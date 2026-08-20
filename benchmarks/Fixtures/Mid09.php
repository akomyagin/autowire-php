<?php

declare(strict_types=1);

namespace AutowirePHP\Benchmarks\Fixtures;

final class Mid09
{
    public function __construct(
        public readonly PortB $port,
        public readonly Leaf05 $leaf,
        public readonly int $level = 2,
    ) {
    }
}
