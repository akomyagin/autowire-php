<?php

declare(strict_types=1);

namespace AutowirePHP\Attribute;

use Attribute;

/**
 * Declares which concrete class-string a constructor parameter resolves to
 * when no explicit binding exists for its type.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Inject
{
    public function __construct(public readonly string $concrete)
    {
    }
}
