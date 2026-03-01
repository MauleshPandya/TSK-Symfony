<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

final class Transfer
{
    private TransferStatus $status;
    private ?string $failureReason = null;
    private \DateTimeImmutable $createdAt;
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(
        private readonly string $id,
        private readonly string $fromAccountId,
        private readonly string $toAccountId,
        private readonly string $amount,
        private readonly string $currency,
        private readonly string $idempotencyKey,
        private readonly ?string $description = null,
    ) {
        $this->status    = TransferStatus::PENDING;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getFromAccountId(): string
    {
        return $this->fromAccountId;
    }

    public function getToAccountId(): string
    {
        return $this->toAccountId;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getStatus(): TransferStatus
    {
        return $this->status;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function markCompleted(): void
    {
        $this->status      = TransferStatus::COMPLETED;
        $this->completedAt = new \DateTimeImmutable();
        $this->failureReason = null;
    }

    public function markFailed(string $reason): void
    {
        $this->status        = TransferStatus::FAILED;
        $this->failureReason = $reason;
    }
}

