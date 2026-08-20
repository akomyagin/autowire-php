<?php

declare(strict_types=1);

namespace AutowirePHP\Tests\Cache;

use DateInterval;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

final class FakeCacheItem implements CacheItemInterface
{
    public function __construct(
        private readonly string $key,
        private mixed $value = null,
        private bool $hit = false,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;
        $this->hit = true;

        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        throw new RuntimeException('The container must never set an expiration.');
    }

    public function expiresAfter(DateInterval|int|null $time): static
    {
        throw new RuntimeException('The container must never call expiresAfter().');
    }
}
