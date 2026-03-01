<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use Predis\ClientInterface;

final class IdempotencyService
{
    private const RESPONSE_PREFIX = 'idempotency:response:';
    private const LOCK_PREFIX     = 'idempotency:lock:';
    private const LOCK_TTL        = 30; // seconds

    public function __construct(
        private readonly ClientInterface $redis,
        private readonly int $ttl,
    ) {
    }

    public function getCachedResponse(string $key): ?array
    {
        $raw = $this->redis->get(self::RESPONSE_PREFIX . $key);

        if ($raw === null) {
            return null;
        }

        return json_decode($raw, true);
    }

    public function storeResponse(string $key, array $response): void
    {
        $this->redis->setex(
            self::RESPONSE_PREFIX . $key,
            $this->ttl,
            json_encode($response, JSON_THROW_ON_ERROR),
        );
    }

    public function acquireLock(string $key): bool
    {
        $result = $this->redis->set(
            self::LOCK_PREFIX . $key,
            '1',
            'NX',
            'EX',
            self::LOCK_TTL,
        );

        return $result === 'OK';
    }
}

