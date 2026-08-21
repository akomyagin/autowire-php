<?php

declare(strict_types=1);

namespace AutowirePHP\Benchmarks\Fixtures;

final class PortCImpl implements PortC
{
    public function __construct(
        public readonly Leaf04 $four,
        public readonly Leaf05 $five,
    ) {
    }
}
