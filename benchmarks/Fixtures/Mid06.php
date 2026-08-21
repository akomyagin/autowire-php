<?php

declare(strict_types=1);

namespace AutowirePHP\Benchmarks\Fixtures;

final class Mid06
{
    public function __construct(
        public readonly PortC $port,
        public readonly Leaf10 $leaf,
    ) {
    }
}
