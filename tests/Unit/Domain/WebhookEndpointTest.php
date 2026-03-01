<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Webhook\WebhookEndpoint;
use App\Domain\Webhook\WebhookEvent;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WebhookEndpointTest extends TestCase
{
    private const ID  = '550e8400-e29b-41d4-a716-446655440001';
    private const URL = 'https://example.com/webhooks';

    public function testCreatesEndpointSuccessfully(): void
    {
        $endpoint = new WebhookEndpoint(
            id:        self::ID,
            url:       self::URL,
            rawSecret: 'my-secret',
            events:    [WebhookEvent::TRANSFER_COMPLETED],
        );

        $this->assertSame(self::ID, $endpoint->getId());
        $this->assertSame(self::URL, $endpoint->getUrl());
        $this->assertTrue($endpoint->isActive());
        $this->assertTrue($endpoint->isSubscribedTo(WebhookEvent::TRANSFER_COMPLETED));
        $this->assertFalse($endpoint->isSubscribedTo(WebhookEvent::TRANSFER_FAILED));
    }

    public function testThrowsOnInvalidUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid webhook URL');

        new WebhookEndpoint(self::ID, 'not-a-url', 'secret', [WebhookEvent::TRANSFER_COMPLETED]);
    }

    public function testThrowsOnEmptyEvents(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one event type');

        new WebhookEndpoint(self::ID, self::URL, 'secret', []);
    }

    public function testThrowsOnUnknownEvent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown event types');

        new WebhookEndpoint(self::ID, self::URL, 'secret', ['bogus.event']);
    }

    public function testSecretIsNotStoredInPlaintext(): void
    {
        $rawSecret = 'super-secret-value';
        $endpoint  = new WebhookEndpoint(self::ID, self::URL, $rawSecret, [WebhookEvent::TRANSFER_COMPLETED]);

        // Verify secret works
        $this->assertTrue($endpoint->verifySecret($rawSecret));
        $this->assertFalse($endpoint->verifySecret('wrong-secret'));
    }

    public function testDeactivation(): void
    {
        $endpoint = new WebhookEndpoint(self::ID, self::URL, 'secret', [WebhookEvent::TRANSFER_COMPLETED]);
        $this->assertTrue($endpoint->isActive());

        $endpoint->deactivate();
        $this->assertFalse($endpoint->isActive());
    }

    public function testDeliveryStatsTracking(): void
    {
        $endpoint = new WebhookEndpoint(self::ID, self::URL, 'secret', [WebhookEvent::TRANSFER_COMPLETED]);

        $this->assertSame(0, $endpoint->getTotalDeliveries());
        $this->assertSame(0, $endpoint->getFailedDeliveries());
        $this->assertNull($endpoint->getLastDeliveryAt());

        $endpoint->recordDeliverySuccess();
        $this->assertSame(1, $endpoint->getTotalDeliveries());
        $this->assertSame(0, $endpoint->getFailedDeliveries());
        $this->assertNotNull($endpoint->getLastDeliveryAt());

        $endpoint->recordDeliveryFailure();
        $this->assertSame(2, $endpoint->getTotalDeliveries());
        $this->assertSame(1, $endpoint->getFailedDeliveries());
    }

    public function testMultipleEventsSubscription(): void
    {
        $endpoint = new WebhookEndpoint(
            id:        self::ID,
            url:       self::URL,
            rawSecret: 'secret',
            events:    [WebhookEvent::TRANSFER_COMPLETED, WebhookEvent::TRANSFER_FAILED],
        );

        $this->assertTrue($endpoint->isSubscribedTo(WebhookEvent::TRANSFER_COMPLETED));
        $this->assertTrue($endpoint->isSubscribedTo(WebhookEvent::TRANSFER_FAILED));
        $this->assertFalse($endpoint->isSubscribedTo(WebhookEvent::ACCOUNT_CREATED));
    }
}
