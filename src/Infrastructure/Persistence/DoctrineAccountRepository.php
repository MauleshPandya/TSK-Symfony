<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Account\Account;
use App\Domain\Account\AccountNotFoundException;
use App\Domain\Account\AccountRepository;
use Doctrine\DBAL\Connection;

final class DoctrineAccountRepository implements AccountRepository
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function save(Account $account): void
    {
        $sql = '
            INSERT INTO accounts (id, owner_id, currency, balance, active, created_at)
            VALUES (:id, :owner_id, :currency, :balance, :active, :created_at)
            ON DUPLICATE KEY UPDATE
                owner_id = VALUES(owner_id),
                currency = VALUES(currency),
                balance  = VALUES(balance),
                active   = VALUES(active)
        ';

        $this->connection->executeStatement($sql, [
            'id'         => $account->getId(),
            'owner_id'   => $account->getOwnerId(),
            'currency'   => $account->getCurrency(),
            'balance'    => $account->getBalance()->getAmount(),
            'active'     => $account->isActive() ? 1 : 0,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function exists(string $accountId): bool
    {
        $value = $this->connection->fetchOne(
            'SELECT 1 FROM accounts WHERE id = :id',
            ['id' => $accountId],
        );

        return $value !== false;
    }

    public function findTwoForUpdate(string $fromAccountId, string $toAccountId): array
    {
        $ids = [$fromAccountId, $toAccountId];
        sort($ids);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM accounts WHERE id IN (:a, :b) ORDER BY id FOR UPDATE',
            ['a' => $ids[0], 'b' => $ids[1]],
            ['a' => \PDO::PARAM_STR, 'b' => \PDO::PARAM_STR],
        );

        if (count($rows) !== 2) {
            throw AccountNotFoundException::withId($fromAccountId === $toAccountId ? $fromAccountId : $fromAccountId);
        }

        $accountsById = [];
        foreach ($rows as $row) {
            $accountsById[$row['id']] = $this->hydrateAccount($row);
        }

        return [
            $accountsById[$fromAccountId] ?? throw AccountNotFoundException::withId($fromAccountId),
            $accountsById[$toAccountId] ?? throw AccountNotFoundException::withId($toAccountId),
        ];
    }

    public function find(string $accountId): Account
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM accounts WHERE id = :id',
            ['id' => $accountId],
        );

        if ($row === false) {
            throw AccountNotFoundException::withId($accountId);
        }

        return $this->hydrateAccount($row);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrateAccount(array $row): Account
    {
        return new Account(
            id:             (string) $row['id'],
            ownerId:        (string) $row['owner_id'],
            currency:       (string) $row['currency'],
            initialBalance: (string) $row['balance'],
            active:         (bool) $row['active'],
        );
    }
}

