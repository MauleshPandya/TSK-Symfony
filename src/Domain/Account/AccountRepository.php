<?php

declare(strict_types=1);

namespace App\Domain\Account;

interface AccountRepository
{
    public function findById(string $id): ?Account;

    /**
     * Lock accounts for update in a consistent order (by ID) to prevent deadlocks.
     *
     * @param string $firstId
     * @param string $secondId
     * @return array{Account, Account} Tuple of [firstAccount, secondAccount]
     *
     * @throws AccountNotFoundException
     */
    public function findTwoForUpdate(string $firstId, string $secondId): array;

    public function save(Account $account): void;
}
