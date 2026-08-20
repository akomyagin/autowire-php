<?php

declare(strict_types=1);

namespace AutowirePHP\Benchmarks\Fixtures;

use AutowirePHP\Attribute\Singleton;

#[Singleton]
final class PortDImpl implements PortD
{
    public function __construct(public readonly Leaf06 $six)
    {
    }
}
