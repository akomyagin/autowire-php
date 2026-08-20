<?php

declare(strict_types=1);

namespace AutowirePHP\Tests\Cache;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * Honest in-memory PSR-16: values take a serialize()/unserialize() round trip,
 * so anything unserialisable would fail here just as it would in a real store.
 * Reads and writes are recorded so tests can assert cache traffic.
 */
final class FakeSimpleCache implements CacheInterface
{
    /**
     * @var array<string, string>
     */
    public array $data = [];

    /**
     * @var list<string>
     */
    public array $reads = [];

    /**
     * @var list<string>
     */
    public array $writes = [];

    /**
     * @var list<DateInterval|int|null>
     */
    public array $ttls = [];

    public function get(string $key, mixed $default = null): mixed
    {
        $this->reads[] = $key;

        return isset($this->data[$key]) ? unserialize($this->data[$key]) : $default;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $this->writes[] = $key;
        $this->ttls[] = $ttl;
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
