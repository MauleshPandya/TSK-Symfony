<?php

declare(strict_types=1);

namespace App\Domain\Account;

use InvalidArgumentException;

/**
 * Immutable Money value object.
 *
 * Uses bcmath strings for precision — never floats.
 * All amounts are stored and compared as strings with fixed decimal scale.
 */
final class Money
{
    private const SCALE = 2;
    private const SUPPORTED_CURRENCIES = ['USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD'];

    private function __construct(
        private readonly string $amount,
        private readonly string $currency,
    ) {
    }

    public static function of(string|int $amount, string $currency): self
    {
        $currency = strtoupper(trim($currency));

        if (!in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            throw new InvalidArgumentException(
                sprintf('Unsupported currency "%s". Supported: %s', $currency, implode(', ', self::SUPPORTED_CURRENCIES))
            );
        }

        $normalised = bcadd((string) $amount, '0', self::SCALE);

        if (bccomp($normalised, '0', self::SCALE) < 0) {
            throw new InvalidArgumentException('Amount cannot be negative.');
        }

        return new self($normalised, $currency);
    }

    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self(bcadd($this->amount, $other->amount, self::SCALE), $this->currency);
    }

    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);

        $result = bcsub($this->amount, $other->amount, self::SCALE);

        if (bccomp($result, '0', self::SCALE) < 0) {
            throw new InsufficientFundsException(
                sprintf('Cannot subtract %s %s from %s %s.', $other->amount, $other->currency, $this->amount, $this->currency)
            );
        }

        return new self($result, $this->currency);
    }

    public function isGreaterThan(Money $other): bool
    {
        $this->assertSameCurrency($other);

        return bccomp($this->amount, $other->amount, self::SCALE) > 0;
    }

    public function isGreaterThanOrEqual(Money $other): bool
    {
        $this->assertSameCurrency($other);

        return bccomp($this->amount, $other->amount, self::SCALE) >= 0;
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) === 0;
    }

    public function equals(Money $other): bool
    {
        return $this->currency === $other->currency
            && bccomp($this->amount, $other->amount, self::SCALE) === 0;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function __toString(): string
    {
        return "{$this->amount} {$this->currency}";
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                sprintf('Currency mismatch: cannot operate on %s and %s.', $this->currency, $other->currency)
            );
        }
    }
}
