<?php

declare(strict_types=1);

namespace App\Domain\Webhook;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * A registered webhook endpoint that will receive transfer event notifications.
 *
 * Each endpoint has:
 *  - A URL to POST to
 *  - A secret for HMAC-SHA256 payload signing
 *  - A bitmask of subscribed event types
 *  - Delivery stats (attempts, failures, last success)
 */
#[ORM\Entity]
#[ORM\Table(name: 'webhook_endpoints')]
#[ORM\Index(columns: ['active'], name: 'idx_webhooks_active')]
class WebhookEndpoint
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 500)]
    private string $url;

    /**
     * HMAC-SHA256 signing secret.
     * Stored hashed — never returned in API responses.
     */
    #[ORM\Column(type: 'string', length: 64)]
    private string $signingSecretHash;

    /** @var string[] */
    #[ORM\Column(type: 'json')]
    private array $events;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $description;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(type: 'integer')]
    private int $totalDeliveries = 0;

    #[ORM\Column(type: 'integer')]
    private int $failedDeliveries = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastDeliveryAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * @param string[] $events
     */
    public function __construct(
        string $id,
        string $url,
        string $rawSecret,
        array $events,
        ?string $description = null,
    ) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException(sprintf('Invalid webhook URL: "%s"', $url));
        }

        if (empty($events)) {
            throw new InvalidArgumentException('At least one event type must be specified.');
        }

        $unknownEvents = array_diff($events, WebhookEvent::ALL);
        if (!empty($unknownEvents)) {
            throw new InvalidArgumentException(
                sprintf('Unknown event types: %s', implode(', ', $unknownEvents))
            );
        }

        $this->id                = $id;
        $this->url               = $url;
        $this->signingSecretHash = hash('sha256', $rawSecret);
        $this->events            = array_values(array_unique($events));
        $this->description       = $description;
        $this->createdAt         = new DateTimeImmutable();
    }

    public function recordDeliverySuccess(): void
    {
        ++$this->totalDeliveries;
        $this->lastDeliveryAt = new DateTimeImmutable();
    }

    public function recordDeliveryFailure(): void
    {
        ++$this->totalDeliveries;
        ++$this->failedDeliveries;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function isSubscribedTo(string $event): bool
    {
        return in_array($event, $this->events, true);
    }

    public function verifySecret(string $rawSecret): bool
    {
        return hash_equals($this->signingSecretHash, hash('sha256', $rawSecret));
    }

    public function getId(): string             { return $this->id; }
    public function getUrl(): string            { return $this->url; }
    public function getEvents(): array          { return $this->events; }
    public function getDescription(): ?string   { return $this->description; }
    public function isActive(): bool            { return $this->active; }
    public function getTotalDeliveries(): int   { return $this->totalDeliveries; }
    public function getFailedDeliveries(): int  { return $this->failedDeliveries; }
    public function getLastDeliveryAt(): ?DateTimeImmutable { return $this->lastDeliveryAt; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
}
