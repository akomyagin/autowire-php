<?php

declare(strict_types=1);

namespace AutowirePHP\Benchmarks\Fixtures;

final class Mid04
{
    public function __construct(
        public readonly PortA|PortB $port,
    ) {
    }
}
