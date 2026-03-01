<?php

declare(strict_types=1);

namespace App\Domain\Account;

final class AccountAlreadyExistsException extends \RuntimeException
{
    public static function withId(string $accountId): self
    {
        return new self(sprintf('Account %s already exists.', $accountId));
    }
}

