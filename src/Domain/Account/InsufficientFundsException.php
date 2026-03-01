<?php

declare(strict_types=1);

namespace App\Domain\Account;

use DomainException;

final class InsufficientFundsException extends DomainException
{
    public function __construct(string $message = 'Insufficient funds for this transfer.')
    {
        parent::__construct($message);
    }

    public static function forAccount(string $accountId, Money $available, Money $required): self
    {
        return new self(sprintf(
            'Account %s has insufficient funds. Available: %s, Required: %s.',
            $accountId,
            $available,
            $required,
        ));
    }
}
