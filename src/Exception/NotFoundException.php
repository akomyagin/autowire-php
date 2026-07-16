<?php

declare(strict_types=1);

namespace Akomyagin\AutowirePHP\Exception;

/**
 * Thrown when the requested id does not exist as a class or interface and no
 * binding is registered for it.
 */
final class NotFoundException extends \RuntimeException implements ContainerException
{
    public function __construct(
        private readonly string $id,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('No binding or resolvable class found for id "%s".', $id),
            0,
            $previous,
        );
    }

    public function getId(): string
    {
        return $this->id;
    }
}
