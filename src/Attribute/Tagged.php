<?php

declare(strict_types=1);

namespace AutowirePHP\Attribute;

use Attribute;

/**
 * Marks a variadic constructor parameter as a tagged collection: instead of
 * the default empty set, the parameter receives every class registered under
 * the named tag via Container::tag(), each resolved through an ordinary
 * get() — in registration order, deduplicated, with cycle detection, shared
 * semantics and observability all applying per member.
 *
 * A tag nobody registered yields an empty collection, which is the same
 * outcome an untagged variadic gets. A member that cannot be built fails the
 * whole resolution of the declaring class — members are never silently
 * skipped.
 *
 * On a non-variadic parameter the attribute is silently ignored, consistent
 * with how #[Inject] is ignored outside the class-type branch: an attribute
 * on an unsuitable parameter is a usage error, not a resolution failure. The
 * declared type of the variadic parameter is documentation only — the
 * container does not filter tag members by it; PHP itself raises a TypeError
 * when an incompatible member reaches a typed variadic.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Tagged
{
    public function __construct(public readonly string $tag)
    {
    }
}
