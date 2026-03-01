<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferRepository;
use App\Domain\Transfer\TransferStatus;
use Doctrine\DBAL\Connection;

final class DoctrineTransferRepository implements TransferRepository
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function save(Transfer $transfer): void
    {
        $sql = '
            INSERT INTO transfers (
                id,
                from_account_id,
                to_account_id,
                amount,
                currency,
                status,
                description,
                failure_reason,
                idempotency_key,
                created_at,
                completed_at
            ) VALUES (
                :id,
                :from_account_id,
                :to_account_id,
                :amount,
                :currency,
                :status,
                :description,
                :failure_reason,
                :idempotency_key,
                :created_at,
                :completed_at
            )
            ON DUPLICATE KEY UPDATE
                status         = VALUES(status),
                description    = VALUES(description),
                failure_reason = VALUES(failure_reason),
                completed_at   = VALUES(completed_at)
        ';

        $this->connection->executeStatement($sql, [
            'id'               => $transfer->getId(),
            'from_account_id'  => $transfer->getFromAccountId(),
            'to_account_id'    => $transfer->getToAccountId(),
            'amount'           => $transfer->getAmount(),
            'currency'         => $transfer->getCurrency(),
            'status'           => $transfer->getStatus()->value,
            'description'      => $transfer->getDescription(),
            'failure_reason'   => $transfer->getFailureReason(),
            'idempotency_key'  => $transfer->getIdempotencyKey(),
            'created_at'       => $transfer->getCreatedAt()->format('Y-m-d H:i:s.u'),
            'completed_at'     => $transfer->getCompletedAt()?->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function find(string $id): ?Transfer
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM transfers WHERE id = :id',
            ['id' => $id],
        );

        if ($row === false) {
            return null;
        }

        return $this->hydrateTransfer($row);
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?Transfer
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM transfers WHERE idempotency_key = :key',
            ['key' => $idempotencyKey],
        );

        if ($row === false) {
            return null;
        }

        return $this->hydrateTransfer($row);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrateTransfer(array $row): Transfer
    {
        $transfer = new Transfer(
            id:             (string) $row['id'],
            fromAccountId:  (string) $row['from_account_id'],
            toAccountId:    (string) $row['to_account_id'],
            amount:         (string) $row['amount'],
            currency:       (string) $row['currency'],
            idempotencyKey: (string) $row['idempotency_key'],
            description:    $row['description'] !== null ? (string) $row['description'] : null,
        );

        // Rehydrate status and timestamps
        $status = TransferStatus::from((string) $row['status']);
        if ($status === TransferStatus::COMPLETED) {
            $reflection = new \ReflectionObject($transfer);

            $statusProp = $reflection->getProperty('status');
            $statusProp->setAccessible(true);
            $statusProp->setValue($transfer, $status);

            $completedAtProp = $reflection->getProperty('completedAt');
            $completedAtProp->setAccessible(true);
            $completedAtProp->setValue(
                $transfer,
                $row['completed_at'] !== null
                    ? new \DateTimeImmutable((string) $row['completed_at'])
                    : null,
            );
        } elseif ($status === TransferStatus::FAILED) {
            $reflection = new \ReflectionObject($transfer);

            $statusProp = $reflection->getProperty('status');
            $statusProp->setAccessible(true);
            $statusProp->setValue($transfer, $status);

            $failureProp = $reflection->getProperty('failureReason');
            $failureProp->setAccessible(true);
            $failureProp->setValue(
                $transfer,
                $row['failure_reason'] !== null ? (string) $row['failure_reason'] : null,
            );
        }

        return $transfer;
    }
}

