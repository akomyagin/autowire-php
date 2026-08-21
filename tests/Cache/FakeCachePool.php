<?php

declare(strict_types=1);

namespace AutowirePHP\Tests\Cache;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Honest in-memory PSR-6 pool with the same serialize() round trip and the
 * same read/write instrumentation as FakeSimpleCache.
 */
final class FakeCachePool implements CacheItemPoolInterface
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

    public function getItem(string $key): CacheItemInterface
    {
        $this->reads[] = $key;

        if (isset($this->data[$key])) {
            return new FakeCacheItem($key, unserialize($this->data[$key]), true);
        }

        return new FakeCacheItem($key);
    }

    /**
     * @param string[] $keys
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        $items = [];

        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }

        return $items;
    }

    public function hasItem(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function clear(): bool
    {
        $this->data = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        unset($this->data[$key]);

        return true;
    }

    /**
     * @param string[] $keys
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->deleteItem($key);
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        $this->writes[] = $item->getKey();
        $this->data[$item->getKey()] = serialize($item->get());

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }
}
