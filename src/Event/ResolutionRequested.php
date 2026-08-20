<?php

declare(strict_types=1);

namespace AutowirePHP\Event;

/**
 * Dispatched on every entry into Container::get(), top-level and recursive
 * alike, including requests that will be served from the shared instance cache
 * and requests that will fail.
 *
 * `$depth` is the zero-based nesting level of `$id` itself, captured before the
 * id is pushed onto the resolution path — the same number the PSR-3 records
 * carry. A top-level get() reports 0, its dependencies 1, and so on.
 *
 * Exactly one terminal event (ServiceResolved or ResolutionFailed) with the
 * same `$id` and `$depth` follows for this frame, provided listeners themselves
 * do not throw. That pairing is what makes request counting, resolution trees
 * and timing spans possible.
 *
 * The event is read-only: observing resolution must not be able to influence
 * it.
 */
final readonly class ResolutionRequested implements ContainerEvent
{
    public function __construct(
        public string $id,
        public int $depth,
    ) {
    }
}
