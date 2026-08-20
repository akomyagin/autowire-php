<?php

declare(strict_types=1);

namespace AutowirePHP\Benchmarks\Fixtures;

final class Root1
{
    public function __construct(
        public readonly Mid01 $a,
        public readonly Mid02 $b,
        public readonly Mid03 $c,
        public readonly Mid04 $d,
    ) {
    }
}
