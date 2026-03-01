<?php

declare(strict_types=1);

namespace App\Application\Transfer;

/**
 * Immutable command object representing a transfer request.
 */
final readonly class TransferCommand
{
    public function __construct(
        public string $transferId,
        public string $fromAccountId,
        public string $toAccountId,
        public string $amount,
        public string $currency,
        public string $idempotencyKey,
        public ?string $description = null,
    ) {
    }
}
