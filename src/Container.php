<?php

declare(strict_types=1);

namespace AutowirePHP;

use AutowirePHP\Exception\NotFoundException;
use AutowirePHP\Exception\NotInstantiableException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Framework-agnostic dependency injection container.
 *
 * Resolves object graphs through the PHP Reflection API.
 */
final class Container
{
    /**
     * Explicit interface/abstract -> concrete class bindings.
     *
     * @var array<class-string, class-string>
     */
    private array $bindings = [];

    /**
     * Register an explicit binding from an abstract id (usually an interface)
     * to a concrete implementation class.
     *
     * @param class-string $abstract
     * @param class-string $concrete
     */
    public function bind(string $abstract, string $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
    }

    /**
     * Resolve an instance for the given class-string id.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     *
     * @throws NotFoundException when the id is neither a binding nor an existing type.
     * @throws NotInstantiableException when the resolved type cannot be instantiated.
     */
    public function get(string $id): object
    {
        $concrete = $this->bindings[$id] ?? $id;

        if (!class_exists($concrete) && !interface_exists($concrete)) {
            throw new NotFoundException($concrete);
        }

        $reflection = new ReflectionClass($concrete);

        if (!$reflection->isInstantiable()) {
            if ($reflection->isInterface()) {
                $reason = 'It is an interface with no binding registered.';
            } elseif ($reflection->isAbstract()) {
                $reason = 'It is an abstract class.';
            } else {
                $reason = 'It cannot be instantiated (e.g. private or protected constructor).';
            }

            throw new NotInstantiableException($concrete, $reason);
        }

        return $this->build($reflection);
    }

    /**
     * Instantiate the reflected class, autowiring its constructor parameters.
     */
    private function build(ReflectionClass $reflection): object
    {
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $args = [];

        foreach ($constructor->getParameters() as $param) {
            $args[] = $this->resolveParameter($param, $reflection->getName());
        }

        return $reflection->newInstanceArgs($args);
    }

    /**
     * Resolve a single constructor parameter: class/interface types are resolved
     * recursively through the container, otherwise the default value is used.
     *
     * @throws NotInstantiableException when the parameter cannot be autowired.
     */
    private function resolveParameter(ReflectionParameter $param, string $declaringClass): mixed
    {
        $type = $param->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return $this->get($type->getName());
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($type === null) {
            throw new NotInstantiableException(
                $declaringClass,
                sprintf('Constructor parameter "$%s" has no type hint and cannot be autowired.', $param->getName()),
            );
        }

        if ($type instanceof ReflectionNamedType) {
            throw new NotInstantiableException(
                $declaringClass,
                sprintf(
                    'Constructor parameter "$%s" is of built-in type "%s" and cannot be autowired.',
                    $param->getName(),
                    $type->getName(),
                ),
            );
        }

        throw new NotInstantiableException(
            $declaringClass,
            sprintf(
                'Constructor parameter "$%s" has type "%s" and cannot be autowired.',
                $param->getName(),
                (string) $type,
            ),
        );
    }

    /**
     * Whether an explicit binding is registered for the given abstract id.
     *
     * @param class-string $abstract
     */
    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]);
    }
}
