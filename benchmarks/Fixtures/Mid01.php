<?php

declare(strict_types=1);

namespace AutowirePHP\Benchmarks\Fixtures;

final class Mid01
{
    public function __construct(
        public readonly Leaf01 $leaf,
        public readonly PortA $port,
    ) {
    }
}
