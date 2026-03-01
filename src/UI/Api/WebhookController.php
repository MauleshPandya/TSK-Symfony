<?php

declare(strict_types=1);

namespace App\UI\Api;

use App\Domain\Webhook\WebhookEndpoint;
use App\Domain\Webhook\WebhookEvent;
use App\Domain\Webhook\WebhookRepository;
use App\Infrastructure\Security\ApiKeyAuthenticator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/webhooks')]
final class WebhookController
{
    public function __construct(
        private readonly WebhookRepository   $webhookRepository,
        private readonly ApiKeyAuthenticator $authenticator,
    ) {
    }

    /**
     * POST /api/v1/webhooks
     *
     * Register a new webhook endpoint.
     * Returns the signing secret ONCE — it is never returned again.
     */
    #[Route('', name: 'api_create_webhook', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->authenticator->authenticate($request)) {
            return $this->unauthorized();
        }

        $body = json_decode($request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->error('Invalid JSON.', Response::HTTP_BAD_REQUEST, 'INVALID_JSON');
        }

        $url         = trim($body['url'] ?? '');
        $events      = $body['events'] ?? [];
        $description = $body['description'] ?? null;

        if (empty($url)) {
            return $this->error('url is required.', Response::HTTP_UNPROCESSABLE_ENTITY, 'VALIDATION_ERROR');
        }

        if (empty($events) || !is_array($events)) {
            return $this->error('events array is required.', Response::HTTP_UNPROCESSABLE_ENTITY, 'VALIDATION_ERROR');
        }

        // Generate a cryptographically secure signing secret
        $rawSecret = bin2hex(random_bytes(32));

        try {
            $endpoint = new WebhookEndpoint(
                id:          Uuid::v4()->toRfc4122(),
                url:         $url,
                rawSecret:   $rawSecret,
                events:      $events,
                description: $description,
            );

            $this->webhookRepository->save($endpoint);

            // Return the raw secret ONCE — we only store the hash
            return new JsonResponse([
                'data' => [
                    'id'          => $endpoint->getId(),
                    'url'         => $endpoint->getUrl(),
                    'events'      => $endpoint->getEvents(),
                    'description' => $endpoint->getDescription(),
                    'active'      => $endpoint->isActive(),
                    'created_at'  => $endpoint->getCreatedAt()->format(\DateTimeInterface::ATOM),
                ],
                'signing_secret' => $rawSecret,
                'notice' => 'Store this signing_secret securely — it will not be shown again.',
            ], Response::HTTP_CREATED);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, 'VALIDATION_ERROR');
        }
    }

    /**
     * GET /api/v1/webhooks/events
     *
     * List all supported event types.
     */
    #[Route('/events', name: 'api_webhook_events', methods: ['GET'])]
    public function events(Request $request): JsonResponse
    {
        if (!$this->authenticator->authenticate($request)) {
            return $this->unauthorized();
        }

        return new JsonResponse([
            'data' => array_map(fn ($event) => ['event' => $event], WebhookEvent::ALL),
        ]);
    }

    /**
     * GET /api/v1/webhooks/{id}
     *
     * Get webhook endpoint details (no secret returned).
     */
    #[Route('/{id}', name: 'api_get_webhook', methods: ['GET'])]
    public function show(string $id, Request $request): JsonResponse
    {
        if (!$this->authenticator->authenticate($request)) {
            return $this->unauthorized();
        }

        $endpoint = $this->webhookRepository->findById($id);
        if ($endpoint === null) {
            return $this->error('Webhook endpoint not found.', Response::HTTP_NOT_FOUND, 'NOT_FOUND');
        }

        return new JsonResponse(['data' => $this->format($endpoint)]);
    }

    /**
     * DELETE /api/v1/webhooks/{id}
     *
     * Deactivate a webhook endpoint.
     */
    #[Route('/{id}', name: 'api_delete_webhook', methods: ['DELETE'])]
    public function delete(string $id, Request $request): JsonResponse
    {
        if (!$this->authenticator->authenticate($request)) {
            return $this->unauthorized();
        }

        $endpoint = $this->webhookRepository->findById($id);
        if ($endpoint === null) {
            return $this->error('Webhook endpoint not found.', Response::HTTP_NOT_FOUND, 'NOT_FOUND');
        }

        $endpoint->deactivate();
        $this->webhookRepository->save($endpoint);

        return new JsonResponse(['data' => $this->format($endpoint)]);
    }

    private function format(WebhookEndpoint $e): array
    {
        return [
            'id'               => $e->getId(),
            'url'              => $e->getUrl(),
            'events'           => $e->getEvents(),
            'description'      => $e->getDescription(),
            'active'           => $e->isActive(),
            'total_deliveries' => $e->getTotalDeliveries(),
            'failed_deliveries' => $e->getFailedDeliveries(),
            'last_delivery_at' => $e->getLastDeliveryAt()?->format(\DateTimeInterface::ATOM),
            'created_at'       => $e->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function unauthorized(): JsonResponse
    {
        return $this->error('Unauthorized.', Response::HTTP_UNAUTHORIZED, 'UNAUTHORIZED');
    }

    private function error(string $message, int $status, string $code): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
