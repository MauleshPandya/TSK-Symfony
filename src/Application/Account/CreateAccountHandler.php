<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Domain\Account\Account;
use App\Domain\Account\AccountAlreadyExistsException;
use App\Domain\Account\AccountRepository;
use App\Infrastructure\Metrics\MetricsCollector;
use Psr\Log\LoggerInterface;

final class CreateAccountHandler
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly LoggerInterface   $logger,
        private readonly MetricsCollector  $metrics,
    ) {
    }

    public function handle(CreateAccountCommand $command): Account
    {
        if ($this->accountRepository->exists($command->accountId)) {
            throw AccountAlreadyExistsException::withId($command->accountId);
        }

        $account = new Account(
            id:             $command->accountId,
            ownerId:        $command->ownerId,
            currency:       $command->currency,
            initialBalance: $command->initialBalance,
        );

        $this->accountRepository->save($account);

        $this->metrics->incrementAccountCreated($command->currency);

        $this->logger->info('Account created', [
            'account_id' => $account->getId(),
            'owner_id'   => $account->getOwnerId(),
            'currency'   => $account->getCurrency(),
        ]);

        return $account;
    }
}
