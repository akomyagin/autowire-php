<?php

declare(strict_types=1);

namespace AutowirePHP\Attribute;

use Attribute;

/**
 * Marks a class as shared: the container caches and reuses a single
 * instance, as a declarative alternative to Container::singleton().
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Singleton
{
}
