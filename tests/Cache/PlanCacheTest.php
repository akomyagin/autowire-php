<?php

declare(strict_types=1);

namespace AutowirePHP\Tests\Cache;

use AutowirePHP\Cache\PlanCache;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

final class PlanCacheTest extends TestCase
{
    public function testKeyMatchesPsr6CharsetAndLengthLimit(): void
    {
        $hash = PlanCache::configHash([], []);
        $key = PlanCache::keyFor($hash, 'Some\\Vendor\\Class');

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_.]{1,64}$/', $key);
    }

    public function testKeyIsDeterministicAndSensitiveToId(): void
    {
        $hash = PlanCache::configHash([], []);

        self::assertSame(PlanCache::keyFor($hash, 'A\\B'), PlanCache::keyFor($hash, 'A\\B'));
        self::assertNotSame(PlanCache::keyFor($hash, 'A\\B'), PlanCache::keyFor($hash, 'A\\C'));
    }

    public function testConfigHashIgnoresRegistrationOrder(): void
    {
        $forward = PlanCache::configHash(['A' => 'X', 'B' => 'Y'], ['A' => true, 'C' => true]);
        $backward = PlanCache::configHash(['B' => 'Y', 'A' => 'X'], ['C' => true, 'A' => true]);

        self::assertSame($forward, $backward);
    }

    public function testConfigHashChangesWithBindingsAndShared(): void
    {
        $base = PlanCache::configHash(['A' => 'X'], []);

        self::assertNotSame($base, PlanCache::configHash(['A' => 'Y'], []));
        self::assertNotSame($base, PlanCache::configHash(['A' => 'X'], ['A' => true]));
    }

    public function testStoresAndFetchesThroughPsr16(): void
    {
        $cache = new PlanCache(new FakeSimpleCache(), new CacheSpyLogger());

        $cache->store('k1', ['payload' => 1]);

        self::assertSame(['payload' => 1], $cache->fetch('k1'));
    }

    public function testStoresAndFetchesThroughPsr6(): void
    {
        $cache = new PlanCache(new FakeCachePool(), new CacheSpyLogger());

        $cache->store('k1', ['payload' => 2]);

        self::assertSame(['payload' => 2], $cache->fetch('k1'));
    }

    public function testFetchReturnsNullOnMissForBothFlavours(): void
    {
        $logger = new CacheSpyLogger();

        self::assertNull((new PlanCache(new FakeSimpleCache(), $logger))->fetch('absent'));
        self::assertNull((new PlanCache(new FakeCachePool(), $logger))->fetch('absent'));
        self::assertSame([], $logger->records, 'A plain miss is not a failure and must not be logged.');
    }

    public function testThrowingDriverDegradesToMissAndLogsOnRead(): void
    {
        $logger = new CacheSpyLogger();
        $cache = new PlanCache(new ThrowingSimpleCache(), $logger);

        self::assertNull($cache->fetch('any'));
        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::DEBUG, $logger->records[0]['level']);
        self::assertStringContainsString('Plan cache {operation} failed', $logger->records[0]['message']);
        self::assertSame('read', $logger->records[0]['context']['operation']);
        self::assertSame('any', $logger->records[0]['context']['key']);
    }

    public function testThrowingDriverSwallowsAndLogsOnWrite(): void
    {
        $logger = new CacheSpyLogger();
        $cache = new PlanCache(new ThrowingSimpleCache(), $logger);

        $cache->store('any', ['x' => 1]);

        self::assertCount(1, $logger->records);
        self::assertSame('write', $logger->records[0]['context']['operation']);
    }

    public function testPsr16SetIsCalledWithoutTtl(): void
    {
        $driver = new FakeSimpleCache();
        $cache = new PlanCache($driver, new CacheSpyLogger());

        $cache->store('k', ['x' => 1]);

        self::assertSame([null], $driver->ttls, 'Plan validity is governed by the manifest, never by TTL.');
    }
}

/**
 * PSR-3 spy local to this file: fixtures from other test files are not PSR-4
 * discoverable and must not be relied upon.
 */
final class CacheSpyLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
