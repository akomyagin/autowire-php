<?php

declare(strict_types=1);

namespace Akomyagin\AutowirePHP\Exception;

/**
 * Thrown when the resolved type exists but cannot be instantiated: an
 * interface without a binding, an abstract class, a private/protected
 * constructor, or a constructor with required parameters (until autowiring
 * arrives in stage 2).
 */
final class NotInstantiableException extends \RuntimeException implements ContainerException
{
    public function __construct(
        private readonly string $className,
        private readonly string $reason = '',
        ?\Throwable $previous = null,
    ) {
        $message = sprintf('Class "%s" is not instantiable.', $className);

        if ($reason !== '') {
            $message .= ' ' . $reason;
        }

        parent::__construct($message, 0, $previous);
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
