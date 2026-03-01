<?php

declare(strict_types=1);

namespace App\Domain\Account;

final class AccountNotFoundException extends \RuntimeException
{
    public static function withId(string $accountId): self
    {
        return new self(sprintf('Account %s not found.', $accountId));
    }
}

