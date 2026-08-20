<?php

declare(strict_types=1);

namespace AutowirePHP\Benchmarks\Fixtures;

final class Leaf08
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly string $name = 'bench',
        public readonly array $options = [],
    ) {
    }
}
