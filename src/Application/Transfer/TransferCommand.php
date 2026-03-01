<?php

declare(strict_types=1);

namespace App\Application\Transfer;

final class TransferCommand
{
    public function __construct(
        public readonly string $transferId,
        public readonly string $fromAccountId,
        public readonly string $toAccountId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $idempotencyKey,
        public readonly ?string $description = null,
    ) {
    }
}

