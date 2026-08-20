<?php

declare(strict_types=1);

namespace AutowirePHP\Benchmarks\Fixtures;

final class Mid07
{
    public function __construct(
        public readonly PortD $port,
        public readonly Leaf11 $leaf,
    ) {
    }
}
