<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use Predis\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Idempotency layer backed by Redis.
 *
 * Guarantees that duplicate API requests (same Idempotency-Key) return
 * the identical response without re-executing the transfer.
 *
 * Flow:
 *   1. Check Redis for existing response → return it if found (HTTP 200 + X-Idempotent-Replayed: true)
 *   2. Execute the transfer
 *   3. Store the response in Redis with TTL
 *
 * Uses SET NX (set if not exists) as a distributed lock to prevent
 * two concurrent identical requests from both executing.
 */
final class IdempotencyService
{
    private const PREFIX = 'idempotency:';
    private const LOCK_PREFIX = 'idempotency_lock:';
    private const LOCK_TTL = 30; // seconds

    public function __construct(
        private readonly ClientInterface $redis,
        private readonly int $ttl,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Returns the cached response if idempotency key was already processed.
     */
    public function getCachedResponse(string $key): ?array
    {
        $redisKey = self::PREFIX . $key;

        try {
            $cached = $this->redis->get($redisKey);

            if ($cached === null) {
                return null;
            }

            $data = json_decode($cached, true, 512, JSON_THROW_ON_ERROR);

            $this->logger->info('Idempotency key hit — returning cached response', ['key' => $key]);

            return $data;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to read idempotency cache', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Store the response for this idempotency key.
     */
    public function storeResponse(string $key, array $response): void
    {
        $redisKey = self::PREFIX . $key;

        try {
            $this->redis->setex(
                $redisKey,
                $this->ttl,
                json_encode($response, JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable $e) {
            // Non-fatal: worst case the next identical request re-executes
            $this->logger->warning('Failed to store idempotency response', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Acquire a distributed lock for this idempotency key.
     * Prevents two simultaneous requests with the same key from both executing.
     *
     * Returns true if lock was acquired, false if another request is in-flight.
     */
    public function acquireLock(string $key): bool
    {
        $lockKey = self::LOCK_PREFIX . $key;

        try {
            // SET NX EX — atomic: only succeeds if key doesn't exist
            $result = $this->redis->set($lockKey, '1', 'EX', self::LOCK_TTL, 'NX');

            return $result !== null;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to acquire idempotency lock', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            // Fail open — allow the request to proceed if Redis is unavailable
            return true;
        }
    }

    public function releaseLock(string $key): void
    {
        $lockKey = self::LOCK_PREFIX . $key;

        try {
            $this->redis->del([$lockKey]);
        } catch (\Throwable $e) {
            // Non-fatal: lock will expire automatically via TTL
            $this->logger->warning('Failed to release idempotency lock', ['key' => $key]);
        }
    }
}
