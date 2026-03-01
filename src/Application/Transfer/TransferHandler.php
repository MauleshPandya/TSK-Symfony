<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Application\Webhook\WebhookDispatcher;
use App\Domain\Account\AccountNotFoundException;
use App\Domain\Account\AccountRepository;
use App\Domain\Account\InsufficientFundsException;
use App\Domain\Account\Money;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferRepository;
use App\Domain\Webhook\WebhookEvent;
use App\Infrastructure\Audit\TransferAuditLogger;
use App\Infrastructure\Cache\AccountBalanceCache;
use App\Infrastructure\Metrics\MetricsCollector;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles the core transfer use case.
 *
 * Locking strategy:
 *   1. Acquire pessimistic write locks on both accounts inside a DB transaction.
 *   2. Accounts are always locked in ascending ID order to prevent deadlocks.
 *   3. Atomic debit + credit ensures no partial state is ever persisted.
 *
 * Post-transfer:
 *   4. Invalidate Redis balance cache for both accounts.
 *   5. Dispatch async webhook notification via Messenger.
 *
 * On deadlock (rare), we retry up to 3 times with exponential backoff.
 */
final class TransferHandler
{
    private const MAX_RETRIES    = 3;
    private const RETRY_DELAY_MS = 50;

    public function __construct(
        private readonly AccountRepository   $accountRepository,
        private readonly TransferRepository  $transferRepository,
        private readonly Connection          $connection,
        private readonly LoggerInterface     $logger,
        private readonly TransferAuditLogger $auditLogger,
        private readonly MetricsCollector    $metrics,
        private readonly AccountBalanceCache $balanceCache,
        private readonly WebhookDispatcher   $webhookDispatcher,
    ) {
    }

    public function handle(TransferCommand $command): Transfer
    {
        $startTime = microtime(true);

        $this->auditLogger->logAttempt(
            transferId:     $command->transferId,
            fromAccountId:  $command->fromAccountId,
            toAccountId:    $command->toAccountId,
            amount:         $command->amount,
            currency:       $command->currency,
            idempotencyKey: $command->idempotencyKey,
        );

        if ($command->fromAccountId === $command->toAccountId) {
            throw new \InvalidArgumentException('Cannot transfer funds to the same account.');
        }

        $transfer = new Transfer(
            id:             $command->transferId,
            fromAccountId:  $command->fromAccountId,
            toAccountId:    $command->toAccountId,
            amount:         $command->amount,
            currency:       $command->currency,
            idempotencyKey: $command->idempotencyKey,
            description:    $command->description,
        );

        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_RETRIES) {
            try {
                $balances   = $this->executeTransfer($command, $transfer);
                $durationMs = (microtime(true) - $startTime) * 1000;

                // ── Cache invalidation (post-commit) ─────────────────────
                $this->balanceCache->invalidateMany(
                    $command->fromAccountId,
                    $command->toAccountId,
                );

                // ── Metrics ──────────────────────────────────────────────
                $this->metrics->incrementTransfer('completed', $command->currency);
                $this->metrics->recordTransferAmount($command->currency, $command->amount);
                $this->metrics->recordTransferDuration($durationMs);

                // ── Audit log ─────────────────────────────────────────────
                $this->auditLogger->logSuccess(
                    transfer:          $transfer,
                    fromBalanceBefore: $balances['from_before'],
                    fromBalanceAfter:  $balances['from_after'],
                    toBalanceBefore:   $balances['to_before'],
                    toBalanceAfter:    $balances['to_after'],
                    durationMs:        $durationMs,
                );

                // ── Async webhook dispatch (non-blocking) ─────────────────
                $this->webhookDispatcher->dispatch(
                    eventType:  WebhookEvent::TRANSFER_COMPLETED,
                    resourceId: $transfer->getId(),
                    payload:    [
                        'id'              => $transfer->getId(),
                        'from_account_id' => $transfer->getFromAccountId(),
                        'to_account_id'   => $transfer->getToAccountId(),
                        'amount'          => $transfer->getAmount(),
                        'currency'        => $transfer->getCurrency(),
                        'status'          => $transfer->getStatus()->value,
                        'completed_at'    => $transfer->getCompletedAt()?->format(\DateTimeInterface::ATOM),
                    ],
                );

                $this->logger->info('Transfer completed', [
                    'transfer_id' => $transfer->getId(),
                    'attempt'     => $attempt + 1,
                    'duration_ms' => round($durationMs, 2),
                ]);

                return $transfer;
            } catch (DeadlockException $e) {
                $lastException = $e;
                ++$attempt;
                $this->logger->warning('Deadlock detected, retrying', [
                    'transfer_id' => $command->transferId,
                    'attempt'     => $attempt,
                ]);
                usleep(self::RETRY_DELAY_MS * (2 ** ($attempt - 1)) * 1000);
            } catch (Throwable $e) {
                $durationMs = (microtime(true) - $startTime) * 1000;

                $errorCode = match (true) {
                    $e instanceof InsufficientFundsException => 'INSUFFICIENT_FUNDS',
                    $e instanceof AccountNotFoundException    => 'ACCOUNT_NOT_FOUND',
                    $e instanceof \InvalidArgumentException   => 'INVALID_ARGUMENT',
                    default                                   => 'INTERNAL_ERROR',
                };

                $this->metrics->incrementTransfer('failed', $command->currency);

                $this->auditLogger->logFailure(
                    transferId:     $command->transferId,
                    fromAccountId:  $command->fromAccountId,
                    toAccountId:    $command->toAccountId,
                    amount:         $command->amount,
                    currency:       $command->currency,
                    idempotencyKey: $command->idempotencyKey,
                    reason:         $e->getMessage(),
                    errorCode:      $errorCode,
                    durationMs:     $durationMs,
                );

                $this->webhookDispatcher->dispatch(
                    eventType:  WebhookEvent::TRANSFER_FAILED,
                    resourceId: $command->transferId,
                    payload:    [
                        'transfer_id'     => $command->transferId,
                        'from_account_id' => $command->fromAccountId,
                        'to_account_id'   => $command->toAccountId,
                        'amount'          => $command->amount,
                        'currency'        => $command->currency,
                        'error_code'      => $errorCode,
                        'reason'          => $e->getMessage(),
                    ],
                );

                throw $e;
            }
        }

        throw new TransferException(
            sprintf('Transfer failed after %d attempts due to deadlock.', self::MAX_RETRIES),
            previous: $lastException,
        );
    }

    /**
     * @return array{from_before: string, from_after: string, to_before: string, to_after: string}
     */
    private function executeTransfer(TransferCommand $command, Transfer $transfer): array
    {
        $this->connection->beginTransaction();

        try {
            $money = Money::of($command->amount, $command->currency);

            [$fromAccount, $toAccount] = $this->accountRepository->findTwoForUpdate(
                $command->fromAccountId,
                $command->toAccountId,
            );

            if ($fromAccount->getCurrency() !== $command->currency) {
                throw new \InvalidArgumentException(sprintf(
                    'Source account currency is %s but transfer currency is %s.',
                    $fromAccount->getCurrency(),
                    $command->currency,
                ));
            }

            if ($toAccount->getCurrency() !== $command->currency) {
                throw new \InvalidArgumentException(sprintf(
                    'Destination account currency is %s but transfer currency is %s.',
                    $toAccount->getCurrency(),
                    $command->currency,
                ));
            }

            $fromBefore = $fromAccount->getBalance()->getAmount();
            $toBefore   = $toAccount->getBalance()->getAmount();

            $fromAccount->debit($money);
            $toAccount->credit($money);

            $transfer->markCompleted();

            $this->accountRepository->save($fromAccount);
            $this->accountRepository->save($toAccount);
            $this->transferRepository->save($transfer);

            $this->connection->commit();

            return [
                'from_before' => $fromBefore,
                'from_after'  => $fromAccount->getBalance()->getAmount(),
                'to_before'   => $toBefore,
                'to_after'    => $toAccount->getBalance()->getAmount(),
            ];
        } catch (Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }
}
