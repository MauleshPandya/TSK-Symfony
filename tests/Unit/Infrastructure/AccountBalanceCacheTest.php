<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure;

use App\Infrastructure\Cache\AccountBalanceCache;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Psr\Log\NullLogger;

final class AccountBalanceCacheTest extends TestCase
{
    private ClientInterface&MockObject $redis;
    private AccountBalanceCache $cache;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(ClientInterface::class);
        $this->cache = new AccountBalanceCache($this->redis, new NullLogger());
    }

    public function testGetReturnsCachedBalance(): void
    {
        $this->redis->method('get')
            ->willReturn(json_encode(['balance' => '500.00', 'currency' => 'USD']));

        $result = $this->cache->get('account-123');

        $this->assertNotNull($result);
        $this->assertSame('500.00', $result['balance']);
        $this->assertSame('USD', $result['currency']);
    }

    public function testGetReturnNullOnCacheMiss(): void
    {
        $this->redis->method('get')->willReturn(null);

        $result = $this->cache->get('account-999');

        $this->assertNull($result);
    }

    public function testGetReturnNullOnRedisError(): void
    {
        $this->redis->method('get')->willThrowException(new \Exception('Redis unavailable'));

        $result = $this->cache->get('account-123');

        // Fail open — null returned, not an exception
        $this->assertNull($result);
    }

    public function testSetCallsRedisSetex(): void
    {
        $this->redis->expects($this->once())
            ->method('setex')
            ->with(
                $this->stringContains('account-123'),
                30,
                $this->stringContains('500.00'),
            );

        $this->cache->set('account-123', '500.00', 'USD');
    }

    public function testInvalidateManyDeletesAllKeys(): void
    {
        $this->redis->expects($this->once())
            ->method('del')
            ->with($this->callback(fn ($keys) => count($keys) === 2));

        $this->cache->invalidateMany('account-001', 'account-002');
    }

    public function testCacheDoesNotThrowOnInvalidationError(): void
    {
        $this->redis->method('del')->willThrowException(new \Exception('Redis down'));

        // Should not throw — cache errors must never interrupt business logic
        $this->cache->invalidateMany('account-001');
        $this->assertTrue(true); // reached here = no exception
    }
}
