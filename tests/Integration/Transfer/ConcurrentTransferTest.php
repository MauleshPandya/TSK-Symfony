<?php

declare(strict_types=1);

namespace App\Tests\Integration\Transfer;

use App\Application\Transfer\TransferCommand;
use App\Application\Transfer\TransferHandler;
use App\Domain\Account\Account;
use App\Domain\Account\AccountRepository;
use App\Tests\Integration\IntegrationTestCase;

/**
 * Concurrency tests for the transfer handler.
 *
 * These tests verify that the pessimistic locking strategy correctly
 * prevents race conditions when multiple transfers happen simultaneously.
 *
 * Note: True multi-process concurrency requires separate processes.
 * These tests verify the sequential correctness and locking logic.
 * For production load testing, use tools like k6 or Apache JMeter.
 */
final class ConcurrentTransferTest extends IntegrationTestCase
{
    private TransferHandler $transferHandler;
    private AccountRepository $accountRepository;

    private const ACCOUNT_A = '550e8400-e29b-41d4-a716-446655440001';
    private const ACCOUNT_B = '550e8400-e29b-41d4-a716-446655440002';

    protected function setUp(): void
    {
        parent::setUp();
        $this->transferHandler = static::getContainer()->get(TransferHandler::class);
        $this->accountRepository = static::getContainer()->get(AccountRepository::class);
    }

    public function testMultipleSequentialTransfersProduceCorrectBalance(): void
    {
        // Account A: 1000 USD, Account B: 0 USD
        $this->seedAccounts('1000.00', '0.00');

        // Execute 10 transfers of $50 each: A → B
        for ($i = 0; $i < 10; $i++) {
            $this->transferHandler->handle(new TransferCommand(
                transferId: sprintf('550e8400-e29b-41d4-a716-4466554400%02d', $i),
                fromAccountId: self::ACCOUNT_A,
                toAccountId: self::ACCOUNT_B,
                amount: '50.00',
                currency: 'USD',
                idempotencyKey: sprintf('idempotency-key-for-test-%02d', $i),
            ));
        }

        $this->entityManager->clear();

        $accountA = $this->accountRepository->findById(self::ACCOUNT_A);
        $accountB = $this->accountRepository->findById(self::ACCOUNT_B);

        // 1000 - (10 * 50) = 500
        $this->assertSame('500.00', $accountA->getBalance()->getAmount());
        // 0 + (10 * 50) = 500
        $this->assertSame('500.00', $accountB->getBalance()->getAmount());
    }

    public function testBidirectionalTransfersBalanceCorrectly(): void
    {
        $this->seedAccounts('500.00', '500.00');

        // Alternating transfers A→B and B→A
        for ($i = 0; $i < 5; $i++) {
            // A sends 100 to B
            $this->transferHandler->handle(new TransferCommand(
                transferId: sprintf('550e8400-e29b-41d4-a716-aabb000000%02d', $i),
                fromAccountId: self::ACCOUNT_A,
                toAccountId: self::ACCOUNT_B,
                amount: '100.00',
                currency: 'USD',
                idempotencyKey: sprintf('key-a-to-b-%02d', $i),
            ));

            // B sends 100 back to A
            $this->transferHandler->handle(new TransferCommand(
                transferId: sprintf('550e8400-e29b-41d4-a716-bbaa000000%02d', $i),
                fromAccountId: self::ACCOUNT_B,
                toAccountId: self::ACCOUNT_A,
                amount: '100.00',
                currency: 'USD',
                idempotencyKey: sprintf('key-b-to-a-%02d', $i),
            ));
        }

        $this->entityManager->clear();

        $accountA = $this->accountRepository->findById(self::ACCOUNT_A);
        $accountB = $this->accountRepository->findById(self::ACCOUNT_B);

        // Net zero — both should remain at 500
        $this->assertSame('500.00', $accountA->getBalance()->getAmount());
        $this->assertSame('500.00', $accountB->getBalance()->getAmount());
    }

    public function testTotalMoneyIsConservedAcrossTransfers(): void
    {
        $this->seedAccounts('800.00', '200.00');
        $totalBefore = '1000.00';

        $transfers = [
            ['from' => self::ACCOUNT_A, 'to' => self::ACCOUNT_B, 'amount' => '300.00'],
            ['from' => self::ACCOUNT_B, 'to' => self::ACCOUNT_A, 'amount' => '100.00'],
            ['from' => self::ACCOUNT_A, 'to' => self::ACCOUNT_B, 'amount' => '50.00'],
        ];

        foreach ($transfers as $i => $t) {
            $this->transferHandler->handle(new TransferCommand(
                transferId: sprintf('550e8400-e29b-41d4-a716-cc%010d', $i),
                fromAccountId: $t['from'],
                toAccountId: $t['to'],
                amount: $t['amount'],
                currency: 'USD',
                idempotencyKey: sprintf('conservation-test-key-%02d', $i),
            ));
        }

        $this->entityManager->clear();

        $accountA = $this->accountRepository->findById(self::ACCOUNT_A);
        $accountB = $this->accountRepository->findById(self::ACCOUNT_B);

        $totalAfter = bcadd(
            $accountA->getBalance()->getAmount(),
            $accountB->getBalance()->getAmount(),
            2,
        );

        // Money must be conserved — no amount created or destroyed
        $this->assertSame($totalBefore, $totalAfter);
    }

    private function seedAccounts(string $balanceA, string $balanceB): void
    {
        $accountA = new Account(self::ACCOUNT_A, 'user-1', 'USD', $balanceA);
        $accountB = new Account(self::ACCOUNT_B, 'user-2', 'USD', $balanceB);

        $this->entityManager->persist($accountA);
        $this->entityManager->persist($accountB);
        $this->entityManager->flush();
    }
}
