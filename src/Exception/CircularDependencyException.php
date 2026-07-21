<?php

declare(strict_types=1);

namespace AutowirePHP\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown when a dependency cycle is detected along the current resolution
 * path. The chain lists the resolution path up to and including the id that
 * closed the cycle.
 */
final class CircularDependencyException extends RuntimeException implements ContainerException
{
    /**
     * @param list<string> $chain
     */
    public function __construct(
        private readonly array $chain,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Circular dependency detected: %s.', implode(' -> ', $chain)),
            0,
            $previous,
        );
    }

    /**
     * @return list<string>
     */
    public function getChain(): array
    {
        return $this->chain;
    }
}
