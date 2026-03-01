<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Account\InsufficientFundsException;
use App\Domain\Account\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testCreatesMoneyFromString(): void
    {
        $money = Money::of('100.00', 'USD');

        $this->assertSame('100.00', $money->getAmount());
        $this->assertSame('USD', $money->getCurrency());
    }

    public function testCreatesMoneyFromInt(): void
    {
        $money = Money::of(100, 'USD');

        $this->assertSame('100.00', $money->getAmount());
    }

    public function testNormalisesDecimalScale(): void
    {
        $money = Money::of('100', 'USD');

        $this->assertSame('100.00', $money->getAmount());
    }

    public function testAddsMoney(): void
    {
        $a = Money::of('100.00', 'USD');
        $b = Money::of('50.50', 'USD');

        $result = $a->add($b);

        $this->assertSame('150.50', $result->getAmount());
    }

    public function testSubtractsMoney(): void
    {
        $a = Money::of('100.00', 'USD');
        $b = Money::of('30.25', 'USD');

        $result = $a->subtract($b);

        $this->assertSame('69.75', $result->getAmount());
    }

    public function testSubtractThrowsOnInsufficientFunds(): void
    {
        $a = Money::of('50.00', 'USD');
        $b = Money::of('100.00', 'USD');

        $this->expectException(InsufficientFundsException::class);

        $a->subtract($b);
    }

    public function testAddThrowsOnCurrencyMismatch(): void
    {
        $usd = Money::of('100.00', 'USD');
        $eur = Money::of('100.00', 'EUR');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency mismatch');

        $usd->add($eur);
    }

    public function testThrowsOnNegativeAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount cannot be negative');

        Money::of('-1.00', 'USD');
    }

    public function testThrowsOnUnsupportedCurrency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported currency');

        Money::of('100.00', 'XYZ');
    }

    public function testIsZero(): void
    {
        $this->assertTrue(Money::of('0.00', 'USD')->isZero());
        $this->assertFalse(Money::of('0.01', 'USD')->isZero());
    }

    public function testEquals(): void
    {
        $a = Money::of('100.00', 'USD');
        $b = Money::of('100.00', 'USD');
        $c = Money::of('100.01', 'USD');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testIsGreaterThan(): void
    {
        $a = Money::of('100.00', 'USD');
        $b = Money::of('99.99', 'USD');

        $this->assertTrue($a->isGreaterThan($b));
        $this->assertFalse($b->isGreaterThan($a));
    }

    /**
     * This test demonstrates why we use bcmath over floats.
     * 0.1 + 0.2 = 0.30000000000000004 with native float arithmetic.
     */
    public function testFloatingPointPrecision(): void
    {
        $a = Money::of('0.1', 'USD');
        $b = Money::of('0.2', 'USD');

        $result = $a->add($b);

        $this->assertSame('0.30', $result->getAmount());
        $this->assertNotSame((string)(0.1 + 0.2), $result->getAmount()); // This would fail with floats
    }
}
