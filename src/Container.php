<?php

declare(strict_types=1);

namespace Akomyagin\AutowirePHP;

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
        // TODO(Stage 1): validate that $concrete is instantiable and store binding.
        $this->bindings[$abstract] = $concrete;
    }

    /**
     * Resolve an instance for the given class-string id.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        // TODO(Stage 1): resolve explicit bindings, then instantiate.
        // TODO(Stage 2): reflection-based constructor autowiring.
        // TODO(Stage 3): interface-aware circular dependency detection.
        // TODO(Stage 4): honor singleton vs transient lifecycle.
        // TODO(Stage 5): handle union/nullable/default/variadic constructor params.
        throw new \LogicException('Container::get() is not implemented yet (see docs/TECHNICAL_PLAN.md).');
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
