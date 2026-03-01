<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

interface TransferRepository
{
    public function save(Transfer $transfer): void;

    public function find(string $id): ?Transfer;

    public function findByIdempotencyKey(string $idempotencyKey): ?Transfer;
}

