<?php

declare(strict_types=1);

namespace Akomyagin\AutowirePHP;

use Akomyagin\AutowirePHP\Exception\NotFoundException;
use Akomyagin\AutowirePHP\Exception\NotInstantiableException;

/**
 * Framework-agnostic dependency injection container.
 *
 * The container resolves object graphs through the PHP Reflection API. Its
 * public surface will grow stage by stage; this skeleton exists so that
 * `composer test` has a real class to autoload and exercise from day one.
 *
 * Stage roadmap (see docs/TECHNICAL_PLAN.md):
 *   - Stage 1: manual interface -> implementation bindings, no autowiring.
 *   - Stage 2: reflection-based constructor autowiring (recursive resolution).
 *   - Stage 3: interface-aware circular dependency detection (resolution stack).
 *   - Stage 4: lifecycle control (singleton vs transient).
 *   - Stage 5: constructor edge cases (union types, nullable, defaults, variadic).
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
     * Roadmap (see docs/TECHNICAL_PLAN.md):
     *   - Stage 2: reflection-based constructor autowiring.
     *   - Stage 3: interface-aware circular dependency detection.
     *   - Stage 4: honor singleton vs transient lifecycle.
     *   - Stage 5: handle union/nullable/default/variadic constructor params.
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

        $reflection = new \ReflectionClass($concrete);

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

        $constructor = $reflection->getConstructor();

        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new NotInstantiableException(
                $concrete,
                'Constructor requires parameters, but autowiring is not available yet (stage 2).',
            );
        }

        return $reflection->newInstance();
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
