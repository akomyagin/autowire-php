<?php

declare(strict_types=1);

namespace AutowirePHP\Benchmarks\Fixtures;

final class Root2
{
    public function __construct(
        public readonly Mid05 $a,
        public readonly Mid06 $b,
        public readonly Mid07 $c,
    ) {
    }
}
