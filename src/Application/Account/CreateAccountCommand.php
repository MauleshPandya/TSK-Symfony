<?php

declare(strict_types=1);

namespace App\Application\Account;

final class CreateAccountCommand
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $ownerId,
        public readonly string $currency,
        public readonly string $initialBalance,
    ) {
    }
}

