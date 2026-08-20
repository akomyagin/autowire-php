<?php

declare(strict_types=1);

namespace AutowirePHP;

use AutowirePHP\Attribute\Inject;
use AutowirePHP\Attribute\Singleton;
use AutowirePHP\Event\ContainerEvent;
use AutowirePHP\Event\ResolutionFailed;
use AutowirePHP\Event\ResolutionRequested;
use AutowirePHP\Event\ServiceResolved;
use AutowirePHP\Exception\CircularDependencyException;
use AutowirePHP\Exception\ContainerException;
use AutowirePHP\Exception\ListenerException;
use AutowirePHP\Exception\NotFoundException;
use AutowirePHP\Exception\NotInstantiableException;
use AutowirePHP\Exception\UnresolvableParameterException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use Throwable;

/**
 * Framework-agnostic dependency injection container.
 *
 * Resolves object graphs through the PHP Reflection API.
 * Implements PSR-11 `ContainerInterface`.
 *
 * Resolution steps are reported to an optional PSR-3 logger at `debug` level.
 * Failures are not logged separately: they are already reported by throwing,
 * so logging them again would only duplicate noise.
 *
 * Context arrays are built unconditionally, without guarding on whether the
 * logger discards them. The cost is one array per resolution step and nothing
 * else — messages are literals, no sprintf, no Reflection, and the only
 * implode() sits on the path to a throw — so the guard would buy less than it
 * costs in readability for a library whose point is legible mechanics.
 *
 * The resolution lifecycle is additionally observable through an optional
 * PSR-14 event dispatcher: every get() frame dispatches a ResolutionRequested
 * and then exactly one terminal ServiceResolved or ResolutionFailed with the
 * same id and depth. The events are read-only — a listener can watch resolution
 * but cannot substitute an instance, touch the shared instance cache or affect
 * cycle detection.
 *
 * A listener that throws never rewrites the outcome of resolution. On the
 * success path its failure surfaces as ListenerException, which deliberately
 * carries no ContainerException marker so that no enclosing parameter probe can
 * absorb it. On the failure path the original resolution exception wins and
 * reaches the caller unchanged, while the listener failure is reported to the
 * log — otherwise a broken listener could downgrade a circular dependency into
 * a silently null dependency.
 *
 * Re-entrancy: ResolutionRequested is dispatched before enter() and the
 * terminal events after leave(), so a listener of a top-level frame may call
 * get() for the same id again. Nested frames are different: the ids of the
 * enclosing frames are still on the resolution path, so requesting one of them
 * from a listener raises CircularDependencyException. Re-entering the container
 * from a listener is therefore not supported, and the container does not guard
 * against it beyond the ordinary cycle detection.
 */
final class Container implements ContainerInterface
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

    private readonly LoggerInterface $logger;

    /**
     * Without a logger the container behaves exactly as it did before logging
     * existed: NullLogger discards every record and nothing else observes them.
     *
     * The parameter is nullable rather than defaulting to `new NullLogger()`
     * so that the container keeps autowiring itself. A non-nullable class-typed
     * parameter is resolved through get(), which would fail on the unbindable
     * LoggerInterface before any default value is considered; a nullable one
     * falls back to null, and null is normalised to NullLogger here.
     *
     * The dispatcher is nullable for exactly the same reason — a non-nullable
     * EventDispatcherInterface parameter would be sent through get() and fail
     * on that unbindable interface. Unlike the logger it is not normalised:
     * PSR-14 ships no null implementation, and `$this->dispatcher?->dispatch(
     * new ...)` short-circuits the whole expression, so with no dispatcher not
     * even the event object is allocated. Both parameters are optional and the
     * dispatcher was added last, so `new Container()` and
     * `new Container($logger)` keep working unchanged.
     */
    public function __construct(
        ?LoggerInterface $logger = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Register an explicit binding from an abstract id (usually an interface)
     * to a concrete implementation class. This takes precedence over any
     * #[Inject] attribute declared on a constructor parameter of that type.
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
     * yields a separate, non-shared instance. A class marked #[Singleton]
     * behaves as if this method had been called for it; calling both has no
     * additional effect.
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
     * If the id was registered via singleton() (or the resolved concrete
     * class is marked #[Singleton]) and has already been resolved, the
     * cached instance is returned without rebuilding the object graph.
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
        // Captured before enter() pushes $id, so every record of this call
        // reports the nesting level of $id itself rather than the size of the
        // resolution path at the moment the record happened to be written.
        $depth = $this->depth();

        $this->logger->debug('Resolving {id}', ['id' => $id, 'depth' => $depth]);

        // Dispatched before the cache check so that every request is observed:
        // otherwise request counts would lie and the pairing with a terminal
        // event would not hold for cache hits.
        $this->dispatch(new ResolutionRequested($id, $depth));

        if (isset($this->instances[$id])) {
            $this->logger->debug(
                'Returning shared instance for {id} from cache',
                ['id' => $id, 'depth' => $depth],
            );

            $this->dispatch(
                new ServiceResolved($id, $depth, $this->instances[$id], fromCache: true),
            );

            return $this->instances[$id];
        }

        // enter() sits inside the try so that a cycle closing on $id itself is
        // reported as a failure of this frame too; without it the pairing of
        // ResolutionRequested with a terminal event would break. leave() is
        // still guaranteed by the inner finally and the exception still leaves
        // the frame as the same instance.
        try {
            $this->enter($id);

            try {
                $concrete = $this->bindings[$id] ?? $id;

                // Logged before the existence check on purpose: when a binding
                // points at a missing class, seeing which binding was applied is
                // exactly what makes the following NotFoundException diagnosable.
                if (isset($this->bindings[$id])) {
                    $this->logger->debug(
                        'Binding applied: {id} -> {concrete}',
                        ['id' => $id, 'concrete' => $concrete, 'depth' => $depth],
                    );
                }

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

                $sharedReason = $this->sharedReason($id, $concrete);

                if ($sharedReason !== null) {
                    $this->instances[$id] = $object;

                    $this->logger->debug(
                        'Caching shared instance for {id} (reason: {reason})',
                        ['id' => $id, 'reason' => $sharedReason, 'depth' => $depth],
                    );
                }
            } finally {
                $this->leave($id);
            }
        } catch (Throwable $exception) {
            $this->dispatchAfterFailure(new ResolutionFailed($id, $depth, $exception), $exception);

            throw $exception;
        }

        // Dispatched outside the try so that a listener failure does not produce
        // a spurious ResolutionFailed — terminal events describe the work of the
        // container, not the behaviour of listeners.
        $this->dispatch(new ServiceResolved($id, $depth, $object, fromCache: false));

        return $object;
    }

    /**
     * Dispatch an event, translating a listener failure into ListenerException.
     *
     * The translation is what keeps a broken listener from rewriting the outcome
     * of resolution. A listener throwing something that carries the local
     * ContainerException marker would otherwise be indistinguishable from a
     * genuine resolution failure, and an enclosing nullable or union parameter
     * probe would absorb it and substitute null — silently producing an object
     * graph with missing dependencies. ListenerException carries no such marker,
     * so it always reaches the caller.
     *
     * @throws ListenerException when a listener throws.
     */
    private function dispatch(ContainerEvent $event): void
    {
        if ($this->dispatcher === null) {
            return;
        }

        try {
            $this->dispatcher->dispatch($event);
        } catch (Throwable $listenerFailure) {
            throw new ListenerException($event, $listenerFailure);
        }
    }

    /**
     * Dispatch a terminal event on the failure path, where a resolution
     * exception is already in flight.
     *
     * Here the listener failure must not be thrown: the diagnosis of why
     * resolution failed is the more useful of the two, and callers — including
     * the container's own parameter probes — are entitled to receive the
     * original exception instance unchanged. The listener failure would be lost
     * entirely, so it goes to the log, which is the only channel left.
     */
    private function dispatchAfterFailure(ContainerEvent $event, Throwable $resolutionFailure): void
    {
        if ($this->dispatcher === null) {
            return;
        }

        try {
            $this->dispatcher->dispatch($event);
        } catch (Throwable $listenerFailure) {
            $this->logger->debug(
                'Listener of {event} threw {class} and was suppressed in favour of {failure}',
                [
                    'event' => $event::class,
                    'class' => $listenerFailure::class,
                    'failure' => $resolutionFailure::class,
                    'listenerFailure' => $listenerFailure,
                ],
            );
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

            $this->logger->debug(
                'Circular dependency detected: {chain}',
                ['id' => $id, 'chain' => implode(' -> ', $chain), 'depth' => $this->depth()],
            );

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
     * Zero-based nesting level at which the id being resolved sits, reported in
     * every log record so that nesting can be read off the log without parsing
     * the chain. A top-level get() reports 0, its dependencies 1, and so on.
     *
     * Called before the id is pushed onto the path, so the cycle record reports
     * the level the repeated id would have occupied; its chain, which already
     * includes that id, therefore holds `depth + 1` entries.
     */
    private function depth(): int
    {
        return count($this->resolutionChain);
    }

    /**
     * Why the given id should be cached, or null when it should not be:
     * either it was registered explicitly via singleton(), or the resolved
     * concrete class is marked #[Singleton]. When both apply, singleton()
     * is reported, mirroring the short-circuit order of the original check.
     *
     * @return 'singleton()'|'#[Singleton]'|null
     */
    private function sharedReason(string $id, string $concrete): ?string
    {
        if (isset($this->shared[$id])) {
            return 'singleton()';
        }

        if ($this->hasSingletonAttribute($concrete)) {
            return '#[Singleton]';
        }

        return null;
    }

    /**
     * Whether the concrete class declares #[Singleton].
     */
    private function hasSingletonAttribute(string $concrete): bool
    {
        if (!class_exists($concrete)) {
            return false;
        }

        return (new ReflectionClass($concrete))->getAttributes(Singleton::class) !== [];
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
            $injected = $this->resolveInjectTarget($param, $type->getName());
            if ($injected !== null) {
                $this->logger->debug(
                    // The attribute target may itself be an abstraction that the
                    // following get() re-resolves through bindings, so it is not
                    // the same notion as the `concrete` of a binding record.
                    'Inject attribute applied to parameter ${parameter} of {class}: resolving as {target}',
                    [
                        'parameter' => $param->getName(),
                        'class' => $declaringClass,
                        'target' => $injected,
                        'depth' => $this->depth(),
                    ],
                );

                return $this->get($injected);
            }

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
     * Returns the class-string a parameter must resolve to via #[Inject], or
     * null when no attribute applies or an explicit binding for the parameter
     * type takes precedence.
     */
    private function resolveInjectTarget(ReflectionParameter $param, string $typeName): ?string
    {
        if (isset($this->bindings[$typeName])) {
            return null;
        }

        $attributes = $param->getAttributes(Inject::class);

        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance()->concrete;
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
