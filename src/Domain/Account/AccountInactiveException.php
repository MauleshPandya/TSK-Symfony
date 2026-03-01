<?php

declare(strict_types=1);

namespace App\Domain\Account;

use DomainException;

final class AccountInactiveException extends DomainException
{
    public static function forAccount(string $accountId): self
    {
        return new self(sprintf('Account %s is inactive and cannot process transactions.', $accountId));
    }
}
