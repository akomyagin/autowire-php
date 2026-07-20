<?php

declare(strict_types=1);

namespace AutowirePHP\Exception;

use Throwable;

/**
 * Marker interface implemented by every exception thrown by the container.
 */
interface ContainerException extends Throwable
{
}
