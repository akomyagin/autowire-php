<?php

declare(strict_types=1);

namespace AutowirePHP;

use AutowirePHP\Attribute\Inject;
use AutowirePHP\Attribute\Singleton;
use AutowirePHP\Cache\PlanCache;
use AutowirePHP\Compiled\CompiledPlan;
use AutowirePHP\Compiled\PlanCompiler;
use AutowirePHP\Compiled\PlanExecutor;
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
use Error;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
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
 *
 * Passing a PSR-6 pool or PSR-16 cache as the third constructor argument
 * enables the compiled mode: the graph is walked with reflection once, the
 * resulting resolution plan is stored in the cache, and subsequent get() calls
 * build objects by interpreting the plan without re-walking the graph through
 * reflection (deferred default values are the one deliberate exception — the
 * plan carries parameter references and the executor evaluates them at the
 * same moment the runtime path would).
 * The compiled path replaces exactly one section of the mechanics — the
 * instantiate() -> build() -> resolveParameter() chain — and recurses through
 * this very get(), so everything observable (exceptions, events, log records,
 * depth accounting, shared instances) is produced by the same code as the
 * runtime path. The only additions are debug records about the plan cache
 * itself. Plans are invalidated by a hash of the bindings/shared maps and a
 * manifest of the class files that took part in compilation.
 *
 * lazy() hands out a native PHP 8.4 lazy proxy instead of a built object:
 * the whole resolution — reflection, constructor, child get() calls, and with
 * them every event and log record of the service — is deferred into one
 * ordinary get() frame that runs at the first real access to the proxy. Eager
 * resolution stays the default and is untouched: get() never returns a proxy.
 * See lazy() for the exact contract of the shifted observability.
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
     * Shadow stack of deferred initializations in flight: ids whose lazy
     * proxy factory is currently executing, mapped to the concrete class
     * chosen for them at lazy() time.
     *
     * A separate axis from the resolution stack: that one tracks the
     * synchronous resolution path (and inside the proxy factory the ordinary
     * get() still pushes onto it, so an A -> B -> A cycle within a deferred
     * frame is caught by enter() as usual), while this map tracks which
     * proxies are mid-initialization. It is what lets a touch of an
     * initializing proxy be reported as a readable cycle instead of PHP's
     * bare uninitialized-property Error — see lazyReentrantTouchAsCycle().
     *
     * @var array<string, class-string>
     */
    private array $lazyInitializing = [];

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
     * Plan cache adapter; null means the compiled mode is off and the
     * container resolves through runtime reflection only.
     */
    private readonly ?PlanCache $planCache;

    /**
     * Interpreter for compiled plan nodes; created together with the cache.
     */
    private readonly ?PlanExecutor $planExecutor;

    /**
     * In-memory merge of the node maps of every plan loaded or compiled so
     * far, keyed by id. A get() for an id already present here never goes back
     * to the cache. Flushed by bind()/singleton() — see flushPlans().
     *
     * @var array<string, array<string, mixed>>
     */
    private array $planNodes = [];

    /**
     * Ids whose plan preparation already failed once in this process, so that
     * it is attempted once per id rather than on every top-level get().
     *
     * Without this, a failing compilation makes the compiled mode slower than
     * the runtime mode it replaces: the id never gets a node, so the entry
     * condition in get() sends every subsequent call through a full eager
     * reflection walk of the graph before degrading again. The failures worth
     * retrying — a broken cache driver — are already absorbed inside PlanCache;
     * what reaches preparePlan() is a property of the code, and the code cannot
     * change under a running process. Flushed by bind()/singleton() together
     * with the plans, because a changed configuration compiles a different graph.
     *
     * @var array<string, true>
     */
    private array $unplannable = [];

    /**
     * Memoised hash of the bindings/shared maps; invalidated on every
     * registration together with the loaded plans.
     */
    private ?string $configHash = null;

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
     * even the event object is allocated. All three parameters are optional
     * and each was added last in its turn, so `new Container()`,
     * `new Container($logger)` and `new Container($logger, $dispatcher)` keep
     * working unchanged.
     *
     * The cache parameter is a union of both PSR cache flavours on purpose:
     * two separate nullable parameters would create the meaningless "both
     * passed" state. Passing a cache is the only trigger of the compiled mode
     * — no extra flag. The `= null` default is mandatory for the same
     * self-autowiring reason as with the logger: the union type both contains
     * null and resolves to the default after every member fails, but the
     * default must exist for that fallback to be reachable. Guarded by
     * testContainerStillAutowiresItselfAndItsDependents, which has caught this
     * exact mistake twice.
     */
    public function __construct(
        ?LoggerInterface $logger = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        CacheItemPoolInterface|CacheInterface|null $cache = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->planCache = $cache === null ? null : new PlanCache($cache, $this->logger);
        $this->planExecutor = $cache === null
            ? null
            : new PlanExecutor($this, $this->logger, fn (): int => $this->depth());
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
        $this->flushPlans();
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

        if ($concrete === null) {
            $this->flushPlans();
        }
    }

    /**
     * Drop every plan loaded into memory and the memoised config hash.
     *
     * Registrations must do this: a plan compiled before a singleton() call
     * would keep living in the process with a stale sharedReason. The cache
     * key of the next compilation changes anyway (the hash covers both maps),
     * but nothing would notice in memory. $this->instances is deliberately NOT
     * flushed — bind() never dropped resolved instances and must not start to.
     */
    private function flushPlans(): void
    {
        $this->planNodes = [];
        $this->unplannable = [];
        $this->configHash = null;
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
            // In compiled mode, make sure a plan node for this id sits in
            // memory before the frame does any work: load it from the cache or
            // compile it now. Placed inside the try so that even an unexpected
            // failure here keeps the event pairing intact.
            if (
                $this->planCache !== null
                && !isset($this->planNodes[$id])
                && !isset($this->unplannable[$id])
            ) {
                $this->preparePlan($id);
            }

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

                // The compiled path replaces exactly the instantiate() call
                // below and nothing else; a missing node (compiled mode off,
                // or plan preparation failed) means the runtime path.
                $node = $this->planNodes[$id] ?? null;

                if ($concrete !== $id) {
                    $this->enter($concrete);

                    try {
                        $object = $node !== null
                            ? $this->planExecutor->execute($node)
                            : $this->instantiate($concrete);
                    } finally {
                        $this->leave($concrete);
                    }
                } else {
                    $object = $node !== null
                        ? $this->planExecutor->execute($node)
                        : $this->instantiate($concrete);
                }

                // On the compiled path the shared reason was computed by the
                // compiler with the same priority; taking it from the node is
                // what spares the second ReflectionClass of the runtime path.
                $sharedReason = $node !== null
                    ? ($node['shared'] ?? null)
                    : $this->sharedReason($id, $concrete);

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
            // Touching a lazy proxy that is already mid-initialization
            // surfaces as a bare PHP Error, not as a re-entered factory (see
            // lazyReentrantTouchAsCycle()); rewrite it into the container's
            // cycle exception before the frame reports its failure. A no-op
            // unless a deferred initialization is actually in flight.
            $exception = $this->lazyReentrantTouchAsCycle($exception, $id);

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
     * Hand out a lazy proxy for the given id: an object that is returned
     * immediately, without building the service or its dependency graph, and
     * is indistinguishable from the real service for the consumer —
     * instanceof holds for the concrete class and for its interfaces, no
     * explicit unwrapping exists. Built on PHP 8.4 native lazy objects
     * (ReflectionClass::newLazyProxy()).
     *
     * Resolution is deferred: the real object is built at the first access
     * to any property or method of the returned proxy, as one ordinary
     * deferred get() frame for the concrete class chosen here. Exceptions of
     * CONSTRUCTION (a failing constructor, an unresolvable deep dependency)
     * therefore fly not out of lazy() but out of that first access — possibly
     * far from the lazy() call site — unwrapped, exactly as get() would have
     * thrown them. Errors of CLASS CHOICE (a missing type, an interface
     * without a binding, a non-instantiable type) are thrown immediately from
     * lazy() itself:
     *
     *     $svc = $container->lazy(Heavy::class); // does not throw, even if
     *                                            // the Heavy constructor fails
     *     $svc->run(); // the constructor exception surfaces here
     *
     * Observability is shifted with the resolution, as part of this contract:
     * for a service obtained through lazy(), ResolutionRequested and its
     * terminal event are dispatched not when lazy() is called but at the
     * first real access to the proxy — deferred, outside the lazy() frame.
     * If the proxy is never touched, none of the resolution events for this
     * service is dispatched at all. The "exactly one terminal event per
     * Requested" pairing holds within the deferred frame; only the moment of
     * that frame is shifted relative to the lazy() call. The single record
     * lazy() itself produces is a debug record of the deferral — a record of
     * the fact of deferring, not of resolving.
     *
     * The concrete class is chosen here, immediately — reading the bindings
     * map is configuration, not construction — and the choice is fixed: the
     * deferred frame requests that very class even if the binding is
     * re-registered before the first access. The deferred frame is therefore
     * get($concrete), and the existing rule that the shared cache is keyed
     * by the requested id applies to that request. A direct lazy($abstract)
     * has no consumer frame, exactly like a direct get($abstract).
     *
     * A touch of the proxy while its own factory is still running closes a
     * cycle and is reported as CircularDependencyException — see
     * lazyReentrantTouchAsCycle(). Classes with readonly promoted properties
     * need no special handling: the deferred frame runs their ordinary
     * constructor. PHP itself refuses to make internal classes lazy; that
     * refusal surfaces immediately as NotInstantiableException.
     *
     * In one narrow case CircularDependencyException can be a false positive:
     * if the lazily-built class's own constructor reads one of its typed
     * properties before assigning it (an ordinary read-before-write bug, no
     * real cycle involved), PHP raises the same "must not be accessed before
     * initialization" Error that a genuine re-entrant touch produces, and this
     * method cannot tell the two apart from the message alone. Code that
     * specifically catches CircularDependencyException (rather than the
     * broader ContainerException) from a lazy() proxy and finds no real cycle
     * should inspect getPrevious() for the original engine Error.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     *
     * @throws NotFoundException when the id is neither a binding nor an existing type.
     * @throws NotInstantiableException when the chosen concrete class cannot be
     *         instantiated or does not support lazy initialization.
     */
    public function lazy(string $id): object
    {
        $concrete = $this->bindings[$id] ?? $id;

        if (!class_exists($concrete) && !interface_exists($concrete)) {
            throw new NotFoundException($concrete);
        }

        $reflection = $this->reflectInstantiable($concrete);

        $this->logger->debug(
            'Deferring resolution of {id} behind a lazy proxy',
            ['id' => $id, 'concrete' => $concrete, 'depth' => $this->depth()],
        );

        try {
            return $reflection->newLazyProxy(function () use ($id, $concrete): object {
                // The uninitialized pre-instance PHP passes to the factory is
                // ignored on purpose: the real object is built by the ordinary
                // get() below and the proxy delegates to it.
                //
                // This guard fires when a second proxy of the same id enters
                // its factory while this one is still initializing. A touch of
                // the *same* proxy never re-enters the factory — PHP throws a
                // bare Error at the touch point instead (verified by probe
                // under 8.4.24) and is translated by lazyReentrantTouchAsCycle().
                if (isset($this->lazyInitializing[$id])) {
                    throw new CircularDependencyException([...$this->resolutionChain, $id]);
                }

                $this->lazyInitializing[$id] = $concrete;

                // The finally mirrors enter()/leave(): without it one failed
                // deferred initialization would leave the id marked forever.
                try {
                    return $this->get($concrete);
                } finally {
                    unset($this->lazyInitializing[$id]);
                }
            });
        } catch (Error $failure) {
            // Creation-time refusal only (e.g. "Cannot make instance of
            // internal class lazy"): the factory has not run, nothing was
            // deferred, so failing fast with the container's own exception is
            // more diagnosable than an engine Error out of a config call.
            throw new NotInstantiableException(
                $concrete,
                'It does not support lazy initialization.',
                $failure,
            );
        }
    }

    /**
     * Translate the Error produced by touching a lazy proxy that is already
     * being initialized into the container's cycle exception.
     *
     * PHP does not re-enter the proxy factory when the same proxy is touched
     * during its own initialization — it throws a bare "Typed property X::$y
     * must not be accessed before initialization" Error at the touch point
     * (verified by probe under PHP 8.4.24). The factory can therefore never
     * catch this re-entrancy itself; instead the Error is intercepted here,
     * on the innermost get() frame it crosses, where the resolution path is
     * still intact enough to yield a readable chain. The original Error stays
     * attached as the previous exception.
     *
     * The rewrite is gated on a deferred initialization actually being in
     * flight AND on the property's declaring class matching the concrete
     * class of one of them, so an uninitialized-property Error outside the
     * lazy path is never rewritten. (A constructor of the lazily built class
     * reading its own typed property before assigning it would match too;
     * even then the attached Error keeps the failure diagnosable.)
     *
     * The frame id is appended before the lazy id because the frame's own
     * leave() already ran by the time the catch sees the Error: re-appending
     * it restores the path the chain would have shown at the touch.
     */
    private function lazyReentrantTouchAsCycle(Throwable $exception, string $frameId): Throwable
    {
        if ($this->lazyInitializing === [] || !$exception instanceof Error) {
            return $exception;
        }

        $matched = preg_match(
            '/^Typed property ([^:]+)::\$\S+ must not be accessed before initialization$/',
            $exception->getMessage(),
            $matches,
        );

        if ($matched !== 1) {
            return $exception;
        }

        foreach ($this->lazyInitializing as $lazyId => $concrete) {
            if ($concrete === $matches[1] || is_a($concrete, $matches[1], true)) {
                return new CircularDependencyException(
                    [...$this->resolutionChain, $frameId, $lazyId],
                    $exception,
                );
            }
        }

        return $exception;
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
     * Load the plan for a top-level id from the cache, or compile and store
     * it. Runs once per unknown id: the node maps of every loaded plan merge
     * in memory, so a later get() of any id reachable from an earlier root
     * never comes back here.
     *
     * Every outcome is silent towards the caller — a discarded payload, a
     * stale manifest or a broken driver only produce debug records and a
     * recompilation. Compilation itself instantiates nothing, dispatches no
     * events and does not touch the resolution stack.
     *
     * The whole body sits under catch (Throwable) because the driver is not
     * the only layer that can fail: compilation may hit a throwing autoloader
     * or a malformed attribute, and validation triggers autoloading through
     * class_exists(). "Discard and recompile — always; throw — never" must
     * hold for all of them, so any failure here leaves the id without a node
     * and the frame degrades to the runtime reflection path. The id is also
     * written off into $unplannable, so the degradation costs one attempt per
     * id rather than one per get() — see that property.
     */
    private function preparePlan(string $id): void
    {
        try {
            $hash = $this->configHash();
            $key = PlanCache::keyFor($hash, $id);
            $payload = $this->planCache->fetch($key);

            if ($payload !== null) {
                $plan = CompiledPlan::fromPayload($payload);

                if ($plan === null || $plan->configHash !== $hash || $plan->root !== $id) {
                    $this->logger->debug(
                        'Discarded compiled plan for {id}: {reason}',
                        [
                            'id' => $id,
                            'reason' => 'the payload is malformed, carries a foreign format stamp'
                                . ' or belongs to another configuration',
                        ],
                    );
                } elseif (($staleness = $plan->staleness()) !== null) {
                    $this->logger->debug(
                        'Discarded compiled plan for {id}: {reason}',
                        ['id' => $id, 'reason' => $staleness],
                    );
                } else {
                    // array_merge(), not +=: a node just validated as fresh
                    // must win over one an earlier plan already left in memory
                    // for the same shared id, not lose to it. No observable
                    // regression today (nodes for one id under one configHash
                    // are identical either way), but += encodes the opposite
                    // of that intent.
                    $this->planNodes = array_merge($this->planNodes, $plan->nodes);

                    $this->logger->debug(
                        'Loaded compiled plan for {id} from cache',
                        ['id' => $id, 'key' => $key],
                    );

                    return;
                }
            }

            $plan = (new PlanCompiler($this->bindings, $this->shared))->compile($id, $hash);

            // See the array_merge() note above — same reasoning for a
            // freshly compiled plan's nodes.
            $this->planNodes = array_merge($this->planNodes, $plan->nodes);

            $this->logger->debug(
                'Compiled resolution plan for {id}',
                ['id' => $id, 'key' => $key],
            );

            $this->planCache->store($key, $plan->toPayload());
        } catch (Throwable $failure) {
            // Written off before the record: the id is unplannable because the
            // preparation failed, not because the failure was logged.
            $this->unplannable[$id] = true;

            $this->logger->debug(
                'Failed to prepare a compiled plan for {id}: {error}',
                ['id' => $id, 'error' => $failure->getMessage(), 'exception' => $failure],
            );
        }
    }

    /**
     * Hash of the current bindings/shared maps, memoised until the next
     * registration. Participates in every cache key, so changing a binding or
     * registering a singleton can never reuse a plan compiled without it.
     */
    private function configHash(): string
    {
        return $this->configHash ??= PlanCache::configHash($this->bindings, $this->shared);
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
        return $this->build($this->reflectInstantiable($concrete));
    }

    /**
     * Reflect a concrete class, rejecting types that cannot be instantiated.
     * Shared by the eager path above and by lazy(), which must fail fast on
     * a class its deferred frame could never build.
     *
     * @throws NotInstantiableException when the type cannot be instantiated.
     */
    private function reflectInstantiable(string $concrete): ReflectionClass
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

        return $reflection;
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
     * This branch order has a second implementor: PlanCompiler replays it when
     * classifying parameters for a compiled plan. Any change here must be
     * mirrored there — the parity harness in CompiledContainerTest guards it.
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
