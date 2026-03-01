<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\Transfer\TransferCommand;
use App\Application\Transfer\TransferHandler;
use App\Domain\Account\Account;
use App\Domain\Account\AccountNotFoundException;
use App\Domain\Account\AccountRepository;
use App\Domain\Account\InsufficientFundsException;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferRepository;
use App\Domain\Transfer\TransferStatus;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TransferHandlerTest extends TestCase
{
    private AccountRepository&MockObject $accountRepository;
    private TransferRepository&MockObject $transferRepository;
    private Connection&MockObject $connection;
    private TransferHandler $handler;

    private const FROM_ID = '550e8400-e29b-41d4-a716-446655440001';
    private const TO_ID = '550e8400-e29b-41d4-a716-446655440002';

    protected function setUp(): void
    {
        $this->accountRepository = $this->createMock(AccountRepository::class);
        $this->transferRepository = $this->createMock(TransferRepository::class);
        $this->connection = $this->createMock(Connection::class);

        $this->handler = new TransferHandler(
            $this->accountRepository,
            $this->transferRepository,
            $this->connection,
            new NullLogger(),
        );
    }

    public function testSuccessfulTransfer(): void
    {
        $fromAccount = new Account(self::FROM_ID, 'user-1', 'USD', '500.00');
        $toAccount = new Account(self::TO_ID, 'user-2', 'USD', '100.00');

        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('commit');
        $this->connection->expects($this->never())->method('rollBack');

        $this->accountRepository
            ->expects($this->once())
            ->method('findTwoForUpdate')
            ->with(self::FROM_ID, self::TO_ID)
            ->willReturn([$fromAccount, $toAccount]);

        $this->accountRepository->expects($this->exactly(2))->method('save');
        $this->transferRepository->expects($this->once())->method('save');

        $command = $this->makeCommand('100.00');

        $transfer = $this->handler->handle($command);

        $this->assertInstanceOf(Transfer::class, $transfer);
        $this->assertSame(TransferStatus::COMPLETED, $transfer->getStatus());
        $this->assertSame('400.00', $fromAccount->getBalance()->getAmount());
        $this->assertSame('200.00', $toAccount->getBalance()->getAmount());
    }

    public function testThrowsWhenFromAccountNotFound(): void
    {
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('rollBack');

        $this->accountRepository
            ->method('findTwoForUpdate')
            ->willThrowException(AccountNotFoundException::withId(self::FROM_ID));

        $this->expectException(AccountNotFoundException::class);

        $this->handler->handle($this->makeCommand('100.00'));
    }

    public function testThrowsInsufficientFunds(): void
    {
        $fromAccount = new Account(self::FROM_ID, 'user-1', 'USD', '50.00');
        $toAccount = new Account(self::TO_ID, 'user-2', 'USD', '100.00');

        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('rollBack');

        $this->accountRepository
            ->method('findTwoForUpdate')
            ->willReturn([$fromAccount, $toAccount]);

        $this->expectException(InsufficientFundsException::class);

        $this->handler->handle($this->makeCommand('100.00'));
    }

    public function testThrowsOnSameAccountTransfer(): void
    {
        $command = new TransferCommand(
            transferId: '550e8400-e29b-41d4-a716-446655440099',
            fromAccountId: self::FROM_ID,
            toAccountId: self::FROM_ID, // Same as fromAccountId
            amount: '100.00',
            currency: 'USD',
            idempotencyKey: '550e8400-e29b-41d4-a716-446655440099',
        );

        $this->connection->expects($this->never())->method('beginTransaction');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same account');

        $this->handler->handle($command);
    }

    public function testThrowsOnCurrencyMismatch(): void
    {
        $fromAccount = new Account(self::FROM_ID, 'user-1', 'EUR', '500.00');
        $toAccount = new Account(self::TO_ID, 'user-2', 'USD', '100.00');

        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('rollBack');

        $this->accountRepository
            ->method('findTwoForUpdate')
            ->willReturn([$fromAccount, $toAccount]);

        $this->expectException(\InvalidArgumentException::class);

        $this->handler->handle($this->makeCommand('100.00', 'USD'));
    }

    private function makeCommand(string $amount, string $currency = 'USD'): TransferCommand
    {
        return new TransferCommand(
            transferId: '550e8400-e29b-41d4-a716-446655440099',
            fromAccountId: self::FROM_ID,
            toAccountId: self::TO_ID,
            amount: $amount,
            currency: $currency,
            idempotencyKey: '550e8400-e29b-41d4-a716-44665544' . rand(1000, 9999),
        );
    }
}
