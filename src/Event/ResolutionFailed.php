<?php

declare(strict_types=1);

namespace AutowirePHP\Event;

use Throwable;

/**
 * Terminal event of a failed Container::get() frame, paired with the
 * ResolutionRequested carrying the same `$id` and `$depth`.
 *
 * `$exception` is the very instance that leaves the frame: a
 * CircularDependencyException with its full getChain(), a NotFoundException, an
 * UnresolvableParameterException, or anything a user constructor threw.
 *
 * While the stack unwinds, one such event is dispatched per active frame — same
 * exception instance, decreasing `$depth`, innermost first. A listener that
 * wants a failure once deduplicates by the identity of `$exception`.
 *
 * This event does not imply that the top-level get() will throw. When the
 * container probes a nullable or union-typed parameter, the child frame really
 * did fail — hence the event — and the parent then swallows the
 * ContainerException and falls back, finishing with its own ServiceResolved.
 */
final readonly class ResolutionFailed implements ContainerEvent
{
    public function __construct(
        public string $id,
        public int $depth,
        public Throwable $exception,
    ) {
    }
}
