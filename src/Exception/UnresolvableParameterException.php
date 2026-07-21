<?php

declare(strict_types=1);

namespace AutowirePHP\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown when a constructor parameter cannot be autowired: no type hint, a
 * built-in type without a default value, or a union type with no resolvable
 * member and no default value.
 */
final class UnresolvableParameterException extends RuntimeException implements ContainerException
{
    public function __construct(
        private readonly string $className,
        private readonly string $parameterName,
        private readonly string $reason = '',
        ?Throwable $previous = null,
    ) {
        $message = sprintf(
            'Constructor parameter "$%s" of class "%s" cannot be resolved.',
            $parameterName,
            $className,
        );

        if ($reason !== '') {
            $message .= ' ' . $reason;
        }

        parent::__construct($message, 0, $previous);
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getParameterName(): string
    {
        return $this->parameterName;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
