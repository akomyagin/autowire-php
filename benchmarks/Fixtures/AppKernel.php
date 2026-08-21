<?php

declare(strict_types=1);

namespace AutowirePHP\Benchmarks\Fixtures;

final class AppKernel
{
    public function __construct(
        public readonly Root1 $one,
        public readonly Root2 $two,
        public readonly Root3 $three,
    ) {
    }
}
