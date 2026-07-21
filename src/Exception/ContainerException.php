<?php

declare(strict_types=1);

namespace AutowirePHP\Exception;

use Psr\Container\ContainerExceptionInterface;

/**
 * Marker interface implemented by every exception thrown by the container,
 * bridging the container's exception hierarchy to PSR-11.
 */
interface ContainerException extends ContainerExceptionInterface
{
}
