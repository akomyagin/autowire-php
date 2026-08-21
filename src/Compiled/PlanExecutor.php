<?php

declare(strict_types=1);

namespace AutowirePHP\Compiled;

use AutowirePHP\Container;
use AutowirePHP\Exception\CircularDependencyException;
use AutowirePHP\Exception\ContainerException;
use Closure;
use Psr\Log\LoggerInterface;
use ReflectionParameter;
use Throwable;

/**
 * Builds an object from a compiled plan node without re-walking the graph
 * through reflection. Every child id is resolved through the container's
 * public get(), never by walking the plan tree internally: that keeps depth
 * accounting (including the depth + 2 children of a bound frame), cycle
 * detection, the log records and the event stream structurally identical to
 * the runtime path instead of carefully re-implemented.
 *
 * The one deliberate use of the Reflection API left on this path is the
 * deferred default value: a `default` spec carries a parameter reference, and
 * defaultValue() evaluates it here, at the exact point in control flow where
 * the runtime path would call getDefaultValue() — see that method for why.
 *
 * The single piece of observability replayed by hand is log point 4 (the
 * #[Inject] record), because on the runtime path it lives inside
 * resolveParameter() — the spec carries its data for that reason alone.
 *
 * @internal Not part of the public API; Container is the only entry point.
 */
final class PlanExecutor
{
    /**
     * Memoised default values, keyed by "class#position". Only results that
     * pass CompiledPlan::isPlanValue() land here — see defaultValue().
     *
     * Never flushed: a default expression cannot change within a process
     * (classes are immutable once loaded), and bindings do not affect it.
     *
     * @var array<string, mixed>
     */
    private array $defaults = [];

    /**
     * @param Closure(): int $depth reports the container's current resolution depth,
     *        so the replayed #[Inject] record carries the same depth as the runtime one
     */
    public function __construct(
        private readonly Container $container,
        private readonly LoggerInterface $logger,
        private readonly Closure $depth,
    ) {
    }

    /**
     * @param array<string, mixed> $node a build or fail node
     */
    public function execute(array $node): object
    {
        if ($node['kind'] === CompiledPlan::NODE_FAIL) {
            throw self::exception($node['exception']);
        }

        $args = [];

        foreach ($node['args'] as $spec) {
            $args[] = $this->argument($spec);
        }

        $concrete = $node['concrete'];

        return new $concrete(...$args);
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function argument(array $spec): mixed
    {
        switch ($spec['kind']) {
            case CompiledPlan::SPEC_SERVICE:
                $inject = $spec['inject'];

                if ($inject !== null) {
                    // Log point 4, replayed verbatim: message, context keys and
                    // their order match resolveParameter() exactly.
                    $this->logger->debug(
                        'Inject attribute applied to parameter ${parameter} of {class}: resolving as {target}',
                        [
                            'parameter' => $inject['parameter'],
                            'class' => $inject['class'],
                            'target' => $inject['target'],
                            'depth' => ($this->depth)(),
                        ],
                    );
                }

                return $this->container->get($spec['id']);

            case CompiledPlan::SPEC_PROBE:
                // Same try/catch as the runtime nullable branch: the probe is
                // executed, not its precomputed outcome — the child's events
                // must fire and a constructor may fail only at runtime. The
                // fallback is itself a spec (null or deferred default), so a
                // defaulted probe evaluates its default only here, after the
                // failure — exactly like the runtime catch block.
                try {
                    return $this->container->get($spec['id']);
                } catch (CircularDependencyException $exception) {
                    throw $exception;
                } catch (ContainerException) {
                    return $this->argument($spec['fallback']);
                }

                // no break: both branches above return or throw.
            case CompiledPlan::SPEC_PROBE_ANY:
                foreach ($spec['ids'] as $memberId) {
                    try {
                        return $this->container->get($memberId);
                    } catch (CircularDependencyException $exception) {
                        throw $exception;
                    } catch (ContainerException) {
                        continue;
                    }
                }

                return $this->argument($spec['terminal']);

            case CompiledPlan::SPEC_DEFAULT:
                return $this->defaultValue($spec);

            case CompiledPlan::SPEC_NULL:
                return null;

            default:
                throw self::exception($spec['exception']);
        }
    }

    /**
     * Evaluate a deferred default value at the moment the runtime path would.
     *
     * getDefaultValue() executes the initializer expression, so evaluating it
     * during compilation would instantiate user code and evaluate defaults the
     * runtime path never touches (a successful probe skips its fallback). The
     * spec therefore carries only a reference; the ReflectionParameter is
     * rebuilt from the concrete class — the same base the runtime path
     * reflects from, so inherited constructors and self:: scoping behave
     * identically.
     *
     * A payload that survived validation but references a parameter that does
     * not exist (a poisoned cache, or a file edited without its mtime or size
     * changing) makes this throw ReflectionException. That is deliberately left
     * unwrapped: a ContainerException here would be swallowed by an enclosing
     * nullable probe and a broken cache would masquerade as an ordinary
     * resolution failure.
     *
     * The first evaluation is memoised only when the result is referentially
     * transparent (null, scalars, enum cases, arrays thereof): re-evaluating a
     * constant expression with such a result cannot observably differ within a
     * process. An object result — a new-in-initializer — is never memoised:
     * the runtime path constructs a fresh instance per build, and a throwing
     * initializer must throw on every build, at this very point.
     *
     * @param array<string, mixed> $spec
     */
    private function defaultValue(array $spec): mixed
    {
        $key = $spec['class'] . '#' . $spec['position'];

        if (array_key_exists($key, $this->defaults)) {
            return $this->defaults[$key];
        }

        $value = (new ReflectionParameter([$spec['class'], '__construct'], $spec['position']))
            ->getDefaultValue();

        if (CompiledPlan::isPlanValue($value)) {
            $this->defaults[$key] = $value;
        }

        return $value;
    }

    /**
     * Reconstruct a recorded exception and hand it over for throwing. Only the
     * three structural exceptions ever appear here, and their constructors take
     * strings only; the reason strings were computed by the compiler with the
     * same code, so the message is character-for-character the runtime one.
     *
     * @param array{class: class-string<Throwable>, args: list<string>} $description
     */
    private static function exception(array $description): Throwable
    {
        $class = $description['class'];

        return new $class(...$description['args']);
    }
}
