<?php

declare(strict_types=1);

namespace AutowirePHP\Benchmarks\Fixtures;

final class Leaf07
{
    public function __construct(public readonly int $retries = 3)
    {
    }
}
