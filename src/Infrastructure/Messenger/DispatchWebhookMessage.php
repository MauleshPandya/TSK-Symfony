<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

/**
 * Message dispatched asynchronously when a webhook event occurs.
 *
 * Symfony Messenger serializes this to Redis and processes it in the background,
 * completely decoupled from the HTTP request that triggered it.
 *
 * Retry strategy (configured in messenger.yaml):
 *   - Attempt 1: immediate
 *   - Attempt 2: 1 minute delay
 *   - Attempt 3: 5 minutes delay
 *   - After 3 failures: moved to the dead_letter queue
 */
final readonly class DispatchWebhookMessage
{
    public function __construct(
        public string $endpointId,
        public string $eventType,
        public string $resourceId,
        public array  $payload,
        public int    $attemptNumber = 1,
    ) {
    }
}
