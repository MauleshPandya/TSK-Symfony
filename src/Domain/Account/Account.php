<?php

declare(strict_types=1);

namespace App\Domain\Account;

final class Account
{
    private Money $balance;

    public function __construct(
        private readonly string $id,
        private readonly string $ownerId,
        private readonly string $currency,
        string $initialBalance = '0.00',
        private bool $active = true,
    ) {
        $this->balance = Money::of($initialBalance, $currency);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOwnerId(): string
    {
        return $this->ownerId;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function getBalance(): Money
    {
        return $this->balance;
    }

    public function debit(Money $amount): void
    {
        $this->assertActive();
        $this->assertCurrency($amount);

        if ($this->balance->isLessThan($amount)) {
            throw InsufficientFundsException::forAccount(
                $this->id,
                $this->balance->getAmount(),
                $amount->getAmount(),
                $this->currency,
            );
        }

        $this->balance = $this->balance->subtract($amount);
    }

    public function credit(Money $amount): void
    {
        $this->assertActive();
        $this->assertCurrency($amount);

        $this->balance = $this->balance->add($amount);
    }

    private function assertCurrency(Money $amount): void
    {
        if ($amount->getCurrency() !== $this->currency) {
            throw new \InvalidArgumentException(sprintf(
                'Currency mismatch for account %s: expected %s, got %s',
                $this->id,
                $this->currency,
                $amount->getCurrency(),
            ));
        }
    }

    private function assertActive(): void
    {
        if (! $this->active) {
            throw AccountInactiveException::forAccount($this->id);
        }
    }
}

