<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use Predis\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Sliding window rate limiter using Redis sorted sets.
 *
 * Why sliding window over fixed window:
 *   Fixed window allows bursting at window boundaries (59 req at 0:59, 60 req at 1:00).
 *   Sliding window smooths this — the limit applies to any 60-second rolling window.
 *
 * Algorithm:
 *   1. ZADD current_timestamp to sorted set (key = account_id)
 *   2. ZREMRANGEBYSCORE removes entries older than window
 *   3. ZCARD counts current requests in window
 *   4. Reject if count exceeds limit
 *   5. EXPIRE key to auto-cleanup
 */
final class RedisRateLimiter
{
    private const WINDOW_SECONDS = 60;
    private const PREFIX = 'rate_limit:';

    public function __construct(
        private readonly ClientInterface $redis,
        private readonly int $limitPerMinute,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Check if the account is within rate limits.
     *
     * @return bool true if allowed, false if rate limit exceeded
     */
    public function isAllowed(string $accountId): bool
    {
        $key = self::PREFIX . $accountId;
        $now = microtime(true);
        $windowStart = $now - self::WINDOW_SECONDS;

        try {
            $this->redis->pipeline(function ($pipe) use ($key, $now, $windowStart) {
                // Add current request timestamp
                $pipe->zadd($key, [$now => $now]);
                // Remove entries outside the sliding window
                $pipe->zremrangebyscore($key, '-inf', (string) $windowStart);
                // Set key expiry
                $pipe->expire($key, self::WINDOW_SECONDS + 1);
            });

            $count = (int) $this->redis->zcard($key);

            if ($count > $this->limitPerMinute) {
                $this->logger->warning('Rate limit exceeded', [
                    'account_id' => $accountId,
                    'count' => $count,
                    'limit' => $this->limitPerMinute,
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Rate limiter error — failing open', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            // Fail open: don't block transfers if Redis is unavailable
            return true;
        }
    }

    /**
     * Get remaining requests in the current window.
     */
    public function getRemainingRequests(string $accountId): int
    {
        $key = self::PREFIX . $accountId;
        $windowStart = microtime(true) - self::WINDOW_SECONDS;

        try {
            $this->redis->zremrangebyscore($key, '-inf', (string) $windowStart);
            $count = (int) $this->redis->zcard($key);

            return max(0, $this->limitPerMinute - $count);
        } catch (\Throwable) {
            return $this->limitPerMinute;
        }
    }
}
