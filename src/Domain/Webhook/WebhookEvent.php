<?php

declare(strict_types=1);

namespace App\Domain\Webhook;

/**
 * All supported webhook event types.
 */
final class WebhookEvent
{
    public const TRANSFER_COMPLETED = 'transfer.completed';
    public const TRANSFER_FAILED    = 'transfer.failed';
    public const ACCOUNT_CREATED    = 'account.created';
    public const ACCOUNT_DEACTIVATED = 'account.deactivated';

    public const ALL = [
        self::TRANSFER_COMPLETED,
        self::TRANSFER_FAILED,
        self::ACCOUNT_CREATED,
        self::ACCOUNT_DEACTIVATED,
    ];

    private function __construct()
    {
    }
}
