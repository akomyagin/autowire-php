<?php

declare(strict_types=1);

namespace AutowirePHP\Cache;

use AutowirePHP\Compiled\CompiledPlan;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Normalises a PSR-6 pool or a PSR-16 cache into the only two operations the
 * container needs: "give me an array by key or nothing" and "store an array by
 * key". The branching on the driver flavour lives here and nowhere else.
 *
 * No TTL is ever set (PSR-16 set() is called without $ttl, PSR-6
 * expiresAfter() is never called): plan validity is governed by the config
 * hash and the file manifest, not by wall-clock time. Eviction belongs to the
 * driver's owner.
 *
 * A cache failure has no right to break resolution: any Throwable from the
 * driver is swallowed here, reported to the debug log, and degrades to "no
 * plan found" / "plan not stored" — the container falls back to the runtime
 * reflection path. The cache is an optimisation, not a point of failure.
 *
 * @internal Not part of the public API; Container is the only entry point.
 */
final class PlanCache
{
    private const KEY_PREFIX = 'awp_';

    public function __construct(
        private readonly CacheItemPoolInterface|CacheInterface $driver,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Order-insensitive hash of the container configuration a plan is compiled
     * against: the same bindings registered in a different order must land on
     * the same cache entry, hence the sort before hashing. The format version
     * participates so that a library upgrade never resurrects an old payload.
     *
     * The contextual map enters the hash whole — consumers, abstracts and
     * concretes alike — because any of the three changes what a parameter
     * compiles into. The inner maps are sorted too, so registration order
     * never influences the hash on that level either.
     *
     * Of the factory map only the ids participate: a Closure cannot be
     * serialised, and the body of a factory cannot change the plan of any
     * other id — what matters to a plan is solely which ids are resolved by a
     * factory instead of a compiled node.
     *
     * The tag map is sorted by tag name only: the member lists inside keep
     * their order, because member order is semantic (it is the order of the
     * injected collection), so two registrations differing only in member
     * order genuinely are different configurations.
     *
     * @param array<class-string, class-string> $bindings
     * @param array<string, true> $shared
     * @param array<class-string, array<class-string, class-string>> $contextual
     * @param list<class-string> $factoryIds
     * @param array<string, list<class-string>> $tags
     */
    public static function configHash(
        array $bindings,
        array $shared,
        array $contextual = [],
        array $factoryIds = [],
        array $tags = [],
    ): string {
        ksort($bindings);
        ksort($shared);
        ksort($contextual);

        foreach ($contextual as &$abstractMap) {
            ksort($abstractMap);
        }

        unset($abstractMap);

        sort($factoryIds);
        ksort($tags);

        return hash(
            'sha256',
            serialize([CompiledPlan::FORMAT_VERSION, $bindings, $shared, $contextual, $factoryIds, $tags]),
        );
    }

    /**
     * PSR-6 guarantees only [A-Za-z0-9_.] up to 64 characters and reserves
     * {}()/\@: — and a class-string id always contains backslashes — so the id
     * never enters the key raw: fixed prefix plus a truncated hash of
     * (configuration, id), 64 characters total.
     */
    public static function keyFor(string $configHash, string $id): string
    {
        return self::KEY_PREFIX . substr(hash('sha256', $configHash . '|' . $id), 0, 60);
    }

    /**
     * The stored payload, or null when the entry is absent — or the driver
     * failed, which degrades to the same thing.
     */
    public function fetch(string $key): mixed
    {
        try {
            if ($this->driver instanceof CacheItemPoolInterface) {
                $item = $this->driver->getItem($key);

                return $item->isHit() ? $item->get() : null;
            }

            return $this->driver->get($key);
        } catch (Throwable $failure) {
            $this->logger->debug(
                'Plan cache {operation} failed for {key}: {error}',
                [
                    'operation' => 'read',
                    'key' => $key,
                    'error' => $failure->getMessage(),
                    'exception' => $failure,
                ],
            );

            return null;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function store(string $key, array $payload): void
    {
        try {
            if ($this->driver instanceof CacheItemPoolInterface) {
                $item = $this->driver->getItem($key);
                $item->set($payload);
                $this->driver->save($item);

                return;
            }

            $this->driver->set($key, $payload);
        } catch (Throwable $failure) {
            $this->logger->debug(
                'Plan cache {operation} failed for {key}: {error}',
                [
                    'operation' => 'write',
                    'key' => $key,
                    'error' => $failure->getMessage(),
                    'exception' => $failure,
                ],
            );
        }
    }
}
