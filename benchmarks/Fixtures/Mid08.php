<?php

declare(strict_types=1);

namespace AutowirePHP\Benchmarks\Fixtures;

final class Mid08
{
    public function __construct(
        public readonly Mid01 $mid,
        public readonly Leaf12 $leaf,
    ) {
    }
}
