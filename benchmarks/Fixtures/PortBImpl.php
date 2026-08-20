<?php

declare(strict_types=1);

namespace AutowirePHP\Benchmarks\Fixtures;

final class PortBImpl implements PortB
{
    public function __construct(public readonly Leaf03 $three)
    {
    }
}
