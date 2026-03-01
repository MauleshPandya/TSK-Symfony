<?php

declare(strict_types=1);

namespace App\UI\Api;

use App\Application\Transfer\TransferCommand;
use App\Application\Transfer\TransferHandler;
use App\Domain\Account\AccountInactiveException;
use App\Domain\Account\AccountNotFoundException;
use App\Domain\Account\InsufficientFundsException;
use App\Domain\Transfer\TransferRepository;
use App\Infrastructure\Redis\IdempotencyService;
use App\Infrastructure\Redis\RedisRateLimiter;
use App\Infrastructure\Security\ApiKeyAuthenticator;
use App\UI\Api\Request\TransferRequest;
use App\UI\Api\Response\TransferResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1')]
final class TransferController
{
    public function __construct(
        private readonly TransferHandler $transferHandler,
        private readonly TransferRepository $transferRepository,
        private readonly IdempotencyService $idempotencyService,
        private readonly RedisRateLimiter $rateLimiter,
        private readonly ApiKeyAuthenticator $authenticator,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * POST /api/v1/transfers
     *
     * Initiate a fund transfer between two accounts.
     *
     * Required headers:
     *   - X-API-Key: <api-key>
     *   - Idempotency-Key: <uuid> (prevents duplicate transfers on retry)
     *   - Content-Type: application/json
     */
    #[Route('/transfers', name: 'api_create_transfer', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        // 1. Authentication
        if (!$this->authenticator->authenticate($request)) {
            return $this->errorResponse(
                'Unauthorized. Provide a valid API key in the X-API-Key header.',
                Response::HTTP_UNAUTHORIZED,
                'UNAUTHORIZED',
            );
        }

        // 2. Idempotency key validation
        $idempotencyKey = $request->headers->get('Idempotency-Key');
        if (empty($idempotencyKey)) {
            return $this->errorResponse(
                'Idempotency-Key header is required.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'MISSING_IDEMPOTENCY_KEY',
            );
        }

        if (!Uuid::isValid($idempotencyKey)) {
            return $this->errorResponse(
                'Idempotency-Key must be a valid UUID v4.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'INVALID_IDEMPOTENCY_KEY',
            );
        }

        // 3. Return cached response for duplicate requests
        $cachedResponse = $this->idempotencyService->getCachedResponse($idempotencyKey);
        if ($cachedResponse !== null) {
            return new JsonResponse(
                $cachedResponse,
                Response::HTTP_OK,
                ['X-Idempotent-Replayed' => 'true'],
            );
        }

        // 4. Parse and validate request body
        $body = json_decode($request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->errorResponse(
                'Invalid JSON in request body.',
                Response::HTTP_BAD_REQUEST,
                'INVALID_JSON',
            );
        }

        $transferRequest = new TransferRequest(
            fromAccountId: $body['from_account_id'] ?? '',
            toAccountId: $body['to_account_id'] ?? '',
            amount: (string) ($body['amount'] ?? ''),
            currency: strtoupper((string) ($body['currency'] ?? '')),
            description: $body['description'] ?? null,
        );

        $violations = $this->validator->validate($transferRequest);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = [
                    'field' => $violation->getPropertyPath(),
                    'message' => $violation->getMessage(),
                ];
            }

            return $this->errorResponse(
                'Validation failed.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'VALIDATION_ERROR',
                $errors,
            );
        }

        // 5. Rate limiting (per source account)
        if (!$this->rateLimiter->isAllowed($transferRequest->fromAccountId)) {
            $remaining = $this->rateLimiter->getRemainingRequests($transferRequest->fromAccountId);

            return $this->errorResponse(
                'Rate limit exceeded. Please slow down your requests.',
                Response::HTTP_TOO_MANY_REQUESTS,
                'RATE_LIMIT_EXCEEDED',
                headers: ['X-RateLimit-Remaining' => (string) $remaining, 'Retry-After' => '60'],
            );
        }

        // 6. Acquire idempotency lock (prevent simultaneous duplicate requests)
        if (!$this->idempotencyService->acquireLock($idempotencyKey)) {
            return $this->errorResponse(
                'A request with this Idempotency-Key is already being processed.',
                Response::HTTP_CONFLICT,
                'CONCURRENT_REQUEST',
            );
        }

        try {
            // 7. Execute the transfer
            $command = new TransferCommand(
                transferId: Uuid::v4()->toRfc4122(),
                fromAccountId: $transferRequest->fromAccountId,
                toAccountId: $transferRequest->toAccountId,
                amount: $transferRequest->amount,
                currency: $transferRequest->currency,
                idempotencyKey: $idempotencyKey,
                description: $transferRequest->description,
            );

            $transfer = $this->transferHandler->handle($command);

            $responseData = [
                'data' => (new TransferResponse($transfer))->toArray(),
                'meta' => ['idempotency_key' => $idempotencyKey],
            ];

            // 8. Cache the response for idempotency
            $this->idempotencyService->storeResponse($idempotencyKey, $responseData);

            return new JsonResponse($responseData, Response::HTTP_CREATED);
        } catch (AccountNotFoundException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_NOT_FOUND, 'ACCOUNT_NOT_FOUND');
        } catch (InsufficientFundsException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_PAYMENT_REQUIRED, 'INSUFFICIENT_FUNDS');
        } catch (AccountInactiveException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, 'ACCOUNT_INACTIVE');
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, 'INVALID_ARGUMENT');
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected transfer error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(
                'An unexpected error occurred. Please try again.',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'INTERNAL_ERROR',
            );
        } finally {
            $this->idempotencyService->releaseLock($idempotencyKey);
        }
    }

    /**
     * GET /api/v1/transfers/{id}
     *
     * Retrieve a transfer by ID.
     */
    #[Route('/transfers/{id}', name: 'api_get_transfer', methods: ['GET'])]
    public function show(string $id, Request $request): JsonResponse
    {
        if (!$this->authenticator->authenticate($request)) {
            return $this->errorResponse(
                'Unauthorized.',
                Response::HTTP_UNAUTHORIZED,
                'UNAUTHORIZED',
            );
        }

        if (!Uuid::isValid($id)) {
            return $this->errorResponse(
                'Transfer ID must be a valid UUID.',
                Response::HTTP_BAD_REQUEST,
                'INVALID_ID',
            );
        }

        $transfer = $this->transferRepository->findById($id);

        if ($transfer === null) {
            return $this->errorResponse(
                sprintf('Transfer with ID "%s" was not found.', $id),
                Response::HTTP_NOT_FOUND,
                'TRANSFER_NOT_FOUND',
            );
        }

        return new JsonResponse([
            'data' => (new TransferResponse($transfer))->toArray(),
        ]);
    }

    private function errorResponse(
        string $message,
        int $statusCode,
        string $errorCode,
        array $details = [],
        array $headers = [],
    ): JsonResponse {
        $body = [
            'error' => [
                'code' => $errorCode,
                'message' => $message,
            ],
        ];

        if (!empty($details)) {
            $body['error']['details'] = $details;
        }

        return new JsonResponse($body, $statusCode, $headers);
    }
}
