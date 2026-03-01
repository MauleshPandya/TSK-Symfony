<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit;

use App\Domain\Transfer\Transfer;
use Psr\Log\LoggerInterface;

final class TransferAuditLogger
{
    public function __construct(
        private readonly LoggerInterface $transferLogger,
    ) {
    }

    public function logAttempt(
        string $transferId,
        string $fromAccountId,
        string $toAccountId,
        string $amount,
        string $currency,
        string $idempotencyKey,
    ): void {
        $this->transferLogger->info('transfer_attempt', [
            'transfer_id'     => $transferId,
            'from_account_id' => $fromAccountId,
            'to_account_id'   => $toAccountId,
            'amount'          => $amount,
            'currency'        => $currency,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    public function logSuccess(
        Transfer $transfer,
        string $fromBalanceBefore,
        string $fromBalanceAfter,
        string $toBalanceBefore,
        string $toBalanceAfter,
        float $durationMs,
    ): void {
        $this->transferLogger->info('transfer_completed', [
            'transfer_id'        => $transfer->getId(),
            'from_account_id'    => $transfer->getFromAccountId(),
            'to_account_id'      => $transfer->getToAccountId(),
            'amount'             => $transfer->getAmount(),
            'currency'           => $transfer->getCurrency(),
            'status'             => $transfer->getStatus()->value,
            'from_before'        => $fromBalanceBefore,
            'from_after'         => $fromBalanceAfter,
            'to_before'          => $toBalanceBefore,
            'to_after'           => $toBalanceAfter,
            'duration_ms'        => $durationMs,
            'idempotency_key'    => $transfer->getIdempotencyKey(),
            'completed_at'       => $transfer->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            'created_at'         => $transfer->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    public function logFailure(
        string $transferId,
        string $fromAccountId,
        string $toAccountId,
        string $amount,
        string $currency,
        string $idempotencyKey,
        string $reason,
        string $errorCode,
        float $durationMs,
    ): void {
        $this->transferLogger->warning('transfer_failed', [
            'transfer_id'     => $transferId,
            'from_account_id' => $fromAccountId,
            'to_account_id'   => $toAccountId,
            'amount'          => $amount,
            'currency'        => $currency,
            'idempotency_key' => $idempotencyKey,
            'reason'          => $reason,
            'error_code'      => $errorCode,
            'duration_ms'     => $durationMs,
        ]);
    }
}

