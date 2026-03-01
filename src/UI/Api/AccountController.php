<?php

declare(strict_types=1);

namespace App\UI\Api;

use App\Application\Account\CreateAccountCommand;
use App\Application\Account\CreateAccountHandler;
use App\Domain\Account\AccountRepository;
use App\Infrastructure\Cache\AccountBalanceCache;
use App\Infrastructure\Security\ApiKeyAuthenticator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/accounts')]
final class AccountController
{
    public function __construct(
        private readonly CreateAccountHandler  $createHandler,
        private readonly AccountRepository     $accountRepository,
        private readonly AccountBalanceCache   $balanceCache,
        private readonly ApiKeyAuthenticator   $authenticator,
    ) {
    }

    #[Route('', name: 'api_create_account', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (! $this->authenticator->authenticate($request)) {
            return $this->unauthorized();
        }

        $data = json_decode($request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->error('Invalid JSON.', Response::HTTP_BAD_REQUEST, 'INVALID_JSON');
        }

        $ownerId  = (string) ($data['owner_id'] ?? '');
        $currency = (string) ($data['currency'] ?? '');
        $balance  = (string) ($data['initial_balance'] ?? '0.00');

        if ($ownerId === '' || $currency === '') {
            return $this->error('owner_id and currency are required.', Response::HTTP_UNPROCESSABLE_ENTITY, 'VALIDATION_ERROR');
        }

        $command = new CreateAccountCommand(
            accountId:       Uuid::v4()->toRfc4122(),
            ownerId:         $ownerId,
            currency:        $currency,
            initialBalance:  $balance,
        );

        $account = $this->createHandler->handle($command);

        return new JsonResponse([
            'data' => [
                'id'       => $account->getId(),
                'owner_id' => $account->getOwnerId(),
                'currency' => $account->getCurrency(),
                'balance'  => $account->getBalance()->getAmount(),
            ],
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_get_account', methods: ['GET'])]
    public function show(string $id, Request $request): JsonResponse
    {
        if (! $this->authenticator->authenticate($request)) {
            return $this->unauthorized();
        }

        if (! Uuid::isValid($id)) {
            return $this->error('Account ID must be a valid UUID.', Response::HTTP_BAD_REQUEST, 'INVALID_ID');
        }

        // First check cache
        if ($cached = $this->balanceCache->get($id)) {
            return new JsonResponse([
                'data' => [
                    'id'       => $id,
                    'balance'  => $cached['balance'],
                    'currency' => $cached['currency'],
                ],
                'meta' => ['source' => 'cache'],
            ]);
        }

        $account = $this->accountRepository->find($id);

        $this->balanceCache->set($id, $account->getBalance()->getAmount(), $account->getCurrency());

        return new JsonResponse([
            'data' => [
                'id'       => $account->getId(),
                'owner_id' => $account->getOwnerId(),
                'currency' => $account->getCurrency(),
                'balance'  => $account->getBalance()->getAmount(),
            ],
            'meta' => ['source' => 'db'],
        ]);
    }

    private function unauthorized(): JsonResponse
    {
        return $this->error('Unauthorized.', Response::HTTP_UNAUTHORIZED, 'UNAUTHORIZED');
    }

    private function error(string $message, int $status, string $code): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}

