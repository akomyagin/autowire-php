<?php

declare(strict_types=1);

namespace AutowirePHP\Benchmarks\Fixtures;

final class PortAImpl implements PortA
{
    public function __construct(
        public readonly Leaf01 $one,
        public readonly Leaf02 $two,
    ) {
    }
}
