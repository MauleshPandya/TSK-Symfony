<?php

declare(strict_types=1);

namespace App\Domain\Account;

use DomainException;

final class AccountNotFoundException extends DomainException
{
    public static function withId(string $accountId): self
    {
        return new self(sprintf('Account with ID "%s" was not found.', $accountId));
    }
}
