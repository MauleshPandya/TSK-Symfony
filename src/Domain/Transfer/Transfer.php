<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Immutable transfer ledger record.
 *
 * Once created, a transfer record is never updated — it is an audit trail entry.
 * Status reflects the final outcome of the transfer attempt.
 */
#[ORM\Entity]
#[ORM\Table(name: 'transfers')]
#[ORM\Index(columns: ['from_account_id'], name: 'idx_transfers_from')]
#[ORM\Index(columns: ['to_account_id'], name: 'idx_transfers_to')]
#[ORM\Index(columns: ['idempotency_key'], name: 'idx_transfers_idempotency')]
#[ORM\Index(columns: ['created_at'], name: 'idx_transfers_created')]
class Transfer
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $fromAccountId;

    #[ORM\Column(type: 'string', length: 36)]
    private string $toAccountId;

    #[ORM\Column(type: 'string', length: 20)]
    private string $amount;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(type: 'string', length: 20, enumType: TransferStatus::class)]
    private TransferStatus $status;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $description;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $failureReason;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $completedAt;

    public function __construct(
        string $id,
        string $fromAccountId,
        string $toAccountId,
        string $amount,
        string $currency,
        string $idempotencyKey,
        ?string $description = null,
    ) {
        $this->id = $id;
        $this->fromAccountId = $fromAccountId;
        $this->toAccountId = $toAccountId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->idempotencyKey = $idempotencyKey;
        $this->description = $description;
        $this->status = TransferStatus::PENDING;
        $this->createdAt = new DateTimeImmutable();
        $this->completedAt = null;
        $this->failureReason = null;
    }

    public function markCompleted(): void
    {
        $this->status = TransferStatus::COMPLETED;
        $this->completedAt = new DateTimeImmutable();
    }

    public function markFailed(string $reason): void
    {
        $this->status = TransferStatus::FAILED;
        $this->failureReason = $reason;
        $this->completedAt = new DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getFromAccountId(): string { return $this->fromAccountId; }
    public function getToAccountId(): string { return $this->toAccountId; }
    public function getAmount(): string { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getStatus(): TransferStatus { return $this->status; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getDescription(): ?string { return $this->description; }
    public function getFailureReason(): ?string { return $this->failureReason; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getCompletedAt(): ?DateTimeImmutable { return $this->completedAt; }
}
