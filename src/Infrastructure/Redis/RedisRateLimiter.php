<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use Predis\ClientInterface;

final class RedisRateLimiter
{
    private const WINDOW_SECONDS = 60;

    public function __construct(
        private readonly ClientInterface $redis,
        private readonly int $limitPerMinute,
    ) {
    }

    /**
     * Returns true if request is allowed; false if rate limit exceeded.
     */
    public function allow(string $accountId): bool
    {
        $key     = sprintf('rate:%s', $accountId);
        $now     = microtime(true);
        $cutoff  = $now - self::WINDOW_SECONDS;

        // Remove entries outside the window
        $this->redis->zremrangebyscore($key, 0, $cutoff);

        // Count remaining requests
        $count = (int) $this->redis->zcard($key);

        if ($count >= $this->limitPerMinute) {
            return false;
        }

        // Add current request timestamp
        $this->redis->zadd($key, [$now => (string) $now]);
        $this->redis->expire($key, self::WINDOW_SECONDS);

        return true;
    }
}

