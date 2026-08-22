<?php

declare(strict_types=1);

namespace AutowirePHP;

use AutowirePHP\Attribute\Inject;
use AutowirePHP\Attribute\Singleton;
use AutowirePHP\Attribute\Tagged;
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
use Closure;
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
use TypeError;

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
 * itself. Plans are invalidated by a hash of the bindings/shared maps and the
 * factory ids, and by a manifest of the class files that took part in
 * compilation.
 *
 * An id registered through factory() is resolved by a user closure instead of
 * reflection, inside the same get() frame and with the same observability;
 * factory ids are excluded from the compiled mode entirely, because a Closure
 * cannot be serialised into a plan.
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
     * Contextual bindings registered via when(): consumer class -> abstract
     * id -> concrete class to use for that consumer's constructor parameter.
     *
     * @var array<class-string, array<class-string, class-string>>
     */
    private array $contextual = [];

    /**
     * User factories, keyed by the id they resolve. A registered factory is
     * the most specific way to build an id: get() checks this map before the
     * compiled plan, the bindings and autowiring, so none of them apply to a
     * factory id. Only the keys of this map participate in the config hash —
     * a Closure cannot be serialised, and the factory body cannot change the
     * plan of any other id.
     *
     * @var array<class-string, Closure>
     */
    private array $factories = [];

    /**
     * Resolved shared instances, keyed by the id under which they were requested.
     *
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * Tag name -> ordered, deduplicated list of concrete classes registered
     * under it via tag(). Keyed by the tag, not by the class: the consuming
     * scenario is always "give me every member of tag T", so the map is read
     * in exactly this direction and never has to be inverted.
     *
     * @var array<string, list<class-string>>
     */
    private array $tags = [];

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
     * to the cache. Flushed by bind()/singleton()/when() — see flushPlans().
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
     * Register concrete classes under a named tag. A variadic constructor
     * parameter marked #[Tagged] with the same tag name receives one resolved
     * instance per member instead of the default empty set.
     *
     * Repeated calls for one tag accumulate, never replace, so independent
     * modules can append their handlers to a common tag. The collection order
     * is the registration order; a class registered twice enters the tag once,
     * at the position of its first occurrence.
     *
     * Every member resolves through an ordinary get(), so cycle detection,
     * shared semantics, events and log records apply per member — and a member
     * that cannot be built fails the whole resolution of the consumer instead
     * of being silently dropped: a registered member is a deliberate part of
     * the configuration, and its failure must surface, not shorten the
     * collection by one. A tag nobody registered yields an empty collection —
     * zero members is a legal state, not an error.
     *
     * This imperative call is deliberately the only registration path. A
     * declarative #[Tag] class attribute was considered and rejected (user
     * decision recorded in docs/POST_MVP_PLAN.md): PHP has no registry of all
     * application classes, so the attribute could only be discovered on
     * classes the container happens to reflect — membership would then depend
     * on resolution order and diverge between the runtime and compiled paths.
     *
     * @param list<class-string> $concretes
     */
    public function tag(string $tag, array $concretes): void
    {
        foreach ($concretes as $concrete) {
            if (!in_array($concrete, $this->tags[$tag] ?? [], true)) {
                $this->tags[$tag][] = $concrete;
            }
        }

        $this->flushPlans();
    }

    /**
     * Register a contextual binding: when $consumer is being built and its
     * constructor declares a parameter of type $abstract, resolve that
     * parameter as $concrete instead of whatever the global configuration
     * would produce.
     *
     * The consumer is the class being built — the same value error messages
     * and the #[Inject] log record report — NOT the class that declared the
     * constructor in an inheritance hierarchy. With `B extends A` and the
     * constructor declared in A, resolving B applies the context registered
     * for B, never the one registered for A. (The compilation manifest
     * deliberately looks at the declaring class instead — that is about which
     * files invalidate a cached plan, not about who the consumer is.)
     *
     * Parameter resolution priority: explicit bind() > contextual binding >
     * #[Inject] attribute > autowiring by type. The context wins over a
     * global bind() of the same abstract only inside the consumer's
     * constructor; a direct top-level get($abstract) has no consumer and goes
     * through bind()/autowiring unaffected.
     *
     * The redirect targets the chosen concrete class directly, so shared
     * semantics apply to that concrete id (singleton(Concrete) / #[Singleton]
     * on it). A singleton($abstract) on the interface is never shared across
     * contextual consumers: their resolutions bypass the $abstract cache key
     * entirely, each consumer builds its own concrete. This follows from the
     * instance cache being keyed by the requested id and is deliberate — the
     * redirect already segregates instances per concrete class correctly.
     *
     * Only single named-type parameters are checked: a union-typed parameter
     * ($abstract being one of its members) never consults this map, matching
     * how #[Inject] is already silently ignored on union parameters. This is
     * the same rule applied consistently, not an oversight — see
     * resolveUnionParameter()/compileUnionParameter().
     *
     * @param class-string $consumer
     * @param class-string $abstract
     * @param class-string $concrete
     */
    public function when(string $consumer, string $abstract, string $concrete): void
    {
        $this->contextual[$consumer][$abstract] = $concrete;
        $this->flushPlans();
    }

    /**
     * Register a user factory for an id: get($abstract) calls the closure
     * instead of walking the constructor, and hands it this container so the
     * factory can pull autowired dependencies through $c->get() and mix them
     * with values the container cannot infer — a DSN, a flag. The factory
     * takes precedence over bind(), #[Inject] and autowiring for the same id:
     * resolution never reaches them, so when both a factory and a binding are
     * registered for one id, the factory wins.
     *
     * The closure runs inside the ordinary get() frame, between the same
     * PSR-14 events and on the same resolution path, so cycle detection,
     * depth accounting, logging and the shared instance cache all apply
     * unchanged. Only singleton() can mark a factory id as shared:
     * #[Singleton] lives on a class, and which class the factory builds is
     * its own decision. An exception thrown by the factory propagates to the
     * caller unwrapped, exactly like an exception from a user constructor —
     * wrapping it in a ContainerException would let an enclosing nullable or
     * union probe absorb it and silently substitute null.
     *
     * A factory id never participates in the compiled mode: a Closure cannot
     * be serialised into a plan, so the id always resolves through the
     * runtime factory branch, and — like bind() — the registration flushes
     * plans already loaded and moves the config hash, so a plan compiled
     * before it can never be reused. Already resolved shared instances
     * survive the registration, as they survive bind().
     *
     * @param class-string $abstract
     * @param Closure(Container): object $factory
     */
    public function factory(string $abstract, Closure $factory): void
    {
        $this->factories[$abstract] = $factory;
        $this->flushPlans();
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
            // failure here keeps the event pairing intact. A factory id is
            // excluded up front: it is unplannable by definition (a Closure
            // cannot be serialised into a plan), so no compilation attempt is
            // spent on it and it resolves through the factory branch below.
            if (
                $this->planCache !== null
                && !isset($this->factories[$id])
                && !isset($this->planNodes[$id])
                && !isset($this->unplannable[$id])
            ) {
                $this->preparePlan($id);
            }

            $this->enter($id);

            try {
                if (isset($this->factories[$id])) {
                    $this->logger->debug(
                        'Factory invoked for {id}',
                        ['id' => $id, 'depth' => $depth],
                    );

                    // The factory takes the place of instantiate(): it runs
                    // inside this very frame, with $id already on the
                    // resolution path, so a nested $c->get() inherits depth
                    // and cycle detection, and a factory exception propagates
                    // unwrapped like a constructor's. No second enter(): the
                    // factory decides itself what to build, so $id is the
                    // only id this frame knows about.
                    $object = ($this->factories[$id])($this);

                    if (!is_object($object)) {
                        throw new TypeError(sprintf(
                            'Factory registered for "%s" must return an object, got %s.',
                            $id,
                            get_debug_type($object),
                        ));
                    }

                    // #[Singleton] lives on a class and the class a factory
                    // builds is its own decision, so of the two halves of
                    // sharedReason() only the singleton() registration can
                    // apply to a factory id.
                    $sharedReason = isset($this->shared[$id]) ? 'singleton()' : null;
                } else {
                    $concrete = $this->bindings[$id] ?? $id;

                    // Logged before the existence check on purpose: when a
                    // binding points at a missing class, seeing which binding
                    // was applied is exactly what makes the following
                    // NotFoundException diagnosable.
                    if (isset($this->bindings[$id])) {
                        $this->logger->debug(
                            'Binding applied: {id} -> {concrete}',
                            ['id' => $id, 'concrete' => $concrete, 'depth' => $depth],
                        );
                    }

                    if (!class_exists($concrete) && !interface_exists($concrete)) {
                        throw new NotFoundException($concrete);
                    }

                    // The compiled path replaces exactly the instantiate()
                    // call below and nothing else; a missing node (compiled
                    // mode off, or plan preparation failed) means the runtime
                    // path.
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

                    // On the compiled path the shared reason was computed by
                    // the compiler with the same priority; taking it from the
                    // node is what spares the second ReflectionClass of the
                    // runtime path.
                    $sharedReason = $node !== null
                        ? ($node['shared'] ?? null)
                        : $this->sharedReason($id, $concrete);
                }

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

            $plan = (new PlanCompiler($this->bindings, $this->shared, $this->contextual, $this->tags))
                ->compile($id, $hash);

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
     * Hash of the current bindings/shared/contextual/factories/tags
     * configuration, memoised until the next registration. Participates in
     * every cache key, so changing a binding, registering a singleton,
     * registering a contextual binding, registering a factory or extending a
     * tag can never reuse a plan compiled without it. Of the factories only
     * the keys are hashed: which ids bypass the plan is configuration, the
     * closure bodies are not.
     */
    private function configHash(): string
    {
        return $this->configHash ??= PlanCache::configHash(
            $this->bindings,
            $this->shared,
            $this->contextual,
            array_keys($this->factories),
            $this->tags,
        );
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
            // A variadic parameter is always the last one. Untagged it ends
            // the argument list and PHP fills it with an empty set; marked
            // #[Tagged] it appends the resolved tag members as trailing
            // positional arguments, which newInstanceArgs() spreads into the
            // variadic tail.
            if ($param->isVariadic()) {
                foreach ($this->resolveTaggedVariadic($param, $reflection->getName()) as $member) {
                    $args[] = $member;
                }

                break;
            }

            $args[] = $this->resolveParameter($param, $reflection->getName());
        }

        return $reflection->newInstanceArgs($args);
    }

    /**
     * Resolve the members for a #[Tagged] variadic parameter, or an empty
     * list when the parameter carries no attribute or the tag has no members.
     *
     * Each member goes through an ordinary get(), so a member that cannot be
     * built propagates its exception and fails the consumer — deliberately
     * unlike the nullable/union probes, whose catch is part of the parameter
     * contract; a tag member has no such contract, every member is mandatory.
     * A cycle closing through a member is caught by the untouched
     * enter()/leave() of the child frame.
     *
     * This branch has a second implementor: PlanCompiler bakes the same
     * member list into a tagged spec, and PlanExecutor replays this very log
     * record. Any change here must be mirrored there.
     *
     * @return list<object>
     */
    private function resolveTaggedVariadic(ReflectionParameter $param, string $declaringClass): array
    {
        $attributes = $param->getAttributes(Tagged::class);

        if ($attributes === []) {
            return [];
        }

        $tag = $attributes[0]->newInstance()->tag;
        $ids = $this->tags[$tag] ?? [];

        $this->logger->debug(
            'Tagged collection for parameter ${parameter} of {class}: resolving {count} members of tag {tag}',
            [
                'parameter' => $param->getName(),
                'class' => $declaringClass,
                'tag' => $tag,
                'count' => count($ids),
                'depth' => $this->depth(),
            ],
        );

        $members = [];

        foreach ($ids as $id) {
            $members[] = $this->get($id);
        }

        return $members;
    }

    /**
     * Resolve a single constructor parameter. Resolution priority: class type
     * through the container (contextual binding -> #[Inject] -> plain get) ->
     * union members in order -> default value -> null (if nullable) ->
     * UnresolvableParameterException.
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
            $contextual = $this->contextual[$declaringClass][$type->getName()] ?? null;
            if ($contextual !== null) {
                $this->logger->debug(
                    // Like the #[Inject] target below, the chosen class may
                    // itself be an abstraction that the following get()
                    // re-resolves through bindings.
                    'Contextual binding applied to parameter ${parameter} of {consumer}: resolving as {target}',
                    [
                        'parameter' => $param->getName(),
                        'consumer' => $declaringClass,
                        'target' => $contextual,
                        'depth' => $this->depth(),
                    ],
                );

                return $this->get($contextual);
            }

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
     * Neither contextual bindings nor #[Inject] are consulted here — both are
     * single-target mechanisms and a union parameter has no single target to
     * redirect. This mirrors the existing #[Inject]-on-union behaviour; see
     * Container::when().
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
