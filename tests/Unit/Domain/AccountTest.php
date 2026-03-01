<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Account\Account;
use App\Domain\Account\AccountInactiveException;
use App\Domain\Account\InsufficientFundsException;
use App\Domain\Account\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    private const ACCOUNT_ID = '550e8400-e29b-41d4-a716-446655440001';
    private const OWNER_ID = 'user-123';

    public function testCreatesAccountWithZeroBalance(): void
    {
        $account = new Account(self::ACCOUNT_ID, self::OWNER_ID, 'USD');

        $this->assertSame(self::ACCOUNT_ID, $account->getId());
        $this->assertSame('0.00', $account->getBalance()->getAmount());
        $this->assertTrue($account->isActive());
    }

    public function testCreatesAccountWithInitialBalance(): void
    {
        $account = new Account(self::ACCOUNT_ID, self::OWNER_ID, 'USD', '500.00');

        $this->assertSame('500.00', $account->getBalance()->getAmount());
    }

    public function testDebitReducesBalance(): void
    {
        $account = new Account(self::ACCOUNT_ID, self::OWNER_ID, 'USD', '1000.00');

        $account->debit(Money::of('250.00', 'USD'));

        $this->assertSame('750.00', $account->getBalance()->getAmount());
    }

    public function testCreditIncreasesBalance(): void
    {
        $account = new Account(self::ACCOUNT_ID, self::OWNER_ID, 'USD', '100.00');

        $account->credit(Money::of('400.00', 'USD'));

        $this->assertSame('500.00', $account->getBalance()->getAmount());
    }

    public function testDebitThrowsWhenInsufficientFunds(): void
    {
        $account = new Account(self::ACCOUNT_ID, self::OWNER_ID, 'USD', '50.00');

        $this->expectException(InsufficientFundsException::class);
        $this->expectExceptionMessage(self::ACCOUNT_ID);

        $account->debit(Money::of('100.00', 'USD'));
    }

    public function testDebitExactBalanceSucceeds(): void
    {
        $account = new Account(self::ACCOUNT_ID, self::OWNER_ID, 'USD', '100.00');

        $account->debit(Money::of('100.00', 'USD'));

        $this->assertSame('0.00', $account->getBalance()->getAmount());
    }

    public function testDebitThrowsOnInactiveAccount(): void
    {
        $account = new Account(self::ACCOUNT_ID, self::OWNER_ID, 'USD', '1000.00');
        $account->deactivate();

        $this->expectException(AccountInactiveException::class);

        $account->debit(Money::of('100.00', 'USD'));
    }

    public function testCreditThrowsOnInactiveAccount(): void
    {
        $account = new Account(self::ACCOUNT_ID, self::OWNER_ID, 'USD', '0.00');
        $account->deactivate();

        $this->expectException(AccountInactiveException::class);

        $account->credit(Money::of('100.00', 'USD'));
    }

    public function testDebitThrowsOnCurrencyMismatch(): void
    {
        $account = new Account(self::ACCOUNT_ID, self::OWNER_ID, 'USD', '1000.00');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('currency');

        $account->debit(Money::of('100.00', 'EUR'));
    }

    public function testThrowsOnEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Account('', self::OWNER_ID, 'USD');
    }

    public function testDeactivate(): void
    {
        $account = new Account(self::ACCOUNT_ID, self::OWNER_ID, 'USD');
        $this->assertTrue($account->isActive());

        $account->deactivate();

        $this->assertFalse($account->isActive());
    }
}
