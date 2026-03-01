<?php

declare(strict_types=1);

namespace App\Application\Webhook;

use App\Domain\Webhook\WebhookRepository;
use App\Infrastructure\Messenger\DispatchWebhookMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Finds all active subscribers for an event and enqueues async delivery messages.
 *
 * Called after business operations complete. The HTTP response to the client
 * is never delayed by webhook delivery — all delivery happens in the background.
 */
final class WebhookDispatcher
{
    public function __construct(
        private readonly WebhookRepository  $webhookRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface    $logger,
    ) {
    }

    /**
     * Dispatch an event to all active subscribers asynchronously.
     */
    public function dispatch(string $eventType, string $resourceId, array $payload): void
    {
        $endpoints = $this->webhookRepository->findActiveByEvent($eventType);

        if (empty($endpoints)) {
            return;
        }

        $envelope = [
            'event'      => $eventType,
            'resource_id' => $resourceId,
            'data'       => $payload,
            'timestamp'  => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        foreach ($endpoints as $endpoint) {
            $this->messageBus->dispatch(new DispatchWebhookMessage(
                endpointId:    $endpoint->getId(),
                eventType:     $eventType,
                resourceId:    $resourceId,
                payload:       $envelope,
                attemptNumber: 1,
            ));

            $this->logger->debug('Webhook message enqueued', [
                'endpoint_id' => $endpoint->getId(),
                'event'       => $eventType,
                'resource_id' => $resourceId,
            ]);
        }
    }
}
