<?php

declare(strict_types=1);

namespace App\UI\Api\Response;

use App\Domain\Transfer\Transfer;

final class TransferResponse
{
    public readonly string $id;
    public readonly string $fromAccountId;
    public readonly string $toAccountId;
    public readonly string $amount;
    public readonly string $currency;
    public readonly string $status;
    public readonly ?string $description;
    public readonly ?string $failureReason;
    public readonly string $createdAt;
    public readonly ?string $completedAt;

    public function __construct(Transfer $transfer)
    {
        $this->id = $transfer->getId();
        $this->fromAccountId = $transfer->getFromAccountId();
        $this->toAccountId = $transfer->getToAccountId();
        $this->amount = $transfer->getAmount();
        $this->currency = $transfer->getCurrency();
        $this->status = $transfer->getStatus()->value;
        $this->description = $transfer->getDescription();
        $this->failureReason = $transfer->getFailureReason();
        $this->createdAt = $transfer->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $this->completedAt = $transfer->getCompletedAt()?->format(\DateTimeInterface::ATOM);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'from_account_id' => $this->fromAccountId,
            'to_account_id' => $this->toAccountId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'description' => $this->description,
            'failure_reason' => $this->failureReason,
            'created_at' => $this->createdAt,
            'completed_at' => $this->completedAt,
        ];
    }
}
