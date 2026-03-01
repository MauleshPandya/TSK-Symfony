<?php

declare(strict_types=1);

namespace App\Domain\Account;

final class AccountInactiveException extends \RuntimeException
{
    public static function forAccount(string $accountId): self
    {
        return new self(sprintf('Account %s is inactive.', $accountId));
    }
}

