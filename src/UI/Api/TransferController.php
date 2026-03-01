<?php

declare(strict_types=1);

namespace App\UI\Api;

use App\Application\Transfer\TransferCommand;
use App\Application\Transfer\TransferHandler;
use App\Infrastructure\Redis\IdempotencyService;
use App\Infrastructure\Redis\RedisRateLimiter;
use App\Infrastructure\Security\ApiKeyAuthenticator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/transfers')]
final class TransferController
{
    public function __construct(
        private readonly TransferHandler      $handler,
        private readonly IdempotencyService   $idempotency,
        private readonly RedisRateLimiter     $rateLimiter,
        private readonly ApiKeyAuthenticator  $authenticator,
    ) {
    }

    #[Route('', name: 'api_create_transfer', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (! $this->authenticator->authenticate($request)) {
            return $this->error('Unauthorized.', Response::HTTP_UNAUTHORIZED, 'UNAUTHORIZED');
        }

        $idempotencyKey = $request->headers->get('Idempotency-Key');
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return $this->error('Idempotency-Key header is required.', Response::HTTP_UNPROCESSABLE_ENTITY, 'MISSING_IDEMPOTENCY_KEY');
        }

        if (! Uuid::isValid($idempotencyKey)) {
            return $this->error('Idempotency-Key must be a UUID.', Response::HTTP_UNPROCESSABLE_ENTITY, 'INVALID_IDEMPOTENCY_KEY');
        }

        // Idempotent replay
        if ($cached = $this->idempotency->getCachedResponse($idempotencyKey)) {
            $response = new JsonResponse($cached['body'], $cached['status']);
            $response->headers->set('X-Idempotent-Replayed', 'true');

            return $response;
        }

        // Basic JSON body validation
        $data = json_decode($request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->error('Invalid JSON.', Response::HTTP_BAD_REQUEST, 'INVALID_JSON');
        }

        $fromId     = (string) ($data['from_account_id'] ?? '');
        $toId       = (string) ($data['to_account_id'] ?? '');
        $amount     = (string) ($data['amount'] ?? '');
        $currency   = (string) ($data['currency'] ?? '');
        $desc       = isset($data['description']) ? (string) $data['description'] : null;

        if ($fromId === '' || $toId === '' || $amount === '' || $currency === '') {
            return $this->error('from_account_id, to_account_id, amount, and currency are required.', Response::HTTP_UNPROCESSABLE_ENTITY, 'VALIDATION_ERROR');
        }

        if (! $this->rateLimiter->allow($fromId)) {
            return $this->error('Rate limit exceeded for this account.', Response::HTTP_TOO_MANY_REQUESTS, 'RATE_LIMIT_EXCEEDED');
        }

        $command = new TransferCommand(
            transferId:     Uuid::v4()->toRfc4122(),
            fromAccountId:  $fromId,
            toAccountId:    $toId,
            amount:         $amount,
            currency:       $currency,
            idempotencyKey: $idempotencyKey,
            description:    $desc,
        );

        try {
            $transfer = $this->handler->handle($command);
        } catch (\Throwable $e) {
            // Let the global exception listener map to correct error payload/status.
            throw $e;
        }

        $body = [
            'data' => [
                'id'              => $transfer->getId(),
                'from_account_id' => $transfer->getFromAccountId(),
                'to_account_id'   => $transfer->getToAccountId(),
                'amount'          => $transfer->getAmount(),
                'currency'        => $transfer->getCurrency(),
                'status'          => $transfer->getStatus()->value,
                'description'     => $transfer->getDescription(),
                'failure_reason'  => $transfer->getFailureReason(),
                'created_at'      => $transfer->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'completed_at'    => $transfer->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            ],
            'meta' => [
                'idempotency_key' => $transfer->getIdempotencyKey(),
            ],
        ];

        $response = new JsonResponse($body, Response::HTTP_CREATED);
        $response->headers->set('X-Idempotent-Replayed', 'false');

        // Cache response for idempotent replay
        $this->idempotency->storeResponse($idempotencyKey, [
            'status' => Response::HTTP_CREATED,
            'body'   => $body,
        ]);

        return $response;
    }

    private function error(string $message, int $status, string $code): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}

