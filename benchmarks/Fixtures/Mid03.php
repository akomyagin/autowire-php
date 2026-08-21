<?php

declare(strict_types=1);

namespace AutowirePHP\Benchmarks\Fixtures;

final class Mid03
{
    public function __construct(
        public readonly ?OptionalPort $optional,
        public readonly Leaf07 $leaf,
    ) {
    }
}
