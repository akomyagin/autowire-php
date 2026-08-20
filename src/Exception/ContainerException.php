<?php

declare(strict_types=1);

namespace AutowirePHP\Exception;

use Psr\Container\ContainerExceptionInterface;

/**
 * Marker interface for a failed resolution, bridging the container's exception
 * hierarchy to PSR-11.
 *
 * Carrying this marker also means "the container may treat this as recoverable":
 * it is what the nullable and union parameter probes catch before falling back
 * to a default or null. ListenerException therefore stays outside this marker
 * on purpose — see its docblock.
 */
interface ContainerException extends ContainerExceptionInterface
{
}
