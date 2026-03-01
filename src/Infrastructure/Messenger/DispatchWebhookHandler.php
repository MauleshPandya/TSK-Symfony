<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

use App\Domain\Webhook\WebhookDelivery;
use App\Domain\Webhook\WebhookRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * Processes webhook delivery asynchronously.
 *
 * HMAC signing:
 *   Each request includes an `X-Signature-256: sha256=<hmac>` header.
 *   The receiver verifies this using their registered secret:
 *
 *   PHP:  hash_hmac('sha256', $rawBody, $secret)
 *   Node: crypto.createHmac('sha256', secret).update(body).digest('hex')
 *
 * Retries:
 *   RecoverableMessageHandlingException tells Messenger to retry.
 *   Non-recoverable exceptions (e.g. endpoint deactivated) stop retrying.
 */
#[AsMessageHandler]
final class DispatchWebhookHandler
{
    private const TIMEOUT_SECONDS  = 10;
    private const MAX_RESPONSE_LEN = 1024; // bytes to log from response body

    public function __construct(
        private readonly WebhookRepository $webhookRepository,
        private readonly LoggerInterface   $logger,
    ) {
    }

    public function __invoke(DispatchWebhookMessage $message): void
    {
        $endpoint = $this->webhookRepository->findById($message->endpointId);

        if ($endpoint === null || !$endpoint->isActive()) {
            $this->logger->info('Skipping webhook delivery — endpoint not found or inactive', [
                'endpoint_id' => $message->endpointId,
            ]);

            return;
        }

        $payload    = json_encode($message->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp  = time();
        $signature  = $this->sign($payload, $timestamp, $endpoint->getId());

        $startMs = microtime(true);
        [$success, $statusCode, $responseBody, $errorMessage] = $this->deliver(
            $endpoint->getUrl(),
            $payload,
            $signature,
            $timestamp,
            $message->eventType,
        );
        $durationMs = (int) round((microtime(true) - $startMs) * 1000);

        // Write delivery log
        $delivery = new WebhookDelivery(
            id:             Uuid::v4()->toRfc4122(),
            endpointId:     $message->endpointId,
            eventType:      $message->eventType,
            resourceId:     $message->resourceId,
            payload:        $payload,
            success:        $success,
            attemptNumber:  $message->attemptNumber,
            durationMs:     $durationMs,
            httpStatusCode: $statusCode,
            responseBody:   $responseBody,
            errorMessage:   $errorMessage,
        );

        $this->webhookRepository->saveDelivery($delivery);

        if ($success) {
            $endpoint->recordDeliverySuccess();
            $this->webhookRepository->save($endpoint);

            $this->logger->info('Webhook delivered successfully', [
                'endpoint_id' => $message->endpointId,
                'event'       => $message->eventType,
                'status_code' => $statusCode,
                'duration_ms' => $durationMs,
            ]);
        } else {
            $endpoint->recordDeliveryFailure();
            $this->webhookRepository->save($endpoint);

            $this->logger->warning('Webhook delivery failed', [
                'endpoint_id'   => $message->endpointId,
                'event'         => $message->eventType,
                'status_code'   => $statusCode,
                'error'         => $errorMessage,
                'attempt'       => $message->attemptNumber,
                'duration_ms'   => $durationMs,
            ]);

            // Throw recoverable so Messenger retries with backoff
            throw new RecoverableMessageHandlingException(
                sprintf(
                    'Webhook delivery to %s failed (attempt %d): %s',
                    $endpoint->getUrl(),
                    $message->attemptNumber,
                    $errorMessage ?? "HTTP {$statusCode}",
                )
            );
        }
    }

    /**
     * @return array{bool, ?int, ?string, ?string} [success, statusCode, responseBody, errorMessage]
     */
    private function deliver(
        string $url,
        string $payload,
        string $signature,
        int    $timestamp,
        string $eventType,
    ): array {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => implode("\r\n", [
                    'Content-Type: application/json',
                    "X-Signature-256: sha256={$signature}",
                    "X-Webhook-Timestamp: {$timestamp}",
                    "X-Webhook-Event: {$eventType}",
                    'User-Agent: FundTransferAPI/1.0',
                ]),
                'content'         => $payload,
                'timeout'         => self::TIMEOUT_SECONDS,
                'ignore_errors'   => true, // Don't throw on 4xx/5xx
            ],
        ]);

        try {
            $responseBody = @file_get_contents($url, false, $context);
            $meta         = $http_response_header ?? [];
            $statusCode   = $this->extractStatusCode($meta);
            $truncated    = substr((string) $responseBody, 0, self::MAX_RESPONSE_LEN);
            $success      = $statusCode >= 200 && $statusCode < 300;

            return [$success, $statusCode, $truncated ?: null, null];
        } catch (\Throwable $e) {
            return [false, null, null, $e->getMessage()];
        }
    }

    /**
     * HMAC-SHA256 signature over: timestamp.payload
     * Including timestamp prevents replay attacks.
     */
    private function sign(string $payload, int $timestamp, string $endpointId): string
    {
        $signingPayload = "{$timestamp}.{$payload}";

        // In production: load the raw secret from a secure vault.
        // For MVP: derive from endpoint ID (replace with real secret store).
        $secret = hash_hmac('sha256', $endpointId, (string) ($_ENV['APP_SECRET'] ?? 'dev-secret'));

        return hash_hmac('sha256', $signingPayload, $secret);
    }

    /** @param string[] $headers */
    private function extractStatusCode(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (preg_match('/HTTP\/\d\.\d (\d{3})/', $header, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }
}
