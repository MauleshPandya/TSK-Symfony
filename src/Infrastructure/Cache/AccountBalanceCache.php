<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use Predis\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Redis cache for account balance reads.
 *
 * Why cache balances?
 *   In production, GET /accounts/{id} is called far more than POST /transfers.
 *   Dashboards, mobile apps, and reports poll balances frequently.
 *   Without caching, every read hits MySQL with a full row fetch.
 *
 * Invalidation strategy:
 *   Cache is invalidated immediately after any transfer or balance-changing
 *   operation. We use write-through invalidation (not write-through update)
 *   because the next read re-fetches the authoritative value from MySQL.
 *
 * TTL:
 *   30 seconds as a safety net. Even if an invalidation is missed (bug, crash),
 *   the stale cache expires quickly. Tune based on your read pattern.
 *
 * This is NOT used inside transfer transactions — the pessimistic lock always
 * reads directly from MySQL to get the authoritative balance.
 */
final class AccountBalanceCache
{
    private const PREFIX = 'balance:';
    private const TTL    = 30; // seconds

    public function __construct(
        private readonly ClientInterface $redis,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Get a cached balance. Returns null on cache miss.
     *
     * @return array{balance: string, currency: string}|null
     */
    public function get(string $accountId): ?array
    {
        try {
            $cached = $this->redis->get(self::PREFIX . $accountId);

            if ($cached === null) {
                return null;
            }

            return json_decode($cached, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger->warning('Balance cache read error', ['account_id' => $accountId, 'error' => $e->getMessage()]);

            return null; // Fail open — fall through to DB
        }
    }

    /**
     * Store a balance in cache after a DB read.
     */
    public function set(string $accountId, string $balance, string $currency): void
    {
        try {
            $this->redis->setex(
                self::PREFIX . $accountId,
                self::TTL,
                json_encode(['balance' => $balance, 'currency' => $currency], JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Balance cache write error', ['account_id' => $accountId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Invalidate cache entries for all accounts involved in a transfer.
     * Called immediately after a transfer commits.
     */
    public function invalidateMany(string ...$accountIds): void
    {
        if (empty($accountIds)) {
            return;
        }

        try {
            $keys = array_map(fn (string $id) => self::PREFIX . $id, $accountIds);
            $this->redis->del($keys);

            $this->logger->debug('Balance cache invalidated', ['account_ids' => $accountIds]);
        } catch (\Throwable $e) {
            $this->logger->warning('Balance cache invalidation error', ['error' => $e->getMessage()]);
        }
    }

    public function invalidate(string $accountId): void
    {
        $this->invalidateMany($accountId);
    }
}
