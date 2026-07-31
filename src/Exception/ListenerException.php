<?php

declare(strict_types=1);

namespace AutowirePHP\Exception;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;
use Throwable;

/**
 * Thrown when a PSR-14 listener itself throws while observing resolution.
 *
 * Deliberately implements PSR-11's ContainerExceptionInterface but NOT the
 * local ContainerException marker. That marker is what the container's own
 * recovery paths catch when probing a nullable or union parameter, so anything
 * carrying it may be silently downgraded to a null argument. A listener failure
 * is not a resolution failure and must never be absorbed that way: it has to
 * reach the caller, or a broken listener would quietly turn a resolvable graph
 * into one with missing dependencies.
 */
final class ListenerException extends RuntimeException implements ContainerExceptionInterface
{
    public function __construct(
        private readonly object $event,
        Throwable $previous,
    ) {
        parent::__construct(
            sprintf(
                'A listener of %s threw %s: %s',
                $event::class,
                $previous::class,
                $previous->getMessage(),
            ),
            0,
            $previous,
        );
    }

    /**
     * The event whose dispatch failed.
     */
    public function getEvent(): object
    {
        return $this->event;
    }
}
