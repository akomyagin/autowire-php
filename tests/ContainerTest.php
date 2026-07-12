<?php

declare(strict_types=1);

namespace Akomyagin\AutowirePHP\Tests;

use Akomyagin\AutowirePHP\Container;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    /**
     * Stage 0 smoke test: the container can be constructed and reports the
     * absence of a binding. Real resolution behaviour arrives in Stage 1.
     */
    public function testContainerReportsNoBindingByDefault(): void
    {
        $container = new Container();

        self::assertFalse($container->has(\stdClass::class));
    }
}
