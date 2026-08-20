<?php

declare(strict_types=1);

namespace AutowirePHP\Event;

/**
 * Marker implemented by every event the container dispatches.
 *
 * Type-based listener providers — the standard PSR-14 mechanic — match
 * listeners by the type hint of their single parameter, so a listener hinting
 * this interface subscribes to the whole resolution lifecycle at once instead
 * of naming each concrete event class.
 *
 * The container never inspects this type: it exists purely for the consumer.
 */
interface ContainerEvent
{
}
