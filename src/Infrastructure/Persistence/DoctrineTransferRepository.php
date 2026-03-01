<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineTransferRepository implements TransferRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findById(string $id): ?Transfer
    {
        return $this->entityManager->find(Transfer::class, $id);
    }

    public function findByIdempotencyKey(string $key): ?Transfer
    {
        return $this->entityManager->getRepository(Transfer::class)
            ->findOneBy(['idempotencyKey' => $key]);
    }

    public function save(Transfer $transfer): void
    {
        $this->entityManager->persist($transfer);
        $this->entityManager->flush();
    }
}
