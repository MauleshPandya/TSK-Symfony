<?php

declare(strict_types=1);

namespace App\Domain\Account;

interface AccountRepository
{
    public function save(Account $account): void;

    public function exists(string $accountId): bool;

    /**
     * Load two accounts with pessimistic write locks, always locking
     * in ascending ID order to prevent deadlocks.
     *
     * @return array{0: Account, 1: Account}
     */
    public function findTwoForUpdate(string $fromAccountId, string $toAccountId): array;

    public function find(string $accountId): Account;
}

