<?php

declare(strict_types=1);

namespace AutowirePHP;

use AutowirePHP\Exception\CircularDependencyException;
use AutowirePHP\Exception\ContainerException;
use AutowirePHP\Exception\NotFoundException;
use AutowirePHP\Exception\NotInstantiableException;
use AutowirePHP\Exception\UnresolvableParameterException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

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
     * Set of ids on the current resolution path, for O(1) cycle checks.
     *
     * @var array<string, true>
     */
    private array $resolving = [];

    /**
     * Ordered resolution path, used to build a readable cycle message.
     *
     * @var list<string>
     */
    private array $resolutionChain = [];

    /**
     * Ids registered as shared (singleton) via singleton().
     *
     * @var array<string, true>
     */
    private array $shared = [];

    /**
     * Resolved shared instances, keyed by the id under which they were requested.
     *
     * @var array<string, object>
     */
    private array $instances = [];

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
     * Register an id as shared: the first resolved instance is cached and
     * returned on every subsequent get() for the same id. The cache is keyed
     * by $abstract, so requesting $concrete directly (bypassing $abstract)
     * yields a separate, non-shared instance.
     *
     * @param class-string $abstract
     * @param class-string|null $concrete
     */
    public function singleton(string $abstract, ?string $concrete = null): void
    {
        if ($concrete !== null) {
            $this->bind($abstract, $concrete);
        }

        $this->shared[$abstract] = true;
    }

    /**
     * Resolve an instance for the given class-string id.
     *
     * If the id was registered via singleton() and has already been resolved,
     * the cached instance is returned without rebuilding the object graph.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     *
     * @throws NotFoundException when the id is neither a binding nor an existing type.
     * @throws NotInstantiableException when the resolved type cannot be instantiated.
     * @throws UnresolvableParameterException when a constructor parameter cannot be autowired.
     * @throws CircularDependencyException when the id is already on the current resolution path.
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        $this->enter($id);

        try {
            $concrete = $this->bindings[$id] ?? $id;

            if (!class_exists($concrete) && !interface_exists($concrete)) {
                throw new NotFoundException($concrete);
            }

            if ($concrete !== $id) {
                $this->enter($concrete);

                try {
                    $object = $this->instantiate($concrete);
                } finally {
                    $this->leave($concrete);
                }
            } else {
                $object = $this->instantiate($concrete);
            }

            if (isset($this->shared[$id])) {
                $this->instances[$id] = $object;
            }

            return $object;
        } finally {
            $this->leave($id);
        }
    }

    /**
     * Push an id onto the current resolution path, failing if it is already there.
     *
     * @throws CircularDependencyException when the id closes a cycle on the current path.
     */
    private function enter(string $id): void
    {
        if (isset($this->resolving[$id])) {
            $chain = [...$this->resolutionChain, $id];
            throw new CircularDependencyException($chain);
        }

        $this->resolving[$id] = true;
        $this->resolutionChain[] = $id;
    }

    /**
     * Pop an id from the current resolution path.
     */
    private function leave(string $id): void
    {
        unset($this->resolving[$id]);
        array_pop($this->resolutionChain);
    }

    /**
     * Reflect the concrete class and delegate to build(), rejecting types that
     * cannot be instantiated.
     *
     * @throws NotInstantiableException when the type cannot be instantiated.
     */
    private function instantiate(string $concrete): object
    {
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
            if ($param->isVariadic()) {
                break;
            }

            $args[] = $this->resolveParameter($param, $reflection->getName());
        }

        return $reflection->newInstanceArgs($args);
    }

    /**
     * Resolve a single constructor parameter. Resolution priority: class type
     * through the container -> union members in order -> default value -> null
     * (if nullable) -> UnresolvableParameterException.
     *
     * A CircularDependencyException is never swallowed while probing nullable
     * or union members: it always propagates to the caller.
     *
     * @throws UnresolvableParameterException when the parameter cannot be autowired.
     */
    private function resolveParameter(ReflectionParameter $param, string $declaringClass): mixed
    {
        $type = $param->getType();

        if ($type instanceof ReflectionUnionType) {
            return $this->resolveUnionParameter($type, $param, $declaringClass);
        }

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            if (!$type->allowsNull()) {
                return $this->get($type->getName());
            }

            try {
                return $this->get($type->getName());
            } catch (CircularDependencyException $exception) {
                throw $exception;
            } catch (ContainerException) {
                if ($param->isDefaultValueAvailable()) {
                    return $param->getDefaultValue();
                }

                return null;
            }
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($type === null) {
            throw new UnresolvableParameterException(
                $declaringClass,
                $param->getName(),
                'It has no type hint and no default value.',
            );
        }

        if ($param->allowsNull()) {
            return null;
        }

        if ($type instanceof ReflectionNamedType) {
            throw new UnresolvableParameterException(
                $declaringClass,
                $param->getName(),
                sprintf('It is of built-in type "%s" and has no default value.', $type->getName()),
            );
        }

        throw new UnresolvableParameterException(
            $declaringClass,
            $param->getName(),
            sprintf('It has type "%s" and no member of it could be resolved.', (string) $type),
        );
    }

    /**
     * Resolve a union-typed constructor parameter by trying each class member
     * in declaration order, then falling back to the default value or null.
     *
     * @throws UnresolvableParameterException when no union member can be
     *         resolved and there is no default value or null fallback.
     */
    private function resolveUnionParameter(
        ReflectionUnionType $type,
        ReflectionParameter $param,
        string $declaringClass,
    ): mixed {
        foreach ($type->getTypes() as $member) {
            if (!$member instanceof ReflectionNamedType || $member->isBuiltin()) {
                continue;
            }

            try {
                return $this->get($member->getName());
            } catch (CircularDependencyException $exception) {
                throw $exception;
            } catch (ContainerException) {
                continue;
            }
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($param->allowsNull()) {
            return null;
        }

        throw new UnresolvableParameterException(
            $declaringClass,
            $param->getName(),
            sprintf('It has type "%s" and no member of it could be resolved.', (string) $type),
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
