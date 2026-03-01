<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Domain\Account\AccountNotFoundException;
use App\Domain\Account\AccountRepository;
use App\Domain\Account\InsufficientFundsException;
use App\Domain\Account\Money;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferRepository;
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
 * On deadlock (rare), we retry up to 3 times with exponential backoff.
 */
final class TransferHandler
{
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MS = 50;

    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly TransferRepository $transferRepository,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(TransferCommand $command): Transfer
    {
        $this->logger->info('Processing transfer', [
            'transfer_id' => $command->transferId,
            'from' => $command->fromAccountId,
            'to' => $command->toAccountId,
            'amount' => $command->amount,
            'currency' => $command->currency,
        ]);

        if ($command->fromAccountId === $command->toAccountId) {
            throw new \InvalidArgumentException('Cannot transfer funds to the same account.');
        }

        $transfer = new Transfer(
            id: $command->transferId,
            fromAccountId: $command->fromAccountId,
            toAccountId: $command->toAccountId,
            amount: $command->amount,
            currency: $command->currency,
            idempotencyKey: $command->idempotencyKey,
            description: $command->description,
        );

        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_RETRIES) {
            try {
                $this->executeTransfer($command, $transfer);

                $this->logger->info('Transfer completed successfully', [
                    'transfer_id' => $transfer->getId(),
                    'attempt' => $attempt + 1,
                ]);

                return $transfer;
            } catch (DeadlockException $e) {
                $lastException = $e;
                ++$attempt;

                $this->logger->warning('Deadlock detected, retrying transfer', [
                    'transfer_id' => $command->transferId,
                    'attempt' => $attempt,
                    'max_retries' => self::MAX_RETRIES,
                ]);

                // Exponential backoff: 50ms, 100ms, 200ms
                usleep(self::RETRY_DELAY_MS * (2 ** ($attempt - 1)) * 1000);
            } catch (Throwable $e) {
                // Non-retryable exceptions bubble up immediately
                $this->logger->error('Transfer failed', [
                    'transfer_id' => $command->transferId,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        throw new TransferException(
            sprintf('Transfer failed after %d attempts due to deadlock.', self::MAX_RETRIES),
            previous: $lastException,
        );
    }

    private function executeTransfer(TransferCommand $command, Transfer $transfer): void
    {
        $this->connection->beginTransaction();

        try {
            $money = Money::of($command->amount, $command->currency);

            // Lock accounts in consistent order (ascending ID) to prevent deadlocks.
            // If thread A locks account-1 then account-2 while thread B locks account-2
            // then account-1, a deadlock occurs. Ordered locking eliminates this.
            [$fromAccount, $toAccount] = $this->accountRepository->findTwoForUpdate(
                $command->fromAccountId,
                $command->toAccountId,
            );

            // Validate currency compatibility
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

            // Atomic: debit source, credit destination
            $fromAccount->debit($money);
            $toAccount->credit($money);

            $transfer->markCompleted();

            // Persist all changes atomically
            $this->accountRepository->save($fromAccount);
            $this->accountRepository->save($toAccount);
            $this->transferRepository->save($transfer);

            $this->connection->commit();
        } catch (InsufficientFundsException | AccountNotFoundException $e) {
            $this->connection->rollBack();
            throw $e;
        } catch (Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }
}
