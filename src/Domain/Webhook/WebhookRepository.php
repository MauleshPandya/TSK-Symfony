<?php

declare(strict_types=1);

namespace App\Domain\Webhook;

interface WebhookRepository
{
    public function findById(string $id): ?WebhookEndpoint;

    /** @return WebhookEndpoint[] */
    public function findActiveByEvent(string $eventType): array;

    public function save(WebhookEndpoint $endpoint): void;

    public function saveDelivery(WebhookDelivery $delivery): void;
}
