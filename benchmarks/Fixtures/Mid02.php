<?php

declare(strict_types=1);

namespace AutowirePHP\Benchmarks\Fixtures;

final class Mid02
{
    public function __construct(
        public readonly Leaf02 $leaf,
        public readonly PortB $port,
    ) {
    }
}
