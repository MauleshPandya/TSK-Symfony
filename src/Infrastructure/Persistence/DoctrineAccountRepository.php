<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Account\Account;
use App\Domain\Account\AccountNotFoundException;
use App\Domain\Account\AccountRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineAccountRepository implements AccountRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findById(string $id): ?Account
    {
        return $this->entityManager->find(Account::class, $id);
    }

    /**
     * Lock two accounts using pessimistic write locks in a consistent order
     * (ascending ID) to prevent deadlocks under concurrent load.
     *
     * This is the critical piece of concurrency safety:
     *   - Both rows are locked before any modification occurs.
     *   - Lock order is deterministic regardless of which account is "from" or "to".
     *   - MySQL/InnoDB will block concurrent transactions trying to lock the same rows.
     */
    public function findTwoForUpdate(string $firstId, string $secondId): array
    {
        // Determine lock acquisition order — always ascending by ID string
        $ids = [$firstId, $secondId];
        sort($ids);
        [$lowerId, $higherId] = $ids;

        $lowerAccount = $this->entityManager->find(
            Account::class,
            $lowerId,
            LockMode::PESSIMISTIC_WRITE,
        );

        if ($lowerAccount === null) {
            throw AccountNotFoundException::withId($lowerId);
        }

        $higherAccount = $this->entityManager->find(
            Account::class,
            $higherId,
            LockMode::PESSIMISTIC_WRITE,
        );

        if ($higherAccount === null) {
            throw AccountNotFoundException::withId($higherId);
        }

        // Return in original caller order: [firstId, secondId]
        if ($firstId === $lowerId) {
            return [$lowerAccount, $higherAccount];
        }

        return [$higherAccount, $lowerAccount];
    }

    public function save(Account $account): void
    {
        $this->entityManager->persist($account);
        // Flush happens at transaction commit in TransferHandler
    }
}
