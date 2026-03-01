<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

interface TransferRepository
{
    public function findById(string $id): ?Transfer;

    public function findByIdempotencyKey(string $key): ?Transfer;

    public function save(Transfer $transfer): void;
}
