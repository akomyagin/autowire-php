<?php

declare(strict_types=1);

namespace AutowirePHP\Benchmarks\Fixtures;

final class Mid10
{
    public function __construct(
        public readonly PortC $portC,
        public readonly PortD $portD,
    ) {
    }
}
