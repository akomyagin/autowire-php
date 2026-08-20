<?php

declare(strict_types=1);

namespace AutowirePHP\Event;

/**
 * Terminal event of a successful Container::get() frame, paired with the
 * ResolutionRequested carrying the same `$id` and `$depth`.
 *
 * `$instance` is the object the caller receives. A listener may inspect it —
 * `$instance::class` already names the concrete class, which is why no separate
 * `concrete` field exists — but cannot replace it: the property is read-only and
 * the container returns its own local variable, not this field.
 *
 * `$fromCache` distinguishes a shared instance served from the cache (true) from
 * one that was just built (false), which is all a cache-hit-ratio metric needs.
 * Why the instance was shared — singleton() or #[Singleton] — stays a detail of
 * the debug log.
 *
 * The event is dispatched after the id has been popped off the resolution path,
 * so a listener of a top-level frame may safely call get() for the same id.
 */
final readonly class ServiceResolved implements ContainerEvent
{
    public function __construct(
        public string $id,
        public int $depth,
        public object $instance,
        public bool $fromCache,
    ) {
    }
}
