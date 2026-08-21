<?php

declare(strict_types=1);

namespace AutowirePHP\Benchmarks\Fixtures;

final class Mid05
{
    public function __construct(
        public readonly Leaf08 $leaf,
        public readonly ?Leaf09 $maybe,
    ) {
    }
}
