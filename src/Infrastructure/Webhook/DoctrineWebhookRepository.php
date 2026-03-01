<?php

declare(strict_types=1);

namespace App\Infrastructure\Webhook;

use App\Domain\Webhook\WebhookDelivery;
use App\Domain\Webhook\WebhookEndpoint;
use App\Domain\Webhook\WebhookRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineWebhookRepository implements WebhookRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findById(string $id): ?WebhookEndpoint
    {
        return $this->entityManager->find(WebhookEndpoint::class, $id);
    }

    public function findActiveByEvent(string $eventType): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('w')
            ->from(WebhookEndpoint::class, 'w')
            ->where('w.active = true')
            ->getQuery()
            ->getResult();

        // Note: PHP-side filtering because JSON_CONTAINS isn't portable across
        // all Doctrine DBAL versions. For high-volume systems, add a separate
        // webhook_endpoint_events join table for SQL-level filtering.
    }

    public function save(WebhookEndpoint $endpoint): void
    {
        $this->entityManager->persist($endpoint);
        $this->entityManager->flush();
    }

    public function saveDelivery(WebhookDelivery $delivery): void
    {
        $this->entityManager->persist($delivery);
        $this->entityManager->flush();
    }
}
