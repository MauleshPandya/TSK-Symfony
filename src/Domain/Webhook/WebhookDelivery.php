<?php

declare(strict_types=1);

namespace App\Domain\Webhook;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Immutable delivery log for each webhook attempt.
 * Provides full audit trail: what was sent, when, response code, duration.
 */
#[ORM\Entity]
#[ORM\Table(name: 'webhook_deliveries')]
#[ORM\Index(columns: ['endpoint_id', 'created_at'], name: 'idx_delivery_endpoint')]
#[ORM\Index(columns: ['event_type'], name: 'idx_delivery_event')]
#[ORM\Index(columns: ['success'], name: 'idx_delivery_success')]
class WebhookDelivery
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $endpointId;

    #[ORM\Column(type: 'string', length: 50)]
    private string $eventType;

    #[ORM\Column(type: 'string', length: 36)]
    private string $resourceId;  // transfer ID or account ID

    #[ORM\Column(type: 'text')]
    private string $payload;  // JSON payload that was sent

    #[ORM\Column(type: 'boolean')]
    private bool $success;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $httpStatusCode;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $responseBody;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $errorMessage;

    #[ORM\Column(type: 'integer')]
    private int $attemptNumber;

    #[ORM\Column(type: 'integer')]
    private int $durationMs;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        string $endpointId,
        string $eventType,
        string $resourceId,
        string $payload,
        bool $success,
        int $attemptNumber,
        int $durationMs,
        ?int $httpStatusCode = null,
        ?string $responseBody = null,
        ?string $errorMessage = null,
    ) {
        $this->id             = $id;
        $this->endpointId     = $endpointId;
        $this->eventType      = $eventType;
        $this->resourceId     = $resourceId;
        $this->payload        = $payload;
        $this->success        = $success;
        $this->attemptNumber  = $attemptNumber;
        $this->durationMs     = $durationMs;
        $this->httpStatusCode = $httpStatusCode;
        $this->responseBody   = $responseBody;
        $this->errorMessage   = $errorMessage;
        $this->createdAt      = new DateTimeImmutable();
    }

    public function getId(): string              { return $this->id; }
    public function getEndpointId(): string      { return $this->endpointId; }
    public function getEventType(): string       { return $this->eventType; }
    public function getResourceId(): string      { return $this->resourceId; }
    public function getPayload(): string         { return $this->payload; }
    public function isSuccess(): bool            { return $this->success; }
    public function getHttpStatusCode(): ?int    { return $this->httpStatusCode; }
    public function getResponseBody(): ?string   { return $this->responseBody; }
    public function getErrorMessage(): ?string   { return $this->errorMessage; }
    public function getAttemptNumber(): int      { return $this->attemptNumber; }
    public function getDurationMs(): int         { return $this->durationMs; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
}
