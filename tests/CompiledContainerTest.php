<?php

declare(strict_types=1);

namespace AutowirePHP\Tests;

use ArrayObject;
use AutowirePHP\Attribute\Inject;
use AutowirePHP\Attribute\Singleton;
use AutowirePHP\Cache\PlanCache;
use AutowirePHP\Compiled\CompiledPlan;
use AutowirePHP\Container;
use AutowirePHP\Event\ResolutionFailed;
use AutowirePHP\Event\ResolutionRequested;
use AutowirePHP\Event\ServiceResolved;
use AutowirePHP\Exception\CircularDependencyException;
use AutowirePHP\Exception\ListenerException;
use AutowirePHP\Exception\NotInstantiableException;
use AutowirePHP\Tests\Cache\FakeCachePool;
use AutowirePHP\Tests\Cache\FakeSimpleCache;
use AutowirePHP\Tests\Cache\ThrowingSimpleCache;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Psr\SimpleCache\CacheInterface;
use ReflectionObject;
use RuntimeException;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Compiled mode of the container: parity with the runtime-reflection path,
 * plan cache behaviour and plan invalidation.
 *
 * The core instrument is the parity harness: every scenario runs three times —
 * on a runtime container, on a compiled container with a cold cache (the run
 * that compiles) and on a fresh compiled container over the warmed cache (the
 * run that interprets a loaded plan) — and everything observable must be
 * identical: the event stream, the resolution log records, the final outcome.
 *
 * Fixtures are deliberately local to this file: fixtures of ContainerTest.php
 * are not PSR-4 discoverable and must not be relied upon.
 */
final class CompiledContainerTest extends TestCase
{
    /**
     * The seven resolution log records plus the listener-suppression record.
     * Everything outside this list is about the plan cache itself — the only
     * observability difference the compiled mode is allowed to introduce.
     */
    private const RESOLUTION_MESSAGES = [
        'Resolving {id}',
        'Returning shared instance for {id} from cache',
        'Binding applied: {id} -> {concrete}',
        'Inject attribute applied to parameter ${parameter} of {class}: resolving as {target}',
        'Contextual binding applied to parameter ${parameter} of {consumer}: resolving as {target}',
        'Caching shared instance for {id} (reason: {reason})',
        'Circular dependency detected: {chain}',
        'Listener of {event} threw {class} and was suppressed in favour of {failure}',
    ];

    // ---------------------------------------------------------------- parity

    /**
     * @return iterable<string, array{callable(Container): void, callable(Container): mixed}>
     */
    public static function provideParityScenarios(): iterable
    {
        $noConfig = static function (Container $container): void {
        };

        yield 'graph covering every argument spec kind' => [
            static function (Container $container): void {
                $container->bind(CpBoundInterface::class, CpBoundImpl::class);
                $container->singleton(CpSharedByMethod::class);
            },
            static function (Container $container): object {
                $container->get(CpParityRoot::class);

                return $container->get(CpParityRoot::class);
            },
        ];

        yield 'builtin parameter without default' => [
            $noConfig,
            static fn (Container $c): object => $c->get(CpNeedsBuiltin::class),
        ];

        yield 'untyped parameter without default' => [
            $noConfig,
            static fn (Container $c): object => $c->get(CpNeedsUntyped::class),
        ];

        yield 'missing class as root id' => [
            $noConfig,
            static fn (Container $c): object => $c->get('AutowirePHP\\Tests\\CpNoSuchClass'),
        ];

        yield 'missing class as dependency' => [
            $noConfig,
            static fn (Container $c): object => $c->get(CpNeedsMissing::class),
        ];

        yield 'interface without binding as root' => [
            $noConfig,
            static fn (Container $c): object => $c->get(CpUnboundInterface::class),
        ];

        yield 'abstract class' => [
            $noConfig,
            static fn (Container $c): object => $c->get(CpAbstract::class),
        ];

        yield 'private constructor' => [
            $noConfig,
            static fn (Container $c): object => $c->get(CpPrivateCtor::class),
        ];

        yield 'binding pointing at a missing class' => [
            static function (Container $container): void {
                $container->bind(CpBoundInterface::class, 'AutowirePHP\\Tests\\CpNoSuchImpl');
            },
            static fn (Container $c): object => $c->get(CpBoundInterface::class),
        ];

        yield 'union falling back to default' => [
            $noConfig,
            static fn (Container $c): object => $c->get(CpUnionWithDefault::class),
        ];

        yield 'nullable union falling back to null' => [
            $noConfig,
            static fn (Container $c): object => $c->get(CpUnionNullable::class),
        ];

        yield 'union with no resolvable member and no fallback' => [
            $noConfig,
            static fn (Container $c): object => $c->get(CpUnionFails::class),
        ];

        yield 'union resolving its first member' => [
            $noConfig,
            static fn (Container $c): object => $c->get(CpUnionFirst::class),
        ];

        yield 'inject attribute on a union parameter is ignored' => [
            $noConfig,
            static fn (Container $c): object => $c->get(CpInjectOnUnion::class),
        ];

        yield 'direct cycle' => [
            $noConfig,
            static fn (Container $c): object => $c->get(CpCycleA::class),
        ];

        yield 'cycle through a nullable parameter' => [
            static function (Container $container): void {
                $container->bind(CpCycleNullable::class, CpNullableCycleImpl::class);
            },
            static fn (Container $c): object => $c->get(CpCycleNullable::class),
        ];

        yield 'object default behind a failed probe' => [
            $noConfig,
            static fn (Container $c): object => $c->get(CpObjectDefault::class),
        ];

        yield 'throwing object default behind a successful nullable probe' => [
            $noConfig,
            static fn (Container $c): object => $c->get(CpProbeWithThrowingDefault::class),
        ];

        yield 'throwing object default behind a successful union member' => [
            $noConfig,
            static fn (Container $c): object => $c->get(CpUnionWithThrowingDefault::class),
        ];

        yield 'enum default behind a failed probe' => [
            $noConfig,
            static fn (Container $c): object => $c->get(CpNeedsEnum::class),
        ];

        yield 'internal class without a file' => [
            $noConfig,
            static fn (Container $c): object => $c->get(ArrayObject::class),
        ];
    }

    /**
     * @param callable(Container): void $configure
     * @param callable(Container): mixed $scenario
     */
    #[DataProvider('provideParityScenarios')]
    public function testCompiledModeIsObservationallyIdenticalToRuntimeMode(
        callable $configure,
        callable $scenario,
    ): void {
        $reference = self::observe(null, $configure, $scenario);

        $cache = new FakeSimpleCache();

        foreach (['cold compiled run', 'warm compiled run'] as $label) {
            $run = self::observe($cache, $configure, $scenario);

            self::assertSame($reference['events'], $run['events'], $label . ': event stream diverged');
            self::assertSame($reference['log'], $run['log'], $label . ': resolution log diverged');
            self::assertSame($reference['outcome'], $run['outcome'], $label . ': outcome diverged');
        }
    }

    public function testCompiledGraphResolvesTheExpectedStructure(): void
    {
        $cache = new FakeSimpleCache();

        foreach (['cold', 'warm'] as $label) {
            $container = new Container(null, null, $cache);
            $container->bind(CpBoundInterface::class, CpBoundImpl::class);
            $container->singleton(CpSharedByMethod::class);

            $root = $container->get(CpParityRoot::class);

            self::assertInstanceOf(CpParityRoot::class, $root, $label);
            self::assertInstanceOf(CpService::class, $root->plain, $label);
            self::assertInstanceOf(CpBoundImpl::class, $root->bound, $label);
            self::assertInstanceOf(CpGatewayB::class, $root->injected, $label);
            self::assertInstanceOf(CpService::class, $root->probeHit, $label);
            self::assertNull($root->probeMiss, $label);
            self::assertInstanceOf(CpUnionImpl::class, $root->union, $label);
            self::assertSame($container->get(CpSharedByMethod::class), $root->sharedByMethod, $label);
            self::assertSame($container->get(CpSharedByAttr::class), $root->sharedByAttr, $label);
            self::assertNull($root->note, $label);
            self::assertSame(42, $root->answer, $label);
            self::assertSame([], $root->variadic, $label);
        }
    }

    public function testInjectAttributeOnUnionParameterStaysIgnoredInCompiledMode(): void
    {
        $container = new Container(null, null, new FakeSimpleCache());

        $resolved = $container->get(CpInjectOnUnion::class);

        // Were the attribute honoured, the target CpGatewayA would have been
        // injected; the runtime path ignores #[Inject] outside the class-type
        // branch and the compiled path must reproduce that known behaviour.
        self::assertInstanceOf(CpUnionImpl::class, $resolved->v);
    }

    public function testBoundFrameGivesItsChildrenDepthPlusTwoInCompiledMode(): void
    {
        $cache = new FakeSimpleCache();

        foreach (['cold', 'warm'] as $label) {
            $events = new CpSpyDispatcher();
            $container = new Container(null, $events, $cache);
            $container->bind(CpBoundInterface::class, CpBoundImpl::class);

            $container->get(CpBoundInterface::class);

            $requested = array_map(
                static fn (ResolutionRequested $event): array => [$event->id, $event->depth],
                self::eventsOf($events, ResolutionRequested::class),
            );

            // The bound frame pushes two ids (abstract and concrete), so its
            // children sit at depth + 2, not depth + 1.
            self::assertSame(
                [[CpBoundInterface::class, 0], [CpLeaf::class, 2]],
                $requested,
                $label,
            );
        }
    }

    // ---------------------------------------------------------------- cycles

    public function testSameLoadedPlanReportsDifferentChainsFromDifferentRoots(): void
    {
        $cache = new FakeSimpleCache();
        $container = new Container(null, null, $cache);

        try {
            $container->get(CpCycleA::class);
            self::fail('Expected CircularDependencyException was not thrown.');
        } catch (CircularDependencyException $exception) {
            self::assertSame(
                [CpCycleA::class, CpCycleB::class, CpCycleA::class],
                $exception->getChain(),
            );
        }

        try {
            $container->get(CpCycleB::class);
            self::fail('Expected CircularDependencyException was not thrown.');
        } catch (CircularDependencyException $exception) {
            self::assertSame(
                [CpCycleB::class, CpCycleA::class, CpCycleB::class],
                $exception->getChain(),
            );
        }

        // Both roots were served by the node map compiled for the first one:
        // the chain is a property of the execution path, never of the plan.
        self::assertCount(1, $cache->writes);
    }

    public function testCompiledContainerStaysUsableAfterCaughtCycle(): void
    {
        $container = new Container(null, null, new FakeSimpleCache());

        try {
            $container->get(CpCycleA::class);
            self::fail('Expected CircularDependencyException was not thrown.');
        } catch (CircularDependencyException) {
            // Expected: what matters is the state afterwards.
        }

        self::assertInstanceOf(CpService::class, $container->get(CpService::class));

        $reflection = new ReflectionObject($container);

        $resolving = $reflection->getProperty('resolving');
        $resolutionChain = $reflection->getProperty('resolutionChain');

        self::assertSame([], $resolving->getValue($container));
        self::assertSame([], $resolutionChain->getValue($container));
    }

    // -------------------------------------------------- side effects & state

    public function testResolvedSingletonStaysCachedWhenALaterArgumentFails(): void
    {
        $configure = static function (Container $container): void {
            $container->singleton(CpSharedByMethod::class);
        };
        $scenario = static fn (Container $c): object => $c->get(CpSideEffect::class);

        $runtime = self::observe(null, $configure, $scenario);
        $compiled = self::observe(new FakeSimpleCache(), $configure, $scenario);

        self::assertSame($runtime['events'], $compiled['events']);
        self::assertSame($runtime['outcome'], $compiled['outcome']);
        self::assertNotNull($compiled['outcome']);

        // Arguments are resolved left to right, so the shared first argument
        // observably lands in the instance cache before the second one throws.
        foreach ([$runtime['container'], $compiled['container']] as $container) {
            $instances = (new ReflectionObject($container))->getProperty('instances');

            self::assertArrayHasKey(CpSharedByMethod::class, $instances->getValue($container));
        }
    }

    public function testSingletonAttributeThroughBindingCachesUnderRequestIdInCompiledMode(): void
    {
        $container = new Container(null, null, new FakeSimpleCache());
        $container->bind(CpAttrIface::class, CpSharedByAttr::class);

        $first = $container->get(CpAttrIface::class);
        $second = $container->get(CpAttrIface::class);
        $direct = $container->get(CpSharedByAttr::class);

        self::assertSame($first, $second);
        self::assertNotSame($first, $direct);
    }

    public function testBindAfterCompilationFlushesLoadedPlans(): void
    {
        $container = new Container(null, null, new FakeSimpleCache());
        $container->bind(CpGateway::class, CpGatewayA::class);

        self::assertInstanceOf(CpGatewayA::class, $container->get(CpGateway::class));

        $container->bind(CpGateway::class, CpGatewayB::class);

        // Without the flush the stale in-memory node would keep producing
        // CpGatewayA even though the cache key already changed.
        self::assertInstanceOf(CpGatewayB::class, $container->get(CpGateway::class));
    }

    public function testSingletonAfterCompilationFlushesLoadedPlans(): void
    {
        $container = new Container(null, null, new FakeSimpleCache());

        self::assertNotSame($container->get(CpLeaf::class), $container->get(CpLeaf::class));

        $container->singleton(CpLeaf::class);

        self::assertSame($container->get(CpLeaf::class), $container->get(CpLeaf::class));
    }

    // ------------------------------------------------------ contextual binding

    public function testCompiledContextualBindingMatchesRuntime(): void
    {
        $configure = static function (Container $container): void {
            $container->when(CpCtxConsumerOne::class, CpCtxContract::class, CpCtxImplA::class);
            $container->when(CpCtxConsumerTwo::class, CpCtxContract::class, CpCtxImplB::class);
        };
        $scenario = static fn (Container $c): array => [
            $c->get(CpCtxConsumerOne::class)->dep::class,
            $c->get(CpCtxConsumerTwo::class)->dep::class,
        ];

        $reference = self::observe(null, $configure, $scenario);

        self::assertSame([CpCtxImplA::class, CpCtxImplB::class], $reference['value']);

        $cache = new FakeSimpleCache();

        foreach (['cold compiled run', 'warm compiled run'] as $label) {
            $run = self::observe($cache, $configure, $scenario);

            self::assertSame($reference['events'], $run['events'], $label . ': event stream diverged');
            self::assertSame($reference['log'], $run['log'], $label . ': resolution log diverged');
            self::assertSame($reference['outcome'], $run['outcome'], $label . ': outcome diverged');
            self::assertSame($reference['value'], $run['value'], $label . ': structure diverged');
        }
    }

    public function testCompiledContextualPrioritiesMatchRuntime(): void
    {
        // Context vs the global bind(): wins for the consumer's parameter,
        // stays inapplicable on the top-level get() of the abstract.
        $bindConfig = static function (Container $container): void {
            $container->bind(CpCtxContract::class, CpCtxImplA::class);
            $container->when(CpCtxConsumerOne::class, CpCtxContract::class, CpCtxImplB::class);
        };
        $bindScenario = static fn (Container $c): array => [
            $c->get(CpCtxConsumerOne::class)->dep::class,
            $c->get(CpCtxContract::class)::class,
        ];

        // Context vs #[Inject]: wins on the annotated parameter, while the
        // attribute keeps winning for a consumer without a context.
        $injectConfig = static function (Container $container): void {
            $container->when(CpCtxInjectConsumer::class, CpCtxContract::class, CpCtxImplB::class);
        };
        $injectScenario = static fn (Container $c): array => [
            $c->get(CpCtxInjectConsumer::class)->dep::class,
            $c->get(CpCtxInjectLoner::class)->dep::class,
        ];

        $cases = [
            'context vs bind' => [$bindConfig, $bindScenario, [CpCtxImplB::class, CpCtxImplA::class]],
            'context vs inject' => [$injectConfig, $injectScenario, [CpCtxImplB::class, CpCtxImplA::class]],
        ];

        foreach ($cases as $case => [$configure, $scenario, $expected]) {
            $reference = self::observe(null, $configure, $scenario);

            self::assertSame($expected, $reference['value'], $case);

            $cache = new FakeSimpleCache();

            foreach (['cold', 'warm'] as $label) {
                $run = self::observe($cache, $configure, $scenario);

                self::assertSame($reference['events'], $run['events'], $case . ', ' . $label . ': events');
                self::assertSame($reference['log'], $run['log'], $case . ', ' . $label . ': log');
                self::assertSame($reference['outcome'], $run['outcome'], $case . ', ' . $label . ': outcome');
                self::assertSame($reference['value'], $run['value'], $case . ', ' . $label . ': structure');
            }
        }
    }

    public function testRegisteringContextualBindingInvalidatesCompiledPlan(): void
    {
        $cache = new FakeSimpleCache();
        $container = new Container(null, null, $cache);
        $container->bind(CpCtxContract::class, CpCtxImplA::class);

        self::assertInstanceOf(CpCtxImplA::class, $container->get(CpCtxConsumerOne::class)->dep);
        self::assertCount(1, $cache->writes);

        $container->when(CpCtxConsumerOne::class, CpCtxContract::class, CpCtxImplB::class);

        // The registration flushes the in-memory plans and the changed config
        // hash lands on a different cache key: the plan compiled without the
        // context is never reused, neither from memory nor from storage.
        self::assertInstanceOf(CpCtxImplB::class, $container->get(CpCtxConsumerOne::class)->dep);
        self::assertCount(2, $cache->writes);
        self::assertNotSame($cache->writes[0], $cache->writes[1]);
    }

    public function testCompiledContextualCycleMatchesRuntime(): void
    {
        $configure = static function (Container $container): void {
            $container->when(CpCtxCycleA::class, CpCtxContract::class, CpCtxCycleB::class);
            $container->when(CpCtxCycleB::class, CpCtxContract::class, CpCtxCycleA::class);
        };
        $scenario = static fn (Container $c): object => $c->get(CpCtxCycleA::class);

        $reference = self::observe(null, $configure, $scenario);

        self::assertNotNull($reference['outcome']);
        self::assertSame(CircularDependencyException::class, $reference['outcome'][0]);
        self::assertStringContainsString(
            CpCtxCycleA::class . ' -> ' . CpCtxCycleB::class . ' -> ' . CpCtxCycleA::class,
            $reference['outcome'][1],
        );

        $cache = new FakeSimpleCache();

        foreach (['cold compiled run', 'warm compiled run'] as $label) {
            $run = self::observe($cache, $configure, $scenario);

            self::assertSame($reference['events'], $run['events'], $label . ': event stream diverged');
            self::assertSame($reference['log'], $run['log'], $label . ': resolution log diverged');
            self::assertSame($reference['outcome'], $run['outcome'], $label . ': outcome diverged');
        }
    }

    public function testContextualFormatVersionBumpDiscardsOldPayload(): void
    {
        // The contextual feature bumped the format stamp 2 -> 3; a payload
        // still carrying the previous stamp must be silently recompiled even
        // if it somehow sits under the current cache key.
        self::assertSame(3, CompiledPlan::FORMAT_VERSION);

        $cache = new FakeSimpleCache();
        (new Container(null, null, $cache))->get(CpService::class);

        foreach ($cache->data as $key => $serialized) {
            $payload = unserialize($serialized);
            $payload['version'] = 2;
            $cache->data[$key] = serialize($payload);
        }

        $writesBefore = count($cache->writes);
        $logger = new CpSpyLogger();
        $container = new Container($logger, null, $cache);

        self::assertInstanceOf(CpService::class, $container->get(CpService::class));
        self::assertGreaterThan($writesBefore, count($cache->writes));
        self::assertNotSame([], self::recordsMatching($logger, 'Discarded compiled plan'));
    }

    // ------------------------------------------------- the cache as storage

    public function testSecondContainerOverTheSameCacheDoesNotRecompile(): void
    {
        $cache = new FakeSimpleCache();

        $first = new Container(null, null, $cache);
        $first->bind(CpBoundInterface::class, CpBoundImpl::class);
        $first->singleton(CpSharedByMethod::class);
        $first->get(CpParityRoot::class);

        $writesAfterFirst = count($cache->writes);
        self::assertGreaterThan(0, $writesAfterFirst);

        $second = new Container(null, null, $cache);
        $second->bind(CpBoundInterface::class, CpBoundImpl::class);
        $second->singleton(CpSharedByMethod::class);

        self::assertInstanceOf(CpParityRoot::class, $second->get(CpParityRoot::class));
        self::assertCount($writesAfterFirst, $cache->writes, 'The warm run must read, not recompile.');
        self::assertNotSame([], $cache->reads);
    }

    /**
     * @return iterable<string, array{callable(): CacheInterface|CacheItemPoolInterface}>
     */
    public static function provideCacheFlavours(): iterable
    {
        yield 'PSR-16 simple cache' => [static fn (): CacheInterface => new FakeSimpleCache()];
        yield 'PSR-6 item pool' => [static fn (): CacheItemPoolInterface => new FakeCachePool()];
    }

    /**
     * @param callable(): CacheInterface|CacheItemPoolInterface $flavour
     */
    #[DataProvider('provideCacheFlavours')]
    public function testBothCacheFlavoursPassTheSameChecks(callable $flavour): void
    {
        $cache = $flavour();

        $first = new Container(null, null, $cache);
        $first->bind(CpBoundInterface::class, CpBoundImpl::class);

        self::assertInstanceOf(CpBoundImpl::class, $first->get(CpBoundInterface::class));

        $writesAfterFirst = count($cache->writes);
        self::assertGreaterThan(0, $writesAfterFirst);

        $second = new Container(null, null, $cache);
        $second->bind(CpBoundInterface::class, CpBoundImpl::class);

        self::assertInstanceOf(CpBoundImpl::class, $second->get(CpBoundInterface::class));
        self::assertCount($writesAfterFirst, $cache->writes);

        foreach ([...$cache->reads, ...$cache->writes] as $key) {
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_.]{1,64}$/', $key);
        }
    }

    public function testStoredPayloadIsObjectFreeAndSurvivesSerialisation(): void
    {
        $cache = new FakeSimpleCache();
        $container = new Container(null, null, $cache);
        $container->bind(CpBoundInterface::class, CpBoundImpl::class);
        $container->singleton(CpSharedByMethod::class);
        $container->get(CpParityRoot::class);
        $container->get(CpNeedsEnum::class);

        self::assertNotSame([], $cache->data);

        foreach ($cache->data as $serialized) {
            $payload = unserialize($serialized);

            self::assertIsArray($payload);
            self::assertContainsOnlyPlanValues($payload);
            self::assertSame($payload, unserialize(serialize($payload)));
        }
    }

    public function testGarbagePayloadIsSilentlyRecompiled(): void
    {
        $cache = new FakeSimpleCache();
        (new Container(null, null, $cache))->get(CpService::class);

        foreach (array_keys($cache->data) as $key) {
            $cache->data[$key] = serialize('garbage that is not a plan');
        }

        $logger = new CpSpyLogger();
        $container = new Container($logger, null, $cache);

        self::assertInstanceOf(CpService::class, $container->get(CpService::class));
        self::assertNotSame(
            [],
            self::recordsMatching($logger, 'Discarded compiled plan'),
            'A foreign payload must be reported and recompiled, never thrown at the caller.',
        );
    }

    public function testForeignFormatStampIsSilentlyRecompiled(): void
    {
        $cache = new FakeSimpleCache();
        (new Container(null, null, $cache))->get(CpService::class);

        foreach ($cache->data as $key => $serialized) {
            $payload = unserialize($serialized);
            $payload['version'] = 'stamp-from-another-era';
            $cache->data[$key] = serialize($payload);
        }

        $writesBefore = count($cache->writes);
        $logger = new CpSpyLogger();
        $container = new Container($logger, null, $cache);

        self::assertInstanceOf(CpService::class, $container->get(CpService::class));
        self::assertGreaterThan($writesBefore, count($cache->writes));
        self::assertNotSame([], self::recordsMatching($logger, 'Discarded compiled plan'));
    }

    public function testThrowingCacheDoesNotBreakResolution(): void
    {
        $configure = static function (Container $container): void {
            $container->bind(CpBoundInterface::class, CpBoundImpl::class);
        };
        $scenario = static fn (Container $c): object => $c->get(CpParityRoot::class);

        $reference = self::observe(null, $configure, $scenario);
        $run = self::observe(new ThrowingSimpleCache(), $configure, $scenario);

        self::assertSame($reference['events'], $run['events']);
        self::assertSame($reference['log'], $run['log']);
        self::assertSame($reference['outcome'], $run['outcome']);
        self::assertInstanceOf(CpParityRoot::class, $run['value']);
    }

    // ----------------------------------------------------------- invalidation

    public function testChangedBindingDoesNotReuseTheOldPlan(): void
    {
        $cache = new FakeSimpleCache();

        $first = new Container(null, null, $cache);
        $first->bind(CpBoundInterface::class, CpBoundImpl::class);

        self::assertInstanceOf(CpBoundImpl::class, $first->get(CpBoundInterface::class));

        $second = new Container(null, null, $cache);
        $second->bind(CpBoundInterface::class, CpBoundImplB::class);

        // A different binding map lands on a different key: clean miss and a
        // fresh compilation, never a stale hit.
        self::assertInstanceOf(CpBoundImplB::class, $second->get(CpBoundInterface::class));
        self::assertCount(2, $cache->writes);
        self::assertNotSame($cache->writes[0], $cache->writes[1]);
    }

    public function testSingletonRegistrationParticipatesInTheCacheKey(): void
    {
        $cache = new FakeSimpleCache();

        $first = new Container(null, null, $cache);

        self::assertNotSame($first->get(CpService::class), $first->get(CpService::class));

        $second = new Container(null, null, $cache);
        $second->singleton(CpService::class);

        self::assertSame($second->get(CpService::class), $second->get(CpService::class));
        self::assertCount(2, $cache->writes);
        self::assertNotSame($cache->writes[0], $cache->writes[1]);
    }

    public function testTouchedClassFileTriggersRecompilation(): void
    {
        $class = 'CpTouched' . str_replace('.', '', uniqid('', true));
        $file = sys_get_temp_dir() . '/' . $class . '.php';

        file_put_contents(
            $file,
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace AutowirePHP\\Tests\\Generated;\n\nfinal class {$class}\n{\n}\n",
        );

        try {
            require $file;

            /** @var class-string $id */
            $id = 'AutowirePHP\\Tests\\Generated\\' . $class;
            $cache = new FakeSimpleCache();

            (new Container(null, null, $cache))->get($id);

            self::assertCount(1, $cache->writes);

            touch($file, time() + 5);
            clearstatcache();

            $logger = new CpSpyLogger();
            $container = new Container($logger, null, $cache);
            $container->get($id);

            self::assertCount(2, $cache->writes, 'A touched class file must force a recompilation.');

            $discarded = self::recordsMatching($logger, 'Discarded compiled plan');

            self::assertCount(1, $discarded);
            self::assertStringContainsString($file, $discarded[0]['context']['reason']);
        } finally {
            @unlink($file);
        }
    }

    public function testInternalClassWithoutFileCompilesAndValidates(): void
    {
        $cache = new FakeSimpleCache();

        self::assertInstanceOf(ArrayObject::class, (new Container(null, null, $cache))->get(ArrayObject::class));

        $logger = new CpSpyLogger();
        $second = new Container($logger, null, $cache);

        self::assertInstanceOf(ArrayObject::class, $second->get(ArrayObject::class));
        self::assertCount(1, $cache->writes, 'A file-less class must not destabilise the manifest.');
        self::assertNotSame([], self::recordsMatching($logger, 'Loaded compiled plan'));
        self::assertSame([], self::recordsMatching($logger, 'Discarded compiled plan'));
    }

    public function testPreviouslyMissingTypeInvalidatesThePlanOnceItAppears(): void
    {
        $class = 'CpAppears' . str_replace('.', '', uniqid('', true));
        $namespace = 'AutowirePHP\\Tests\\Generated';
        $id = $namespace . '\\' . $class;
        $file = sys_get_temp_dir() . '/' . $class . '.php';

        try {
            $cache = new FakeSimpleCache();

            // First compilation: the dependency class does not exist yet, so
            // the compiler records it into the plan's "missing" list and the
            // node is a fail-node carrying NotFoundException.
            $first = new Container(null, null, $cache);

            try {
                $first->get($id);
                self::fail('Expected NotFoundException was not thrown.');
            } catch (Throwable $exception) {
                self::assertStringContainsString($id, $exception->getMessage());
            }

            self::assertCount(1, $cache->writes);

            // The class now appears on disk, as it would after a deploy.
            file_put_contents(
                $file,
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\nfinal class {$class}\n{\n}\n",
            );
            require $file;

            $logger = new CpSpyLogger();
            $second = new Container($logger, null, $cache);

            self::assertInstanceOf($id, $second->get($id));
            self::assertCount(
                2,
                $cache->writes,
                'A previously missing type that now exists must force a recompilation.',
            );

            $discarded = self::recordsMatching($logger, 'Discarded compiled plan');

            self::assertCount(1, $discarded);
            self::assertStringContainsString($id, $discarded[0]['context']['reason']);
        } finally {
            @unlink($file);
        }
    }

    public function testConstructorInheritedFromTraitAndParentIsTrackedInTheManifest(): void
    {
        $suffix = str_replace('.', '', uniqid('', true));
        $namespace = 'AutowirePHP\\Tests\\Generated';
        $traitName = 'CpGenTrait' . $suffix;
        $parentName = 'CpGenParent' . $suffix;
        $childName = 'CpGenChild' . $suffix;
        $traitFile = sys_get_temp_dir() . '/' . $traitName . '.php';
        $parentFile = sys_get_temp_dir() . '/' . $parentName . '.php';
        $childFile = sys_get_temp_dir() . '/' . $childName . '.php';
        $childId = $namespace . '\\' . $childName;

        try {
            // The constructor lives in a trait composed into the parent; the
            // child class declares no constructor of its own, so both the
            // parent file and the trait file must land in the manifest.
            file_put_contents(
                $traitFile,
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\ntrait {$traitName}\n{\n"
                    . '    public function __construct(public readonly \\AutowirePHP\\Tests\\CpLeaf $leaf)'
                    . "\n    {\n    }\n}\n",
            );
            file_put_contents(
                $parentFile,
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\nclass {$parentName}\n{\n"
                    . "    use {$traitName};\n}\n",
            );
            file_put_contents(
                $childFile,
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\nfinal class {$childName}"
                    . " extends {$parentName}\n{\n}\n",
            );

            require $traitFile;
            require $parentFile;
            require $childFile;

            $cache = new FakeSimpleCache();

            $first = new Container(null, null, $cache);

            self::assertInstanceOf($childId, $first->get($childId));
            self::assertCount(1, $cache->writes);

            // Warm run over the same cache must load, not recompile: proves
            // the manifest built from the parent+trait files validated clean.
            $logger = new CpSpyLogger();
            $second = new Container($logger, null, $cache);

            self::assertInstanceOf($childId, $second->get($childId));
            self::assertCount(1, $cache->writes, 'A clean manifest must not trigger a recompilation.');
            self::assertNotSame([], self::recordsMatching($logger, 'Loaded compiled plan'));

            // Touching the trait file alone (not parent, not child) must still
            // invalidate the plan: the manifest has to track the trait file
            // independently of the class that composes it.
            touch($traitFile, time() + 5);
            clearstatcache();

            $third = new Container(null, null, $cache);

            self::assertInstanceOf($childId, $third->get($childId));
            self::assertCount(2, $cache->writes, 'A touched trait file must force a recompilation.');
        } finally {
            @unlink($traitFile);
            @unlink($parentFile);
            @unlink($childFile);
        }
    }

    // ---------------------------------------------------------- default values

    public function testEnumDefaultSurvivesTheCacheRoundTrip(): void
    {
        $cache = new FakeSimpleCache();

        $cold = new Container(null, null, $cache);

        self::assertSame(CpColor::Green, $cold->get(CpNeedsEnum::class)->color);

        // The payload carries only a reference to the defaulted parameter;
        // both containers evaluate it lazily, and an enum case evaluates to
        // the very same singleton instance in any process.
        $warm = new Container(null, null, $cache);

        self::assertSame(CpColor::Green, $warm->get(CpNeedsEnum::class)->color);
    }

    public function testClassConstantDefaultSurvivesTheCacheRoundTrip(): void
    {
        $cache = new FakeSimpleCache();

        $cold = new Container(null, null, $cache);

        self::assertSame(42, $cold->get(CpNeedsClassConstantDefault::class)->limit);

        $warm = new Container(null, null, $cache);

        self::assertSame(42, $warm->get(CpNeedsClassConstantDefault::class)->limit);
    }

    public function testObjectDefaultIsNotSharedBetweenResolutions(): void
    {
        $cache = new FakeSimpleCache();

        foreach (['cold', 'warm'] as $label) {
            $container = new Container(null, null, $cache);

            $first = $container->get(CpObjectDefault::class);
            $second = $container->get(CpObjectDefault::class);

            self::assertInstanceOf(CpOptionalDepImpl::class, $first->dep, $label);

            // An object result of the deferred default is never memoised: a
            // baked or memoised object would be one shared instance, while
            // the runtime path evaluates the initializer anew on every build.
            self::assertNotSame($first->dep, $second->dep, $label);
        }
    }

    public function testThrowingObjectDefaultBehindASuccessfulProbeIsNeverEvaluated(): void
    {
        // The B1 blocker fixture: the probe target resolves, so neither path
        // may ever evaluate the default whose constructor throws. Before the
        // deferred-default fix the compiler evaluated it eagerly and the
        // compiled mode threw where the runtime mode succeeded.
        $runtime = (new Container())->get(CpProbeWithThrowingDefault::class);

        self::assertInstanceOf(CpResolvableDep::class, $runtime->p);

        $cache = new FakeSimpleCache();

        foreach (['cold', 'warm'] as $label) {
            $compiled = (new Container(null, null, $cache))->get(CpProbeWithThrowingDefault::class);

            self::assertInstanceOf(CpResolvableDep::class, $compiled->p, $label);
        }
    }

    public function testCompilationDoesNotInstantiateDefaultValues(): void
    {
        $cache = new FakeSimpleCache();

        foreach (['cold', 'warm'] as $label) {
            CpConstructionCounter::$constructions = 0;

            $container = new Container(null, null, $cache);

            $first = $container->get(CpUntypedObjectDefault::class);
            $second = $container->get(CpUntypedObjectDefault::class);

            // The runtime path evaluates this initializer once per build: two
            // builds, two constructions. An eager compilation would add a
            // third one on the cold run.
            self::assertSame(2, CpConstructionCounter::$constructions, $label);
            self::assertNotSame($first->dep, $second->dep, $label);
        }
    }

    /**
     * The counterpart of testThrowingObjectDefaultBehindASuccessfulProbeIsNeverEvaluated:
     * here the probe target is never resolvable, so the deferred default must
     * actually fire on every build, and — being a new-in-initializer default —
     * must produce a fresh object each time rather than a memoised one.
     */
    public function testObjectDefaultBehindAFailedProbeIsEvaluatedFreshOnEveryBuild(): void
    {
        $cache = new FakeSimpleCache();

        foreach (['cold', 'warm'] as $label) {
            CpCountingUnboundImpl::$constructions = 0;

            $container = new Container(null, null, $cache);

            $first = $container->get(CpProbeWithCountingDefault::class);
            $second = $container->get(CpProbeWithCountingDefault::class);

            self::assertInstanceOf(CpCountingUnboundImpl::class, $first->p, $label);
            self::assertSame(2, CpCountingUnboundImpl::$constructions, $label);
            self::assertNotSame($first->p, $second->p, $label);
        }
    }

    /**
     * Same as above, but for a union type where every member is unresolvable:
     * the default must only be reached after both members fail, and must
     * still be evaluated fresh on every build.
     */
    public function testObjectDefaultBehindAFailedUnionIsEvaluatedFreshOnEveryBuild(): void
    {
        $cache = new FakeSimpleCache();

        foreach (['cold', 'warm'] as $label) {
            CpCountingUnboundImpl::$constructions = 0;

            $container = new Container(null, null, $cache);

            $first = $container->get(CpUnionWithCountingDefault::class);
            $second = $container->get(CpUnionWithCountingDefault::class);

            self::assertInstanceOf(CpCountingUnboundImpl::class, $first->v, $label);
            self::assertSame(2, CpCountingUnboundImpl::$constructions, $label);
            self::assertNotSame($first->v, $second->v, $label);
        }
    }

    // ------------------------------------------- degraded plan preparation

    public function testCompilationFailureFallsBackToTheRuntimePath(): void
    {
        // The ghost member is unreachable on the runtime path (the first
        // union member resolves), so only the compiler's eager walk hits the
        // throwing autoloader: the failure belongs to plan preparation alone
        // and must degrade, never surface out of get().
        $loader = static function (string $class): void {
            if ($class === CpCompileGhost::class) {
                throw new RuntimeException('autoloader exploded');
            }
        };

        spl_autoload_register($loader);

        try {
            $logger = new CpSpyLogger();
            $cache = new FakeSimpleCache();
            $container = new Container($logger, null, $cache);

            $resolved = $container->get(CpServiceOrCompileGhost::class);

            self::assertInstanceOf(CpService::class, $resolved->v);

            // The child ids reached on the runtime fallback still compile
            // their own plans — only the failed root must not store one.
            $rootKey = PlanCache::keyFor(
                PlanCache::configHash([], []),
                CpServiceOrCompileGhost::class,
            );

            self::assertNotContains(
                $rootKey,
                $cache->writes,
                'A failed compilation must not store a plan for the failed root.',
            );
            self::assertNotSame(
                [],
                self::recordsMatching($logger, 'Failed to prepare a compiled plan'),
            );
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    /**
     * The two degradation tests above run without a dispatcher, so neither
     * exercises the pairing invariant PSR-14 events must keep: every
     * ResolutionRequested matched by exactly one terminal event per id, in
     * the same order runtime would produce. Structurally that pairing cannot
     * break here — preparePlan() sits inside get()'s outer try and only ever
     * produces a debug record, never a throw, so there is nothing for it to
     * disrupt — but the harness already builds this exact comparison for
     * every other scenario, so asserting it costs a handful of lines instead
     * of an argument.
     */
    public function testDegradedCompilationKeepsEventPairingWithTheRuntimePath(): void
    {
        $configure = static function (Container $container): void {
        };
        $scenario = static fn (Container $c): object => $c->get(CpServiceOrCompileGhost::class);

        $reference = self::observe(null, $configure, $scenario);

        $loader = static function (string $class): void {
            if ($class === CpCompileGhost::class) {
                throw new RuntimeException('autoloader exploded');
            }
        };

        spl_autoload_register($loader);

        try {
            $degraded = self::observe(new FakeSimpleCache(), $configure, $scenario);
        } finally {
            spl_autoload_unregister($loader);
        }

        self::assertSame($reference['events'], $degraded['events'], 'event stream diverged');
        self::assertSame($reference['outcome'], $degraded['outcome'], 'outcome diverged');
    }

    public function testPlanValidationFailureFallsBackToTheRuntimePath(): void
    {
        $cache = new FakeSimpleCache();

        // Cold run, no throwing autoloader yet: the ghost member compiles
        // into the plan's missing list.
        $first = new Container(null, null, $cache);

        self::assertInstanceOf(CpService::class, $first->get(CpServiceOrLateGhost::class)->v);
        self::assertCount(1, $cache->writes);

        // Warm run: staleness() re-checks the missing list through
        // class_exists(), which triggers autoloading — this failure belongs
        // to the validation layer, not to the driver and not to compilation.
        $loader = static function (string $class): void {
            if ($class === CpLateGhost::class) {
                throw new RuntimeException('autoloader exploded');
            }
        };

        spl_autoload_register($loader);

        try {
            $logger = new CpSpyLogger();
            $second = new Container($logger, null, $cache);

            self::assertInstanceOf(CpService::class, $second->get(CpServiceOrLateGhost::class)->v);

            // The root plan was written exactly once — on the cold run. The
            // failed validation must neither throw nor blindly recompile the
            // root; child ids of the runtime fallback may still store theirs.
            $rootKey = PlanCache::keyFor(
                PlanCache::configHash([], []),
                CpServiceOrLateGhost::class,
            );

            self::assertCount(1, array_keys($cache->writes, $rootKey, true));
            self::assertNotSame(
                [],
                self::recordsMatching($logger, 'Failed to prepare a compiled plan'),
            );
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    /**
     * Preparation that failed once must not be retried on every get(): without
     * the write-off, each subsequent top-level get() of that id repeats the
     * cache read and the full eager reflection walk before degrading again,
     * which makes the compiled mode slower than the runtime mode it replaces.
     */
    public function testFailedPlanPreparationIsAttemptedOncePerId(): void
    {
        $loader = static function (string $class): void {
            if ($class === CpCompileGhost::class) {
                throw new RuntimeException('autoloader exploded');
            }
        };

        spl_autoload_register($loader);

        try {
            $logger = new CpSpyLogger();
            $cache = new FakeSimpleCache();
            $container = new Container($logger, null, $cache);

            $rootKey = PlanCache::keyFor(
                PlanCache::configHash([], []),
                CpServiceOrCompileGhost::class,
            );

            for ($i = 0; $i < 3; ++$i) {
                self::assertInstanceOf(
                    CpService::class,
                    $container->get(CpServiceOrCompileGhost::class)->v,
                );
            }

            self::assertCount(
                1,
                self::recordsMatching($logger, 'Failed to prepare a compiled plan'),
                'Three resolutions of an unplannable id must cost one attempt, not three.',
            );
            self::assertCount(1, array_keys($cache->reads, $rootKey, true));

            // A registration compiles a different graph, so the write-off is
            // dropped together with the plans and the id is tried again.
            $container->bind(CpBoundInterface::class, CpBoundImpl::class);

            self::assertInstanceOf(
                CpService::class,
                $container->get(CpServiceOrCompileGhost::class)->v,
            );

            self::assertCount(
                2,
                self::recordsMatching($logger, 'Failed to prepare a compiled plan'),
            );
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    // ------------------------------------------------------------- factories

    /**
     * A factory id has no plan at all: it is excluded from compilation up
     * front rather than attempted and failed, and a compiled container
     * resolves it through the very same runtime factory branch — so cold and
     * warm runs must be observationally identical to the runtime container.
     */
    public function testCompiledContainerResolvesFactoryRootThroughRuntimePath(): void
    {
        $configure = static function (Container $container): void {
            $container->factory(
                CpFactoryPort::class,
                static fn (Container $c): object => new CpFactoryAdapter($c->get(CpLeaf::class), 'dsn://x'),
            );
        };
        $scenario = static fn (Container $c): object => $c->get(CpFactoryPort::class);

        $reference = self::observe(null, $configure, $scenario);

        self::assertInstanceOf(CpFactoryAdapter::class, $reference['value']);

        $cache = new FakeSimpleCache();

        foreach (['cold compiled run', 'warm compiled run'] as $label) {
            $run = self::observe($cache, $configure, $scenario);

            self::assertSame($reference['events'], $run['events'], $label . ': event stream diverged');
            self::assertSame($reference['log'], $run['log'], $label . ': resolution log diverged');
            self::assertSame($reference['outcome'], $run['outcome'], $label . ': outcome diverged');
            self::assertInstanceOf(CpFactoryAdapter::class, $run['value'], $label);
            self::assertSame('dsn://x', $run['value']->dsn, $label);
        }

        // The cache never saw the factory id: no plan was stored for it and
        // none was even looked up. (The CpLeaf the factory pulls through
        // get() compiles its own plan as any id does — that one may appear.)
        $factoryKey = PlanCache::keyFor(
            PlanCache::configHash([], [], factoryIds: [CpFactoryPort::class]),
            CpFactoryPort::class,
        );

        self::assertNotContains($factoryKey, $cache->writes);
        self::assertNotContains($factoryKey, $cache->reads);
    }

    /**
     * The compiler sees the factory-bound interface as an ordinary unbound
     * parameter type and compiles it into the parent's plan; at execution the
     * child goes through the public get(), where the factory branch wins
     * before the plan node is consulted. The parent rides the plan, the
     * dependency rides the factory, and nothing observable diverges.
     */
    public function testCompiledParentWithFactoryDependencyMatchesRuntime(): void
    {
        $configure = static function (Container $container): void {
            $container->factory(
                CpFactoryPort::class,
                static fn (Container $c): object => new CpFactoryAdapter($c->get(CpLeaf::class), 'dsn://x'),
            );
        };
        $scenario = static fn (Container $c): object => $c->get(CpNeedsPort::class);

        $reference = self::observe(null, $configure, $scenario);

        self::assertInstanceOf(CpNeedsPort::class, $reference['value']);

        $cache = new FakeSimpleCache();

        foreach (['cold compiled run', 'warm compiled run'] as $label) {
            $run = self::observe($cache, $configure, $scenario);

            self::assertSame($reference['events'], $run['events'], $label . ': event stream diverged');
            self::assertSame($reference['log'], $run['log'], $label . ': resolution log diverged');
            self::assertSame($reference['outcome'], $run['outcome'], $label . ': outcome diverged');
            self::assertInstanceOf(CpNeedsPort::class, $run['value'], $label);
            self::assertInstanceOf(CpFactoryAdapter::class, $run['value']->port, $label);
        }

        // The parent really did go through the compiled path — its plan is in
        // the cache — while the factory id stored none.
        $hash = PlanCache::configHash([], [], factoryIds: [CpFactoryPort::class]);

        self::assertContains(PlanCache::keyFor($hash, CpNeedsPort::class), $cache->writes);
        self::assertNotContains(PlanCache::keyFor($hash, CpFactoryPort::class), $cache->writes);
    }

    public function testRegisteringFactoryInvalidatesCompiledPlan(): void
    {
        $cache = new FakeSimpleCache();
        $container = new Container(null, null, $cache);
        $container->bind(CpGateway::class, CpGatewayA::class);

        self::assertInstanceOf(CpGatewayA::class, $container->get(CpGateway::class));
        self::assertCount(1, $cache->writes);
        self::assertCount(1, $cache->reads);

        $container->factory(CpGateway::class, static fn (Container $c): object => new CpGatewayB());

        // Neither the in-memory node nor the cached plan may resurrect the
        // compiled CpGatewayA construction: the id now resolves by factory,
        // without touching the cache at all.
        self::assertInstanceOf(CpGatewayB::class, $container->get(CpGateway::class));
        self::assertCount(1, $cache->writes);
        self::assertCount(1, $cache->reads);

        // And the registration moved the config hash, so the old plan's key
        // can never be looked up again by any container configured this way.
        $oldHash = PlanCache::configHash([CpGateway::class => CpGatewayA::class], []);
        $newHash = PlanCache::configHash(
            [CpGateway::class => CpGatewayA::class],
            [],
            factoryIds: [CpGateway::class],
        );

        self::assertNotSame($oldHash, $newHash);
        self::assertSame([PlanCache::keyFor($oldHash, CpGateway::class)], $cache->writes);
    }

    // ------------------------------------------------------ guarded decisions

    public function testContainerWithCacheStillAutowiresItselfAndItsDependents(): void
    {
        $container = new Container(null, null, new FakeSimpleCache());

        self::assertInstanceOf(Container::class, $container->get(Container::class));
        self::assertInstanceOf(CpNeedsContainer::class, $container->get(CpNeedsContainer::class));
    }

    public function testThrowingListenerStillSurfacesAsListenerExceptionInCompiledMode(): void
    {
        $container = new Container(
            null,
            new CpThrowingDispatcher(
                static fn (object $event): ?Throwable => $event instanceof ServiceResolved
                    ? new RuntimeException('listener is broken')
                    : null,
            ),
            new FakeSimpleCache(),
        );

        $this->expectException(ListenerException::class);

        $container->get(CpLeaf::class);
    }

    public function testListenerFailureDoesNotRewriteTheOutcomeInCompiledMode(): void
    {
        $container = new Container(
            null,
            new CpThrowingDispatcher(
                static fn (object $event): ?Throwable => $event instanceof ResolutionFailed
                    ? new RuntimeException('listener is broken')
                    : null,
            ),
            new FakeSimpleCache(),
        );

        $this->expectException(NotInstantiableException::class);

        $container->get(CpUnboundInterface::class);
    }

    // ------------------------------------------------- cache log observability

    public function testAllCacheLogRecordsUseDebugLevel(): void
    {
        $logger = self::exerciseEveryCacheLoggedEvent();

        foreach ($logger->records as $record) {
            self::assertSame(LogLevel::DEBUG, $record['level'], $record['message']);
        }
    }

    public function testEveryCacheLogPlaceholderHasMatchingContextKey(): void
    {
        $logger = self::exerciseEveryCacheLoggedEvent();

        foreach ($logger->records as $record) {
            preg_match_all('/\{([A-Za-z0-9_]+)\}/', $record['message'], $matches);

            self::assertNotSame([], $matches[1], sprintf('Message "%s" has no placeholder.', $record['message']));

            foreach ($matches[1] as $placeholder) {
                self::assertArrayHasKey(
                    $placeholder,
                    $record['context'],
                    sprintf('Placeholder {%s} of "%s" has no context key.', $placeholder, $record['message']),
                );
            }
        }
    }

    /**
     * Mirror of exerciseEveryLoggedEvent() in ContainerTest, for the cache
     * records: drives containers through every cache-related log point —
     * compiled, loaded, discarded, driver failure, preparation failure — and
     * pins their number so the cross-cutting assertions above cannot silently
     * stop being exhaustive.
     */
    private static function exerciseEveryCacheLoggedEvent(): CpSpyLogger
    {
        $logger = new CpSpyLogger();
        $cache = new FakeSimpleCache();

        (new Container($logger, null, $cache))->get(CpService::class);        // compiled
        (new Container($logger, null, $cache))->get(CpService::class);        // loaded

        foreach (array_keys($cache->data) as $key) {
            $cache->data[$key] = serialize('garbage');
        }

        (new Container($logger, null, $cache))->get(CpService::class);        // discarded + compiled

        (new Container($logger, null, new ThrowingSimpleCache()))->get(CpService::class); // read/write failures

        // Preparation failure: a throwing autoloader on a union member only
        // the compiler's eager walk reaches.
        $loader = static function (string $class): void {
            if ($class === CpCompileGhost::class) {
                throw new RuntimeException('autoloader exploded');
            }
        };

        spl_autoload_register($loader);

        try {
            (new Container($logger, null, new FakeSimpleCache()))->get(CpServiceOrCompileGhost::class);
        } finally {
            spl_autoload_unregister($loader);
        }

        $cacheRecords = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => !in_array($record['message'], self::RESOLUTION_MESSAGES, true),
        ));

        self::assertNotSame([], $cacheRecords);

        $shapes = array_unique(array_column($cacheRecords, 'message'));

        self::assertCount(
            5,
            $shapes,
            'The helper must exercise every cache log point; update it when adding one.',
        );

        return $logger;
    }

    // ---------------------------------------------------------------- harness

    /**
     * Run one scenario on a fresh container and capture everything observable.
     *
     * @param callable(Container): void $configure
     * @param callable(Container): mixed $scenario
     * @return array{
     *     container: Container,
     *     value: mixed,
     *     outcome: array{class-string, string}|null,
     *     events: list<array<mixed>>,
     *     log: list<array{level: mixed, message: string, context: array<string, mixed>}>
     * }
     */
    private static function observe(
        CacheInterface|CacheItemPoolInterface|null $cache,
        callable $configure,
        callable $scenario,
    ): array {
        $logger = new CpSpyLogger();
        $events = new CpSpyDispatcher();
        $container = new Container($logger, $events, $cache);
        $configure($container);

        $value = null;
        $outcome = null;

        try {
            $value = $scenario($container);
        } catch (Throwable $exception) {
            $outcome = [$exception::class, $exception->getMessage()];
        }

        return [
            'container' => $container,
            'value' => $value,
            'outcome' => $outcome,
            'events' => array_map(self::describeEvent(...), $events->events),
            'log' => self::resolutionRecords($logger),
        ];
    }

    /**
     * @return array<mixed>
     */
    private static function describeEvent(object $event): array
    {
        return match (true) {
            $event instanceof ServiceResolved => [$event::class, $event->id, $event->depth, $event->fromCache],
            $event instanceof ResolutionFailed => [
                $event::class,
                $event->id,
                $event->depth,
                $event->exception::class,
                $event->exception->getMessage(),
            ],
            default => [$event::class, $event->id, $event->depth],
        };
    }

    /**
     * The resolution records only, with objects in context collapsed to their
     * class names so records can be compared across containers. Cache records
     * are excluded: they are the one observability difference the compiled
     * mode is allowed to have.
     *
     * @return list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    private static function resolutionRecords(CpSpyLogger $logger): array
    {
        $records = array_filter(
            $logger->records,
            static fn (array $record): bool => in_array($record['message'], self::RESOLUTION_MESSAGES, true),
        );

        return array_values(array_map(
            static fn (array $record): array => [
                'level' => $record['level'],
                'message' => $record['message'],
                'context' => array_map(
                    static fn (mixed $value): mixed => is_object($value) ? $value::class : $value,
                    $record['context'],
                ),
            ],
            $records,
        ));
    }

    private static function assertContainsOnlyPlanValues(mixed $value): void
    {
        if (is_object($value)) {
            self::assertInstanceOf(
                UnitEnum::class,
                $value,
                'Enum cases are the only objects allowed in a cached payload.',
            );

            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertContainsOnlyPlanValues($item);
            }

            return;
        }

        self::assertTrue($value === null || is_scalar($value));
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    private static function recordsMatching(CpSpyLogger $logger, string $needle): array
    {
        return array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => str_contains($record['message'], $needle),
        ));
    }

    /**
     * @template TEvent of object
     * @param class-string<TEvent> $class
     * @return list<TEvent>
     */
    private static function eventsOf(CpSpyDispatcher $dispatcher, string $class): array
    {
        return array_values(array_filter(
            $dispatcher->events,
            static fn (object $event): bool => $event instanceof $class,
        ));
    }
}

// Fixture classes for the compiled-mode scenarios above. Kept in this file so
// the dependency graph under test is visible at a glance; deliberately not
// shared with ContainerTest.php.

final class CpLeaf
{
}

final class CpService
{
    public function __construct(public readonly CpLeaf $leaf)
    {
    }
}

interface CpBoundInterface
{
}

final class CpBoundImpl implements CpBoundInterface
{
    public function __construct(public readonly CpLeaf $leaf)
    {
    }
}

final class CpBoundImplB implements CpBoundInterface
{
}

interface CpGateway
{
}

final class CpGatewayA implements CpGateway
{
}

final class CpGatewayB implements CpGateway
{
}

interface CpUnboundInterface
{
}

interface CpUnboundInterfaceB
{
}

final class CpUnionImpl
{
}

final class CpSharedByMethod
{
}

interface CpAttrIface
{
}

#[Singleton]
final class CpSharedByAttr implements CpAttrIface
{
}

/**
 * One constructor exercising every kind of compiled argument spec at once:
 * plain service, service through a binding, #[Inject] service, resolving and
 * failing nullable probes, a union probe, shared instances of both flavours,
 * a nullable builtin (null spec), a defaulted builtin (deferred default spec)
 * and a variadic tail that cuts the argument list.
 */
final class CpParityRoot
{
    /**
     * @var list<CpLeaf>
     */
    public array $variadic;

    public function __construct(
        public readonly CpService $plain,
        public readonly CpBoundInterface $bound,
        #[Inject(CpGatewayB::class)] public readonly CpGateway $injected,
        public readonly ?CpService $probeHit,
        public readonly ?CpUnboundInterface $probeMiss,
        public readonly CpUnboundInterface|CpUnionImpl $union,
        public readonly CpSharedByMethod $sharedByMethod,
        public readonly CpSharedByAttr $sharedByAttr,
        public readonly ?string $note,
        public readonly int $answer = 42,
        CpLeaf ...$variadic,
    ) {
        $this->variadic = $variadic;
    }
}

final class CpNeedsBuiltin
{
    public function __construct(int $count)
    {
    }
}

final class CpNeedsUntyped
{
    public function __construct($whatever)
    {
    }
}

final class CpNeedsMissing
{
    public function __construct(CpDoesNotExist $dep)
    {
    }
}

abstract class CpAbstract
{
}

final class CpPrivateCtor
{
    private function __construct()
    {
    }
}

final class CpUnionWithDefault
{
    public function __construct(public readonly CpUnboundInterface|int $v = 7)
    {
    }
}

final class CpUnionNullable
{
    public function __construct(public readonly CpUnboundInterface|CpUnboundInterfaceB|null $v)
    {
    }
}

final class CpUnionFails
{
    public function __construct(public readonly CpUnboundInterface|CpUnboundInterfaceB $v)
    {
    }
}

final class CpUnionFirst
{
    public function __construct(public readonly CpUnionImpl|CpService $v)
    {
    }
}

final class CpInjectOnUnion
{
    public function __construct(
        #[Inject(CpGatewayA::class)] public readonly CpUnboundInterface|CpUnionImpl $v,
    ) {
    }
}

final class CpCycleA
{
    public function __construct(public readonly CpCycleB $b)
    {
    }
}

final class CpCycleB
{
    public function __construct(public readonly CpCycleA $a)
    {
    }
}

interface CpCycleNullable
{
}

final class CpNullableCycleImpl implements CpCycleNullable
{
    public function __construct(public readonly ?CpCycleNullable $next = null)
    {
    }
}

final class CpSideEffect
{
    public function __construct(public readonly CpSharedByMethod $first, int $second)
    {
    }
}

enum CpColor
{
    case Red;
    case Green;
}

final class CpNeedsEnum
{
    public function __construct(public readonly ?CpColor $color = CpColor::Green)
    {
    }
}

final class CpNeedsClassConstantDefault
{
    public const LIMIT = 42;

    public function __construct(public readonly int $limit = self::LIMIT)
    {
    }
}

interface CpOptionalDep
{
}

final class CpOptionalDepImpl implements CpOptionalDep
{
}

final class CpObjectDefault
{
    public function __construct(public readonly ?CpOptionalDep $dep = new CpOptionalDepImpl())
    {
    }
}

final class CpNeedsContainer
{
    public function __construct(public readonly Container $container)
    {
    }
}

// Factory fixtures: the port is only ever satisfied by a user factory (no
// binding exists), and the adapter mixes an autowired dependency with a
// non-object argument no compilation could plan.
interface CpFactoryPort
{
}

final class CpFactoryAdapter implements CpFactoryPort
{
    public function __construct(
        public readonly CpLeaf $leaf,
        public readonly string $dsn,
    ) {
    }
}

final class CpNeedsPort
{
    public function __construct(
        public readonly CpFactoryPort $port,
        public readonly CpLeaf $leaf,
    ) {
    }
}

// Deliberately not final: CpThrowingDefaultDep must subtype it so that a
// new-in-initializer default can satisfy the ?CpResolvableDep type.
class CpResolvableDep
{
}

final class CpThrowingDefaultDep extends CpResolvableDep
{
    public function __construct()
    {
        throw new LogicException('default evaluated!');
    }
}

final class CpProbeWithThrowingDefault
{
    public function __construct(public readonly ?CpResolvableDep $p = new CpThrowingDefaultDep())
    {
    }
}

final class CpUnionWithThrowingDefault
{
    public function __construct(
        public readonly CpUnionImpl|CpResolvableDep $v = new CpThrowingDefaultDep(),
    ) {
    }
}

final class CpConstructionCounter
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
    }
}

final class CpUntypedObjectDefault
{
    public function __construct(public $dep = new CpConstructionCounter())
    {
    }
}

// A default whose class actually satisfies the declared type (unlike
// CpConstructionCounter above, a plain default's type need not match its
// parameter's type, but a default that is itself constructed and returned
// to the caller must — PHP raises a TypeError otherwise). Counts
// constructions like CpConstructionCounter so a test can prove the default
// fired and was not memoised as an object.
final class CpCountingUnboundImpl implements CpUnboundInterface
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
    }
}

// Unlike CpProbeWithThrowingDefault, the probe target here is never
// resolvable (no binding for CpUnboundInterface exists), so the default is
// not merely skipped — it must actually fire on every build.
final class CpProbeWithCountingDefault
{
    public function __construct(
        public readonly ?CpUnboundInterface $p = new CpCountingUnboundImpl(),
    ) {
    }
}

// Mirrors CpProbeWithCountingDefault for the union case: both members are
// unbound interfaces, so every union branch fails before the default is
// reached.
final class CpUnionWithCountingDefault
{
    public function __construct(
        public readonly CpUnboundInterface|CpUnboundInterfaceB|null $v = new CpCountingUnboundImpl(),
    ) {
    }
}

/**
 * CpCompileGhost / CpLateGhost are deliberately never defined: the runtime
 * path resolves the first union member and never touches them, while the
 * compiler's eager walk (and the missing-list validation of a loaded plan)
 * does — which makes an exploding autoloader for them a failure exclusive to
 * plan preparation.
 */
final class CpServiceOrCompileGhost
{
    public function __construct(public readonly CpService|CpCompileGhost $v)
    {
    }
}

final class CpServiceOrLateGhost
{
    public function __construct(public readonly CpService|CpLateGhost $v)
    {
    }
}

// Fixtures for the contextual binding parity scenarios: one contract, two
// implementations, consumers of every flavour the priority rules distinguish.

interface CpCtxContract
{
}

final class CpCtxImplA implements CpCtxContract
{
}

final class CpCtxImplB implements CpCtxContract
{
}

final class CpCtxConsumerOne
{
    public function __construct(public readonly CpCtxContract $dep)
    {
    }
}

final class CpCtxConsumerTwo
{
    public function __construct(public readonly CpCtxContract $dep)
    {
    }
}

final class CpCtxInjectConsumer
{
    public function __construct(
        #[Inject(CpCtxImplA::class)] public readonly CpCtxContract $dep,
    ) {
    }
}

/**
 * Same attribute but no contextual binding is ever registered for this class:
 * the regression guard that a context registered for another consumer does
 * not disturb #[Inject].
 */
final class CpCtxInjectLoner
{
    public function __construct(
        #[Inject(CpCtxImplA::class)] public readonly CpCtxContract $dep,
    ) {
    }
}

final class CpCtxCycleA implements CpCtxContract
{
    public function __construct(public readonly CpCtxContract $dep)
    {
    }
}

final class CpCtxCycleB implements CpCtxContract
{
    public function __construct(public readonly CpCtxContract $dep)
    {
    }
}

final class CpSpyLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

final class CpSpyDispatcher implements EventDispatcherInterface
{
    /**
     * @var list<object>
     */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}

final class CpThrowingDispatcher implements EventDispatcherInterface
{
    /**
     * @param callable(object): ?Throwable $failure
     */
    public function __construct(private $failure)
    {
    }

    public function dispatch(object $event): object
    {
        $throwable = ($this->failure)($event);

        if ($throwable !== null) {
            throw $throwable;
        }

        return $event;
    }
}
