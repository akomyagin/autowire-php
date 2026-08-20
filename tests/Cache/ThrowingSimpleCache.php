<?php

declare(strict_types=1);

namespace AutowirePHP\Tests\Cache;

use DateInterval;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * PSR-16 driver whose every operation throws, standing in for a cache backend
 * that is down. The container must degrade to the runtime path, never break.
 */
final class ThrowingSimpleCache implements CacheInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        throw new RuntimeException('cache backend is down');
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        throw new RuntimeException('cache backend is down');
    }

    public function delete(string $key): bool
    {
        throw new RuntimeException('cache backend is down');
    }

    public function clear(): bool
    {
        throw new RuntimeException('cache backend is down');
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        throw new RuntimeException('cache backend is down');
    }

    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        throw new RuntimeException('cache backend is down');
    }

    public function deleteMultiple(iterable $keys): bool
    {
        throw new RuntimeException('cache backend is down');
    }

    public function has(string $key): bool
    {
        throw new RuntimeException('cache backend is down');
    }
}
