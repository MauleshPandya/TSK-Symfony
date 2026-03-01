<?php

declare(strict_types=1);

namespace App\Domain\Account;

/**
 * Immutable money value object backed by bcmath strings.
 *
 * All amounts are stored as decimal strings with 2 fraction digits to avoid
 * floating‑point precision issues.
 */
final class Money
{
    private const SCALE = 2;

    public function __construct(
        private readonly string $amount,
        private readonly string $currency,
    ) {
        if ($this->amount === '') {
            throw new \InvalidArgumentException('Amount cannot be empty.');
        }

        if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $this->amount)) {
            throw new \InvalidArgumentException(sprintf('Invalid amount format: %s', $this->amount));
        }

        if ($this->currency === '') {
            throw new \InvalidArgumentException('Currency cannot be empty.');
        }
    }

    public static function of(string $amount, string $currency): self
    {
        // Normalise to fixed scale
        $normalized = number_format((float) $amount, self::SCALE, '.', '');

        return new self($normalized, $currency);
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        $sum = bcadd($this->amount, $other->amount, self::SCALE);

        return new self($sum, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        $diff = bcsub($this->amount, $other->amount, self::SCALE);

        return new self($diff, $this->currency);
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) < 0;
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return bccomp($this->amount, $other->amount, self::SCALE) < 0;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException(sprintf(
                'Currency mismatch: %s vs %s',
                $this->currency,
                $other->currency,
            ));
        }
    }
}

