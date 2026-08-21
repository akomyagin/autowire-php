<?php

declare(strict_types=1);

namespace AutowirePHP\Benchmarks\Fixtures;

final class Root3
{
    public function __construct(
        public readonly Mid08 $a,
        public readonly Mid09 $b,
        public readonly Mid10 $c,
    ) {
    }
}
