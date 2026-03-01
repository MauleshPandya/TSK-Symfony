<?php

declare(strict_types=1);

namespace App\Domain\Account;

final class InsufficientFundsException extends \RuntimeException
{
    public static function forAccount(string $accountId, string $available, string $required, string $currency): self
    {
        return new self(sprintf(
            'Account %s has insufficient funds. Available: %s %s, Required: %s %s.',
            $accountId,
            $available,
            $currency,
            $required,
            $currency,
        ));
    }
}

