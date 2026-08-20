<?php

declare(strict_types=1);

namespace AutowirePHP\Benchmarks;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * Minimal in-memory PSR-16 driver for the benchmark, deliberately living in
 * benchmarks/ and not in src/: the container consumes a user-supplied PSR
 * cache and ships no implementation of its own. Values take an honest
 * serialize()/unserialize() round trip, as they would in any real store.
 */
final class ArrayCache implements CacheInterface
{
    /**
     * @var array<string, string>
     */
    private array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return isset($this->data[$key]) ? unserialize($this->data[$key]) : $default;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $this->data[$key] = serialize($value);

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->data[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->data = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }
}
