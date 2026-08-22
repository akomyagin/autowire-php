<?php

declare(strict_types=1);

namespace AutowirePHP\Tests;

use AutowirePHP\Attribute\Inject;
use AutowirePHP\Attribute\Singleton;
use AutowirePHP\Attribute\Tagged;
use AutowirePHP\Container;
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
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use ReflectionObject;
use RuntimeException;
use stdClass;
use Stringable;
use Throwable;

final class ContainerTest extends TestCase
{
    public function testContainerReportsNoBindingByDefault(): void
    {
        $container = new Container();

        self::assertFalse($container->has(stdClass::class));
    }

    public function testResolvesInterfaceThroughBinding(): void
    {
        $container = new Container();
        $container->bind(FooInterface::class, Foo::class);

        $instance = $container->get(FooInterface::class);

        self::assertInstanceOf(Foo::class, $instance);
        self::assertInstanceOf(FooInterface::class, $instance);
    }

    public function testResolvesConcreteClassWithoutConstructor(): void
    {
        $container = new Container();

        self::assertInstanceOf(NoConstructor::class, $container->get(NoConstructor::class));
    }

    public function testResolvesConcreteClassWithEmptyConstructor(): void
    {
        $container = new Container();

        self::assertInstanceOf(EmptyConstructor::class, $container->get(EmptyConstructor::class));
    }

    public function testResolvesClassWithOnlyOptionalConstructorParameters(): void
    {
        $container = new Container();

        self::assertInstanceOf(
            OptionalParamsConstructor::class,
            $container->get(OptionalParamsConstructor::class),
        );
    }

    public function testThrowsNotInstantiableForInterfaceWithoutBinding(): void
    {
        $container = new Container();

        try {
            $container->get(FooInterface::class);
            self::fail('Expected NotInstantiableException was not thrown.');
        } catch (NotInstantiableException $exception) {
            self::assertStringContainsString('interface', $exception->getMessage());
        }
    }

    public function testThrowsNotInstantiableForAbstractClass(): void
    {
        $container = new Container();

        $this->expectException(NotInstantiableException::class);

        $container->get(AbstractThing::class);
    }

    public function testThrowsNotInstantiableForPrivateConstructor(): void
    {
        $container = new Container();

        $this->expectException(NotInstantiableException::class);

        $container->get(PrivateConstructor::class);
    }

    public function testAutowiresConcreteDependencyGraph(): void
    {
        $container = new Container();

        $a = $container->get(GraphA::class);

        self::assertInstanceOf(GraphA::class, $a);
        self::assertInstanceOf(GraphB::class, $a->b);
        self::assertInstanceOf(GraphC::class, $a->c);
        self::assertInstanceOf(GraphD::class, $a->b->d);
    }

    public function testAutowiresSingleLevelDependency(): void
    {
        $container = new Container();

        $b = $container->get(GraphB::class);

        self::assertInstanceOf(GraphD::class, $b->d);
    }

    public function testResolvesInterfaceConstructorParameterThroughBinding(): void
    {
        $container = new Container();
        $container->bind(FooInterface::class, Foo::class);

        $n = $container->get(NeedsFoo::class);

        self::assertInstanceOf(NeedsFoo::class, $n);
        self::assertInstanceOf(Foo::class, $n->foo);
        self::assertInstanceOf(FooInterface::class, $n->foo);
    }

    public function testTransientByDefaultCreatesFreshDependencies(): void
    {
        $container = new Container();

        $b1 = $container->get(GraphB::class);
        $b2 = $container->get(GraphB::class);

        self::assertNotSame($b1, $b2);
        self::assertNotSame($b1->d, $b2->d);
    }

    public function testThrowsNotInstantiableForInterfaceParameterWithoutBinding(): void
    {
        $container = new Container();

        try {
            $container->get(NeedsFoo::class);
            self::fail('Expected NotInstantiableException was not thrown.');
        } catch (NotInstantiableException $exception) {
            self::assertStringContainsString('interface', $exception->getMessage());
            self::assertStringNotContainsString('stage', $exception->getMessage());
            self::assertSame(FooInterface::class, $exception->getClassName());
        }
    }

    public function testThrowsUnresolvableForBuiltinParameterWithoutDefault(): void
    {
        $container = new Container();

        try {
            $container->get(NeedsBuiltinNoDefault::class);
            self::fail('Expected UnresolvableParameterException was not thrown.');
        } catch (UnresolvableParameterException $exception) {
            self::assertSame(NeedsBuiltinNoDefault::class, $exception->getClassName());
            self::assertSame('count', $exception->getParameterName());
            self::assertStringContainsString('built-in', $exception->getReason());
            self::assertStringNotContainsString('stage', $exception->getMessage());
            self::assertInstanceOf(ContainerException::class, $exception);
        }
    }

    public function testThrowsUnresolvableForUntypedParameter(): void
    {
        $container = new Container();

        try {
            $container->get(NeedsUntyped::class);
            self::fail('Expected UnresolvableParameterException was not thrown.');
        } catch (UnresolvableParameterException $exception) {
            self::assertSame(NeedsUntyped::class, $exception->getClassName());
            self::assertSame('whatever', $exception->getParameterName());
            self::assertInstanceOf(ContainerException::class, $exception);
        }
    }

    public function testResolvesMixedClassAndDefaultBuiltinParameters(): void
    {
        $container = new Container();

        $m = $container->get(MixedClassAndDefault::class);

        self::assertInstanceOf(GraphD::class, $m->d);
        self::assertSame(7, $m->x);
    }

    public function testThrowsUnresolvableForUnionTypedParameterWithoutDefault(): void
    {
        $container = new Container();

        try {
            $container->get(NeedsUnion::class);
            self::fail('Expected UnresolvableParameterException was not thrown.');
        } catch (UnresolvableParameterException $exception) {
            self::assertSame(NeedsUnion::class, $exception->getClassName());
            self::assertSame('v', $exception->getParameterName());
            self::assertInstanceOf(ContainerException::class, $exception);
        }
    }

    public function testThrowsNotFoundForUnknownId(): void
    {
        $container = new Container();

        try {
            $container->get('This\\Class\\Does\\Not\\Exist');
            self::fail('Expected NotFoundException was not thrown.');
        } catch (NotFoundException $exception) {
            self::assertSame('This\\Class\\Does\\Not\\Exist', $exception->getId());
        }
    }

    public function testNotFoundReportsUnresolvableBindingTarget(): void
    {
        $container = new Container();
        $container->bind(FooInterface::class, 'This\\Class\\Does\\Not\\Exist');

        try {
            $container->get(FooInterface::class);
            self::fail('Expected NotFoundException was not thrown.');
        } catch (NotFoundException $exception) {
            self::assertSame('This\\Class\\Does\\Not\\Exist', $exception->getId());
            self::assertStringContainsString('This\\Class\\Does\\Not\\Exist', $exception->getMessage());
        }
    }

    public function testHasReturnsTrueAfterBind(): void
    {
        $container = new Container();
        $container->bind(FooInterface::class, Foo::class);

        self::assertTrue($container->has(FooInterface::class));
        self::assertFalse($container->has(NoConstructor::class));
    }

    public function testExceptionsImplementContainerExceptionMarker(): void
    {
        $container = new Container();

        try {
            $container->get('This\\Class\\Does\\Not\\Exist');
            self::fail('Expected NotFoundException was not thrown.');
        } catch (NotFoundException $exception) {
            self::assertInstanceOf(ContainerException::class, $exception);
        }

        try {
            $container->get(AbstractThing::class);
            self::fail('Expected NotInstantiableException was not thrown.');
        } catch (NotInstantiableException $exception) {
            self::assertInstanceOf(ContainerException::class, $exception);
        }
    }

    public function testResolvesConcreteClassThroughBindingToItself(): void
    {
        $container = new Container();
        $container->bind(Foo::class, Foo::class);

        self::assertInstanceOf(Foo::class, $container->get(Foo::class));
    }

    public function testDetectsDirectCycleBetweenConcreteClasses(): void
    {
        $container = new Container();

        try {
            $container->get(DirectCycleA::class);
            self::fail('Expected CircularDependencyException was not thrown.');
        } catch (CircularDependencyException $exception) {
            $chain = $exception->getChain();

            self::assertSame(DirectCycleA::class, end($chain));
            self::assertContains(DirectCycleA::class, $chain);
            self::assertContains(DirectCycleB::class, $chain);
        }
    }

    public function testDetectsCycleThroughInterfaces(): void
    {
        $container = new Container();
        $container->bind(CycleIA::class, CycleA::class);
        $container->bind(CycleIB::class, CycleB::class);

        try {
            $container->get(CycleIA::class);
            self::fail('Expected CircularDependencyException was not thrown.');
        } catch (CircularDependencyException $exception) {
            $chain = $exception->getChain();

            self::assertContains(CycleIA::class, $chain);
            self::assertContains(CycleIB::class, $chain);
            self::assertContains(CycleA::class, $chain);
            self::assertContains(CycleB::class, $chain);
        }
    }

    public function testDetectsSelfDependency(): void
    {
        $container = new Container();

        try {
            $container->get(SelfCycle::class);
            self::fail('Expected CircularDependencyException was not thrown.');
        } catch (CircularDependencyException $exception) {
            self::assertSame([SelfCycle::class, SelfCycle::class], $exception->getChain());
        }
    }

    public function testDetectsIndirectCycleOfLengthThreeThroughInterfaces(): void
    {
        $container = new Container();
        $container->bind(ChainI1::class, Chain1::class);
        $container->bind(ChainI2::class, Chain2::class);
        $container->bind(ChainI3::class, Chain3::class);

        try {
            $container->get(ChainI1::class);
            self::fail('Expected CircularDependencyException was not thrown.');
        } catch (CircularDependencyException $exception) {
            $chain = $exception->getChain();

            self::assertSame(ChainI1::class, $chain[0]);
            self::assertSame(ChainI1::class, end($chain));
            self::assertGreaterThanOrEqual(6, count($chain));
        }
    }

    public function testCircularExceptionMessageContainsReadableChain(): void
    {
        $container = new Container();
        $container->bind(CycleIA::class, CycleA::class);
        $container->bind(CycleIB::class, CycleB::class);

        try {
            $container->get(CycleIA::class);
            self::fail('Expected CircularDependencyException was not thrown.');
        } catch (CircularDependencyException $exception) {
            $message = $exception->getMessage();

            self::assertStringContainsString(' -> ', $message);
            self::assertStringContainsString(CycleIA::class, $message);
            self::assertStringContainsString(CycleIB::class, $message);
            self::assertStringContainsString(CycleA::class, $message);
            self::assertStringContainsString(CycleB::class, $message);
            self::assertStringNotContainsString('stage', $message);
            self::assertSame(
                'Circular dependency detected: ' . implode(' -> ', $exception->getChain()) . '.',
                $message,
            );
            self::assertInstanceOf(ContainerException::class, $exception);
        }
    }

    public function testContainerStaysUsableAfterCaughtCycle(): void
    {
        $container = new Container();

        try {
            $container->get(DirectCycleA::class);
            self::fail('Expected CircularDependencyException was not thrown.');
        } catch (CircularDependencyException $exception) {
            $chainFirst = $exception->getChain();
        }

        $a = $container->get(GraphA::class);

        self::assertInstanceOf(GraphA::class, $a);
        self::assertInstanceOf(GraphB::class, $a->b);
        self::assertInstanceOf(GraphD::class, $a->b->d);

        try {
            $container->get(DirectCycleA::class);
            self::fail('Expected CircularDependencyException was not thrown.');
        } catch (CircularDependencyException $exception) {
            $chainSecond = $exception->getChain();
        }

        self::assertSame($chainFirst, $chainSecond);
    }

    public function testDiamondDependencyIsNotFalselyDetectedAsCycle(): void
    {
        $container = new Container();

        $a = $container->get(DiamondA::class);

        self::assertInstanceOf(DiamondA::class, $a);
        self::assertInstanceOf(DiamondD::class, $a->b->d);
        self::assertInstanceOf(DiamondD::class, $a->c->d);
        self::assertNotSame($a->b->d, $a->c->d);
    }

    public function testSameDependencyTwiceInOneConstructorIsNotCycle(): void
    {
        $container = new Container();

        $n = $container->get(NeedsTwoD::class);

        self::assertInstanceOf(TwiceD::class, $n->first);
        self::assertInstanceOf(TwiceD::class, $n->second);
    }

    public function testDetectsIndirectCycleOfLengthThreeAmongConcreteClasses(): void
    {
        $container = new Container();

        try {
            $container->get(ConcreteCycleA::class);
            self::fail('Expected CircularDependencyException was not thrown.');
        } catch (CircularDependencyException $exception) {
            $chain = $exception->getChain();

            self::assertSame(ConcreteCycleA::class, $chain[0]);
            self::assertSame(ConcreteCycleA::class, end($chain));
            self::assertContains(ConcreteCycleB::class, $chain);
            self::assertContains(ConcreteCycleC::class, $chain);
        }
    }

    public function testResolutionStackIsEmptyAfterSuccessfulGet(): void
    {
        $container = new Container();

        $container->get(DiamondA::class);

        $reflection = new ReflectionObject($container);

        $resolving = $reflection->getProperty('resolving');
        $resolving->setAccessible(true);

        $resolutionChain = $reflection->getProperty('resolutionChain');
        $resolutionChain->setAccessible(true);

        self::assertSame([], $resolving->getValue($container));
        self::assertSame([], $resolutionChain->getValue($container));
    }

    public function testSingletonReturnsSameInstanceOnRepeatedGet(): void
    {
        $container = new Container();
        $container->singleton(SharedService::class);

        $a = $container->get(SharedService::class);
        $b = $container->get(SharedService::class);

        self::assertSame($a, $b);
    }

    public function testTransientBindingReturnsFreshInstanceOnRepeatedGet(): void
    {
        $container = new Container();
        $container->bind(FooInterface::class, Foo::class);

        $a = $container->get(FooInterface::class);
        $b = $container->get(FooInterface::class);

        self::assertNotSame($a, $b);
    }

    public function testSingletonThroughInterfaceBindingReturnsSameInstance(): void
    {
        $container = new Container();
        $container->singleton(FooInterface::class, Foo::class);

        $a = $container->get(FooInterface::class);
        $b = $container->get(FooInterface::class);

        self::assertSame($a, $b);
    }

    public function testSingletonDependencyIsSharedBetweenTwoConsumers(): void
    {
        $container = new Container();
        $container->singleton(SharedDep::class);

        $c1 = $container->get(ConsumerOne::class);
        $c2 = $container->get(ConsumerTwo::class);

        self::assertSame($c1->dep, $c2->dep);
    }

    public function testSingletonInterfaceDependencyIsSharedBetweenTwoConsumers(): void
    {
        $container = new Container();
        $container->singleton(SharedDepInterface::class, SharedDepImpl::class);

        $a = $container->get(NeedsSharedA::class);
        $b = $container->get(NeedsSharedB::class);

        self::assertSame($a->dep, $b->dep);
        self::assertInstanceOf(SharedDepImpl::class, $a->dep);
    }

    public function testTransientDependencyIsNotSharedBetweenConsumers(): void
    {
        $container = new Container();

        $c1 = $container->get(ConsumerOne::class);
        $c2 = $container->get(ConsumerTwo::class);

        self::assertNotSame($c1->dep, $c2->dep);
    }

    public function testConsumerItselfSingletonSharesWholeSubtree(): void
    {
        $container = new Container();
        $container->singleton(ConsumerOne::class);
        $container->singleton(SharedDep::class);

        $x = $container->get(ConsumerOne::class);
        $y = $container->get(ConsumerOne::class);

        self::assertSame($x, $y);
        self::assertSame($x->dep, $y->dep);
    }

    public function testSingletonClassInCycleStillDetectsCycle(): void
    {
        $container = new Container();
        $container->singleton(DirectCycleA::class);

        try {
            $container->get(DirectCycleA::class);
            self::fail('Expected CircularDependencyException was not thrown.');
        } catch (CircularDependencyException $exception) {
            self::assertContains(DirectCycleA::class, $exception->getChain());
        }

        // The second call throws again, proving the failed resolution did not
        // leave a half-built object in the singleton cache.
        try {
            $container->get(DirectCycleA::class);
            self::fail('Expected CircularDependencyException was not thrown.');
        } catch (CircularDependencyException $exception) {
            self::assertContains(DirectCycleA::class, $exception->getChain());
        }
    }

    public function testSingletonCacheDoesNotBreakDiamond(): void
    {
        $container = new Container();
        $container->singleton(DiamondD::class);

        $a = $container->get(DiamondA::class);

        self::assertSame($a->b->d, $a->c->d);
    }

    public function testResolutionStackIsEmptyAfterSingletonGet(): void
    {
        $container = new Container();
        $container->singleton(SharedDep::class);

        $container->get(ConsumerOne::class);

        $reflection = new ReflectionObject($container);

        $resolving = $reflection->getProperty('resolving');
        $resolving->setAccessible(true);

        $resolutionChain = $reflection->getProperty('resolutionChain');
        $resolutionChain->setAccessible(true);

        self::assertSame([], $resolving->getValue($container));
        self::assertSame([], $resolutionChain->getValue($container));
    }

    public function testNullableClassParameterIsResolvedWhenPossible(): void
    {
        $container = new Container();

        $n = $container->get(NeedsNullableFoo::class);

        self::assertInstanceOf(Foo::class, $n->foo);
    }

    public function testNullableInterfaceParameterFallsBackToNullWithoutBinding(): void
    {
        $container = new Container();

        $n = $container->get(NeedsNullableBar::class);

        self::assertNull($n->bar);
    }

    public function testUnionParameterResolvesFirstResolvableClassType(): void
    {
        $container = new Container();
        $container->bind(UPrimary::class, UPrimaryImpl::class);

        $n = $container->get(NeedsClassUnion::class);

        self::assertInstanceOf(UPrimaryImpl::class, $n->svc);
    }

    public function testUnionSkipsUnresolvableFirstMemberAndUsesSecond(): void
    {
        $container = new Container();
        $container->bind(USecondary::class, USecondaryImpl::class);

        $n = $container->get(NeedsUnionOfInterfaces::class);

        self::assertInstanceOf(USecondaryImpl::class, $n->svc);
    }

    public function testUnionWithoutDefaultThrowsWhenNoMemberResolvable(): void
    {
        $container = new Container();

        try {
            $container->get(NeedsClassUnion::class);
            self::fail('Expected UnresolvableParameterException was not thrown.');
        } catch (UnresolvableParameterException $exception) {
            self::assertSame(NeedsClassUnion::class, $exception->getClassName());
            self::assertSame('svc', $exception->getParameterName());
        }
    }

    public function testUnionWithDefaultUsesDefaultWhenNothingResolves(): void
    {
        $container = new Container();

        $n = $container->get(NeedsUnionWithDefault::class);

        self::assertSame(0, $n->v);
    }

    public function testNullableUnionFallsBackToNull(): void
    {
        $container = new Container();

        $n = $container->get(NeedsNullableUnion::class);

        self::assertNull($n->svc);
    }

    public function testVariadicParameterReceivesEmptySet(): void
    {
        $container = new Container();

        $n = $container->get(NeedsVariadic::class);

        self::assertSame([], $n->foos);
    }

    public function testVariadicAfterRequiredParameterStillBuilds(): void
    {
        $container = new Container();

        $n = $container->get(NeedsClassThenVariadic::class);

        self::assertInstanceOf(GraphD::class, $n->d);
        self::assertSame([], $n->foos);
    }

    public function testTaggedVariadicReceivesAllTaggedMembers(): void
    {
        $container = new Container();
        $container->tag('handlers', [TagHandlerA::class, TagHandlerB::class, TagHandlerC::class]);

        $consumer = $container->get(NeedsTaggedVariadic::class);

        self::assertCount(3, $consumer->handlers);
        self::assertContainsOnlyInstancesOf(TagHandler::class, $consumer->handlers);
        self::assertSame(
            [TagHandlerA::class, TagHandlerB::class, TagHandlerC::class],
            array_map(static fn (TagHandler $h): string => $h::class, $consumer->handlers),
        );
    }

    public function testTaggedCollectionPreservesRegistrationOrder(): void
    {
        $container = new Container();
        $container->tag('handlers', [TagHandlerB::class]);
        $container->tag('handlers', [TagHandlerC::class, TagHandlerA::class]);

        $consumer = $container->get(NeedsTaggedVariadic::class);

        // Several tag() calls concatenate in call order; within one call the
        // array order wins. No sorting of any kind is applied.
        self::assertSame(
            [TagHandlerB::class, TagHandlerC::class, TagHandlerA::class],
            array_map(static fn (TagHandler $h): string => $h::class, $consumer->handlers),
        );
    }

    public function testTaggedCollectionDeduplicatesMembers(): void
    {
        $container = new Container();
        $container->tag('handlers', [TagHandlerA::class, TagHandlerB::class]);
        $container->tag('handlers', [TagHandlerA::class, TagHandlerC::class]);

        $consumer = $container->get(NeedsTaggedVariadic::class);

        // A class registered twice enters the collection once, at the
        // position of its first occurrence.
        self::assertSame(
            [TagHandlerA::class, TagHandlerB::class, TagHandlerC::class],
            array_map(static fn (TagHandler $h): string => $h::class, $consumer->handlers),
        );
    }

    public function testTaggedVariadicWithUnregisteredTagReceivesEmptySet(): void
    {
        $container = new Container();

        $consumer = $container->get(NeedsTaggedVariadic::class);

        // Zero members is a legal state of a collection, not an error: the
        // application simply has no handlers yet.
        self::assertSame([], $consumer->handlers);
    }

    public function testUntaggedVariadicStillReceivesEmptySet(): void
    {
        $container = new Container();
        $container->tag('some.other.tag', [TagHandlerA::class]);

        // Registered tags must not leak into a variadic that never asked for
        // one: the Stage 5 empty-set default is untouched.
        $n = $container->get(NeedsVariadic::class);

        self::assertSame([], $n->foos);
    }

    public function testTaggedMemberThatCannotBeBuiltFailsWholeResolution(): void
    {
        $container = new Container();
        $container->tag('handlers', [TagHandlerA::class, TagBrokenHandler::class]);

        // A registered member is a deliberate part of the configuration, so
        // its failure must surface instead of silently shortening the set.
        $this->expectException(UnresolvableParameterException::class);

        $container->get(NeedsTaggedVariadic::class);
    }

    public function testTaggedSingletonMemberIsSharedAcrossConsumers(): void
    {
        $container = new Container();
        $container->tag('handlers', [TagSharedHandler::class, TagHandlerA::class]);

        $first = $container->get(NeedsTaggedVariadic::class);
        $second = $container->get(NeedsTaggedVariadic::class);

        // Members resolve through ordinary get(), so each keeps its own
        // lifecycle: the #[Singleton] member is one instance for everybody,
        // the transient member is fresh per consumer.
        self::assertSame($first->handlers[0], $second->handlers[0]);
        self::assertNotSame($first->handlers[1], $second->handlers[1]);
    }

    public function testCycleThroughTaggedMemberIsDetected(): void
    {
        $container = new Container();
        $container->tag('cycle.handlers', [TagCycleMember::class]);

        try {
            $container->get(NeedsCycleTagged::class);
            self::fail('Expected CircularDependencyException was not thrown.');
        } catch (CircularDependencyException $exception) {
            self::assertSame(
                [NeedsCycleTagged::class, TagCycleMember::class, NeedsCycleTagged::class],
                $exception->getChain(),
            );
        }

        // The stack must be clean afterwards: a valid graph still resolves.
        self::assertInstanceOf(Foo::class, $container->get(Foo::class));

        $reflection = new ReflectionObject($container);

        self::assertSame([], $reflection->getProperty('resolving')->getValue($container));
        self::assertSame([], $reflection->getProperty('resolutionChain')->getValue($container));
    }

    public function testTaggedAttributeOnNonVariadicParameterIsIgnored(): void
    {
        $container = new Container();
        $container->tag('handlers', [TagHandlerA::class, TagHandlerB::class]);

        // Silently ignored, consistent with #[Inject] outside the class-type
        // branch: the parameter resolves by its type as if unannotated.
        $consumer = $container->get(TaggedOnNonVariadic::class);

        self::assertInstanceOf(Foo::class, $consumer->foo);
    }

    public function testResolutionStackIsEmptyAfterNullableFallbackToNull(): void
    {
        $container = new Container();

        $container->get(NeedsNullableBar::class);

        $reflection = new ReflectionObject($container);

        $resolving = $reflection->getProperty('resolving');
        $resolving->setAccessible(true);

        $resolutionChain = $reflection->getProperty('resolutionChain');
        $resolutionChain->setAccessible(true);

        self::assertSame([], $resolving->getValue($container));
        self::assertSame([], $resolutionChain->getValue($container));
    }

    public function testContainerImplementsPsr11ContainerInterface(): void
    {
        self::assertInstanceOf(ContainerInterface::class, new Container());
    }

    public function testResolvesThroughPsr11ContainerInterfaceType(): void
    {
        $container = new Container();
        $container->bind(FooInterface::class, Foo::class);

        /** @var ContainerInterface $psr11 */
        $psr11 = $container;

        self::assertTrue($psr11->has(FooInterface::class));
        self::assertInstanceOf(Foo::class, $psr11->get(FooInterface::class));
    }

    public function testNotFoundExceptionImplementsPsr11NotFoundExceptionInterface(): void
    {
        $container = new Container();

        try {
            $container->get('This\\Class\\Does\\Not\\Exist');
            self::fail('Expected NotFoundException was not thrown.');
        } catch (NotFoundException $exception) {
            self::assertInstanceOf(NotFoundExceptionInterface::class, $exception);
            self::assertInstanceOf(ContainerExceptionInterface::class, $exception);
        }
    }

    public function testNotInstantiableExceptionIsPsr11ContainerExceptionButNotNotFound(): void
    {
        $container = new Container();

        try {
            $container->get(AbstractThing::class);
            self::fail('Expected NotInstantiableException was not thrown.');
        } catch (NotInstantiableException $exception) {
            self::assertInstanceOf(ContainerExceptionInterface::class, $exception);
            self::assertNotInstanceOf(NotFoundExceptionInterface::class, $exception);
        }
    }

    public function testCircularDependencyExceptionIsPsr11ContainerExceptionButNotNotFound(): void
    {
        $container = new Container();

        try {
            $container->get(DirectCycleA::class);
            self::fail('Expected CircularDependencyException was not thrown.');
        } catch (CircularDependencyException $exception) {
            self::assertInstanceOf(ContainerExceptionInterface::class, $exception);
            self::assertNotInstanceOf(NotFoundExceptionInterface::class, $exception);
        }
    }

    public function testUnresolvableParameterExceptionIsPsr11ContainerExceptionButNotNotFound(): void
    {
        $container = new Container();

        try {
            $container->get(NeedsBuiltinNoDefault::class);
            self::fail('Expected UnresolvableParameterException was not thrown.');
        } catch (UnresolvableParameterException $exception) {
            self::assertInstanceOf(ContainerExceptionInterface::class, $exception);
            self::assertNotInstanceOf(NotFoundExceptionInterface::class, $exception);
        }
    }

    public function testInjectAttributeResolvesConcreteForAmbiguousInterface(): void
    {
        $container = new Container();

        $n = $container->get(NeedsInjectedImpl::class);

        self::assertInstanceOf(StripeGateway::class, $n->gw);
    }

    public function testExplicitBindOverridesInjectAttribute(): void
    {
        $container = new Container();
        $container->bind(PaymentGateway::class, PaypalGateway::class);

        $n = $container->get(NeedsInjectedImpl::class);

        self::assertInstanceOf(PaypalGateway::class, $n->gw);
    }

    public function testSingletonAttributeSharesInstanceWhenResolvedByClass(): void
    {
        $container = new Container();

        $a = $container->get(AttrSingleton::class);
        $b = $container->get(AttrSingleton::class);

        self::assertSame($a, $b);
    }

    public function testSingletonAttributeThroughInterfaceBindingCachesUnderRequestId(): void
    {
        $container = new Container();
        $container->bind(AttrSharedInterface::class, AttrSharedImpl::class);

        $a1 = $container->get(AttrSharedInterface::class);
        $a2 = $container->get(AttrSharedInterface::class);
        $direct = $container->get(AttrSharedImpl::class);

        self::assertSame($a1, $a2);
        self::assertNotSame($a1, $direct);
    }

    public function testExplicitSingletonAndSingletonAttributeAgreeWithoutConflict(): void
    {
        $container = new Container();
        $container->singleton(AttrSingleton::class);

        $a = $container->get(AttrSingleton::class);
        $b = $container->get(AttrSingleton::class);

        self::assertSame($a, $b);
    }

    public function testClassWithoutAttributesResolvesAsBefore(): void
    {
        $container = new Container();

        $a = $container->get(GraphA::class);
        $b = $container->get(GraphA::class);

        self::assertNotSame($a, $b);
    }

    public function testDetectsCycleThroughInjectAttribute(): void
    {
        $container = new Container();

        try {
            $container->get(InjectCycleA::class);
            self::fail('Expected CircularDependencyException was not thrown.');
        } catch (CircularDependencyException $exception) {
            $chain = $exception->getChain();

            self::assertContains(InjectCycleA::class, $chain);
            self::assertContains(InjectCycleB::class, $chain);
        }
    }

    public function testResolutionStackEmptyAfterInjectResolution(): void
    {
        $container = new Container();

        $container->get(NeedsInjectedImpl::class);

        $reflection = new ReflectionObject($container);

        $resolving = $reflection->getProperty('resolving');
        $resolving->setAccessible(true);

        $resolutionChain = $reflection->getProperty('resolutionChain');
        $resolutionChain->setAccessible(true);

        self::assertSame([], $resolving->getValue($container));
        self::assertSame([], $resolutionChain->getValue($container));
    }

    public function testLogsResolutionRequest(): void
    {
        $logger = new SpyLogger();
        $container = new Container($logger);

        $container->get(NoConstructor::class);

        $record = self::singleRecordMatching($logger, 'Resolving {id}');

        self::assertSame(LogLevel::DEBUG, $record['level']);
        self::assertSame(NoConstructor::class, $record['context']['id']);
        self::assertSame(0, $record['context']['depth']);
    }

    public function testLogsSharedInstanceCacheHit(): void
    {
        $logger = new SpyLogger();
        $container = new Container($logger);
        $container->singleton(SharedService::class);

        $container->get(SharedService::class);
        $container->get(SharedService::class);

        $record = self::singleRecordMatching($logger, 'from cache');

        self::assertSame(LogLevel::DEBUG, $record['level']);
        self::assertSame(SharedService::class, $record['context']['id']);
        self::assertSame(0, $record['context']['depth']);

        // A cache hit must still announce the request: the guarantee is that
        // every get() is visible in the log, not just the ones that build.
        self::assertCount(2, self::recordsMatching($logger, 'Resolving {id}'));
    }

    public function testLogsBindingApplication(): void
    {
        $logger = new SpyLogger();
        $container = new Container($logger);
        $container->bind(FooInterface::class, Foo::class);

        $container->get(FooInterface::class);

        $record = self::singleRecordMatching($logger, 'Binding applied');

        self::assertSame(LogLevel::DEBUG, $record['level']);
        self::assertSame(FooInterface::class, $record['context']['id']);
        self::assertSame(Foo::class, $record['context']['concrete']);
        self::assertSame(0, $record['context']['depth']);
    }

    public function testLogsInjectAttributeApplication(): void
    {
        $logger = new SpyLogger();
        $container = new Container($logger);

        $container->get(NeedsInjectedImpl::class);

        $record = self::singleRecordMatching($logger, 'Inject attribute applied');

        self::assertSame(LogLevel::DEBUG, $record['level']);
        self::assertSame('gw', $record['context']['parameter']);
        self::assertSame(NeedsInjectedImpl::class, $record['context']['class']);
        self::assertSame(StripeGateway::class, $record['context']['target']);
        self::assertSame(1, $record['context']['depth']);
    }

    public function testLogsTaggedCollectionResolution(): void
    {
        $logger = new SpyLogger();
        $container = new Container($logger);
        $container->tag('handlers', [TagHandlerA::class, TagHandlerB::class]);

        $container->get(NeedsTaggedVariadic::class);

        $record = self::singleRecordMatching($logger, 'Tagged collection');

        self::assertSame('handlers', $record['context']['parameter']);
        self::assertSame(NeedsTaggedVariadic::class, $record['context']['class']);
        self::assertSame('handlers', $record['context']['tag']);
        self::assertSame(2, $record['context']['count']);
        self::assertSame(1, $record['context']['depth']);
    }

    public function testLogsSharedCachingReasonForSingletonMethod(): void
    {
        $logger = new SpyLogger();
        $container = new Container($logger);
        $container->singleton(SharedService::class);

        $container->get(SharedService::class);

        $record = self::singleRecordMatching($logger, 'Caching shared instance');

        self::assertSame(SharedService::class, $record['context']['id']);
        self::assertSame('singleton()', $record['context']['reason']);
        self::assertSame(0, $record['context']['depth']);
    }

    public function testLogsSharedCachingReasonForSingletonAttribute(): void
    {
        $logger = new SpyLogger();
        $container = new Container($logger);

        $container->get(AttrSingleton::class);

        $record = self::singleRecordMatching($logger, 'Caching shared instance');

        self::assertSame(AttrSingleton::class, $record['context']['id']);
        self::assertSame('#[Singleton]', $record['context']['reason']);
    }

    /**
     * singleton() and #[Singleton] are an OR, so when both apply the instance is
     * shared either way and only the reported reason is at stake. singleton() is
     * checked first deliberately: it short-circuits before hasSingletonAttribute()
     * builds a ReflectionClass, so the order is a hot-path decision, not cosmetics.
     */
    public function testReportsSingletonMethodAsReasonWhenAttributeAlsoApplies(): void
    {
        $logger = new SpyLogger();
        $container = new Container($logger);
        $container->singleton(AttrSingleton::class);

        $container->get(AttrSingleton::class);

        $record = self::singleRecordMatching($logger, 'Caching shared instance');

        self::assertSame('singleton()', $record['context']['reason']);
    }

    public function testLogsCircularDependencyChain(): void
    {
        $logger = new SpyLogger();
        $container = new Container($logger);

        try {
            $container->get(ConcreteCycleA::class);
            self::fail('Expected CircularDependencyException was not thrown.');
        } catch (CircularDependencyException) {
            // The record, not the exception, is what this test is about.
        }

        $record = self::singleRecordMatching($logger, 'Circular dependency detected');

        self::assertSame(LogLevel::DEBUG, $record['level']);
        self::assertSame(ConcreteCycleA::class, $record['context']['id']);

        $chain = $record['context']['chain'];

        self::assertStringContainsString(ConcreteCycleA::class, $chain);
        self::assertStringContainsString(ConcreteCycleB::class, $chain);
        self::assertStringContainsString(ConcreteCycleC::class, $chain);
        self::assertStringContainsString(' -> ', $chain);

        // depth is the level the repeated id would have occupied, so the chain,
        // which already includes that id, is exactly one entry longer.
        self::assertCount($record['context']['depth'] + 1, explode(' -> ', $chain));
    }

    public function testLogsResolutionDepthForNestedGraph(): void
    {
        $logger = new SpyLogger();
        $container = new Container($logger);

        $container->get(GraphA::class);

        $depths = [];

        foreach (self::recordsMatching($logger, 'Resolving {id}') as $record) {
            $depths[$record['context']['id']] = $record['context']['depth'];
        }

        self::assertSame(0, $depths[GraphA::class]);
        self::assertSame(1, $depths[GraphB::class]);
        self::assertSame(1, $depths[GraphC::class]);
        self::assertSame(2, $depths[GraphD::class]);
    }

    public function testAllLogRecordsUseDebugLevel(): void
    {
        $logger = self::exerciseEveryLoggedEvent();

        self::assertNotSame([], $logger->records);

        foreach ($logger->records as $record) {
            self::assertSame(LogLevel::DEBUG, $record['level'], $record['message']);
        }
    }

    public function testEveryLogPlaceholderHasMatchingContextKey(): void
    {
        $logger = self::exerciseEveryLoggedEvent();

        self::assertNotSame([], $logger->records);

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
     * The logger parameter is nullable on purpose. A non-nullable class-typed
     * constructor parameter is resolved through get() before any default value
     * is considered, so `= new NullLogger()` would make the container unable to
     * autowire itself or anything depending on it — LoggerInterface has no
     * binding and is not instantiable. Nullable falls back to null instead.
     */
    public function testContainerStillAutowiresItselfAndItsDependents(): void
    {
        $container = new Container();

        self::assertInstanceOf(Container::class, $container->get(Container::class));
        self::assertInstanceOf(NeedsContainer::class, $container->get(NeedsContainer::class));
    }

    public function testExplicitLoggerBindingIsInjectedWhenContainerIsAutowired(): void
    {
        $logger = new SpyLogger();
        $container = new Container($logger);
        $container->bind(LoggerInterface::class, SpyLogger::class);

        $resolved = $container->get(NeedsContainer::class);

        // The outer container's own logger is not propagated: the nested one is
        // autowired from the binding, like any other dependency.
        self::assertInstanceOf(Container::class, $resolved->container);
    }

    public function testContainerWithoutLoggerBehavesAsBefore(): void
    {
        $container = new Container();
        $container->bind(FooInterface::class, Foo::class);
        $container->singleton(SharedService::class);

        self::assertInstanceOf(Foo::class, $container->get(FooInterface::class));
        self::assertInstanceOf(GraphA::class, $container->get(GraphA::class));
        self::assertSame($container->get(SharedService::class), $container->get(SharedService::class));
        self::assertNotSame($container->get(GraphC::class), $container->get(GraphC::class));

        $this->expectException(CircularDependencyException::class);

        $container->get(ConcreteCycleA::class);
    }

    /**
     * Drive one container through every kind of event the container logs, so
     * cross-cutting assertions cover all message shapes at once.
     */
    private static function exerciseEveryLoggedEvent(): SpyLogger
    {
        $logger = new SpyLogger();
        $container = new Container($logger);
        $container->bind(FooInterface::class, Foo::class);
        $container->singleton(SharedService::class);

        $container->get(FooInterface::class);
        $container->get(SharedService::class);
        $container->get(SharedService::class);
        $container->get(AttrSingleton::class);
        $container->get(NeedsInjectedImpl::class);

        $container->tag('handlers', [TagHandlerA::class]);
        $container->get(NeedsTaggedVariadic::class);

        try {
            $container->get(ConcreteCycleA::class);
        } catch (CircularDependencyException) {
            // Expected: the cycle record is part of the set under test.
        }

        // Without this the helper silently stops being exhaustive as soon as a
        // log point is added, and the cross-cutting tests above would keep
        // passing while no longer covering everything they claim to. The count
        // covers the resolution log points only: the listener-suppression
        // record needs a dispatcher, which this helper deliberately omits.
        $shapes = array_unique(array_column($logger->records, 'message'));

        self::assertCount(
            7,
            $shapes,
            'The helper must exercise every log point; update it when adding one.',
        );

        return $logger;
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    private static function recordsMatching(SpyLogger $logger, string $needle): array
    {
        return array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => str_contains($record['message'], $needle),
        ));
    }

    /**
     * @return array{level: mixed, message: string, context: array<string, mixed>}
     */
    private static function singleRecordMatching(SpyLogger $logger, string $needle): array
    {
        $matches = self::recordsMatching($logger, $needle);

        self::assertCount(1, $matches, sprintf('Expected exactly one record matching "%s".', $needle));

        return $matches[0];
    }

    /**
     * The marker is the hook a type-based listener provider subscribes to, so
     * dropping it from any event would silently unsubscribe such a listener.
     */
    public function testEveryDispatchedEventImplementsTheContainerEventMarker(): void
    {
        $events = new SpyEventDispatcher();
        $container = new Container(null, $events);

        $container->get(GraphA::class);

        try {
            $container->get(ConcreteCycleA::class);
        } catch (CircularDependencyException) {
            // Expected: failure events belong to the set under test.
        }

        self::assertNotSame([], $events->events);

        foreach ($events->events as $event) {
            self::assertInstanceOf(ContainerEvent::class, $event);
        }
    }

    public function testDispatchesResolutionRequestedForEveryGetIncludingNested(): void
    {
        $events = new SpyEventDispatcher();
        $container = new Container(null, $events);

        $container->get(GraphA::class);

        $requested = self::eventsOf($events, ResolutionRequested::class);

        // Order matters as much as the count: the outer frame is announced
        // before its dependencies, which is what makes the stream readable as
        // a resolution tree.
        self::assertSame(
            [
                [GraphA::class, 0],
                [GraphB::class, 1],
                [GraphD::class, 2],
                [GraphC::class, 1],
            ],
            array_map(
                static fn (ResolutionRequested $event): array => [$event->id, $event->depth],
                $requested,
            ),
        );
    }

    public function testDispatchesServiceResolvedWithBuiltInstance(): void
    {
        $events = new SpyEventDispatcher();
        $container = new Container(null, $events);

        $instance = $container->get(GraphB::class);

        $resolved = self::eventsOf($events, ServiceResolved::class);

        // The nested frame finishes first, so the requested id closes the list.
        self::assertCount(2, $resolved);
        self::assertSame(GraphD::class, $resolved[0]->id);

        self::assertSame(GraphB::class, $resolved[1]->id);
        self::assertSame(0, $resolved[1]->depth);
        self::assertSame($instance, $resolved[1]->instance);
        self::assertFalse($resolved[1]->fromCache);
    }

    public function testDispatchesServiceResolvedFromCacheOnSharedHit(): void
    {
        $events = new SpyEventDispatcher();
        $container = new Container(null, $events);
        $container->singleton(SharedService::class);

        $first = $container->get(SharedService::class);
        $second = $container->get(SharedService::class);

        self::assertCount(2, self::eventsOf($events, ResolutionRequested::class));

        $resolved = self::eventsOf($events, ServiceResolved::class);

        self::assertCount(2, $resolved);
        self::assertFalse($resolved[0]->fromCache);
        self::assertSame($first, $resolved[0]->instance);
        self::assertTrue($resolved[1]->fromCache);
        self::assertSame($second, $resolved[1]->instance);
        self::assertSame($first, $second);

        $built = array_filter(
            $resolved,
            static fn (ServiceResolved $event): bool => !$event->fromCache,
        );

        self::assertCount(1, $built, 'A shared id must be reported as built exactly once.');
    }

    /**
     * The pairing invariant is the whole point of the events: it is what the
     * log cannot give, and what request counters, resolution trees and timing
     * spans are built on.
     */
    public function testEveryResolutionRequestedGetsExactlyOneTerminalEvent(): void
    {
        $events = new SpyEventDispatcher();
        $container = new Container(null, $events);
        $container->bind(FooInterface::class, Foo::class);
        $container->singleton(SharedService::class);

        $container->get(GraphA::class);
        $container->get(NeedsFoo::class);
        $container->get(SharedService::class);
        $container->get(SharedService::class);

        try {
            $container->get(ConcreteCycleA::class);
        } catch (CircularDependencyException) {
            // Expected: the failing frames belong to the set under test.
        }

        $requested = self::eventsOf($events, ResolutionRequested::class);
        $resolved = self::eventsOf($events, ServiceResolved::class);
        $failed = self::eventsOf($events, ResolutionFailed::class);

        self::assertCount(count($resolved) + count($failed), $requested);
        self::assertSame(
            self::tallyByIdAndDepth($requested),
            self::tallyByIdAndDepth([...$resolved, ...$failed]),
        );
    }

    public function testDispatchesResolutionFailedOnCircularDependencyPerFrame(): void
    {
        $events = new SpyEventDispatcher();
        $container = new Container(null, $events);

        $caught = null;

        try {
            $container->get(ConcreteCycleA::class);
        } catch (CircularDependencyException $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(CircularDependencyException::class, $caught);

        $failed = self::eventsOf($events, ResolutionFailed::class);

        // One event per active frame, innermost first: the depth decreases as
        // the stack unwinds.
        self::assertSame(
            [
                [ConcreteCycleA::class, 3],
                [ConcreteCycleC::class, 2],
                [ConcreteCycleB::class, 1],
                [ConcreteCycleA::class, 0],
            ],
            array_map(
                static fn (ResolutionFailed $event): array => [$event->id, $event->depth],
                $failed,
            ),
        );

        foreach ($failed as $event) {
            self::assertSame($caught, $event->exception);
        }

        self::assertSame([], self::eventsOf($events, ServiceResolved::class));
    }

    public function testDispatchesResolutionFailedOnNotFound(): void
    {
        $events = new SpyEventDispatcher();
        $container = new Container(null, $events);

        try {
            $container->get('AutowirePHP\Tests\NoSuchService');
        } catch (NotFoundException) {
            // Expected: the throw is what the event reports.
        }

        $failed = self::eventsOf($events, ResolutionFailed::class);

        self::assertCount(1, $failed);
        self::assertSame('AutowirePHP\Tests\NoSuchService', $failed[0]->id);
        self::assertSame(0, $failed[0]->depth);
        self::assertInstanceOf(NotFoundException::class, $failed[0]->exception);
    }

    /**
     * A failed frame does not imply a failed call: the nullable probe really
     * did fail for BarInterface, and the parent frame then chose the fallback.
     */
    public function testDispatchesResolutionFailedForRecoveredNullableProbe(): void
    {
        $events = new SpyEventDispatcher();
        $container = new Container(null, $events);

        $instance = $container->get(NeedsNullableBar::class);

        self::assertNull($instance->bar);

        $failed = self::eventsOf($events, ResolutionFailed::class);

        self::assertCount(1, $failed);
        self::assertSame(BarInterface::class, $failed[0]->id);
        self::assertSame(1, $failed[0]->depth);
        self::assertInstanceOf(NotInstantiableException::class, $failed[0]->exception);

        $resolved = self::eventsOf($events, ServiceResolved::class);

        self::assertCount(1, $resolved);
        self::assertSame(NeedsNullableBar::class, $resolved[0]->id);
        self::assertSame(0, $resolved[0]->depth);
        self::assertSame($instance, $resolved[0]->instance);
    }

    public function testListenerFailureSurfacesAsListenerException(): void
    {
        $container = new Container(null, new ThrowingDispatcher(
            static fn (): Throwable => new RuntimeException('listener is broken'),
        ));

        try {
            $container->get(NoConstructor::class);

            self::fail('Expected the listener failure to reach the caller.');
        } catch (ListenerException $exception) {
            self::assertInstanceOf(ResolutionRequested::class, $exception->getEvent());
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            self::assertSame('listener is broken', $exception->getPrevious()->getMessage());
        }
    }

    /**
     * The success event is dispatched outside the frame's try/catch on purpose.
     * Were it inside, a listener failure would be caught as if the container had
     * failed, and the frame would report both success and failure.
     */
    public function testFailingSuccessListenerDoesNotProduceAFailureEvent(): void
    {
        $dispatcher = new ThrowingDispatcher(
            static fn (object $event): ?Throwable => $event instanceof ServiceResolved
                ? new RuntimeException('listener is broken')
                : null,
        );
        $container = new Container(null, $dispatcher);

        try {
            $container->get(NoConstructor::class);

            self::fail('Expected the listener failure to reach the caller.');
        } catch (ListenerException) {
            // Expected: what matters is which events were dispatched.
        }

        self::assertSame([], self::eventsOf($dispatcher, ResolutionFailed::class));
    }

    /**
     * ListenerException must not carry the ContainerException marker, or the
     * nullable probe of an enclosing frame would absorb it and hand back null.
     */
    public function testListenerExceptionIsNotAbsorbedByNullableProbe(): void
    {
        $container = new Container(null, new ThrowingDispatcher(
            static fn (object $event): ?Throwable => $event instanceof ResolutionRequested
                && $event->id === BarInterface::class
                    ? new RuntimeException('listener is broken')
                    : null,
        ));

        self::assertNotInstanceOf(ContainerException::class, new ListenerException(
            new ResolutionRequested(BarInterface::class, 0),
            new RuntimeException(),
        ));

        $this->expectException(ListenerException::class);

        $container->get(NeedsNullableBar::class);
    }

    /**
     * The regression this guards: a listener throwing a ContainerException
     * subclass used to replace the in-flight exception, after which the parent's
     * nullable probe swallowed it and returned an object with a null dependency
     * instead of reporting the cycle.
     */
    public function testListenerFailureNeverSuppressesTheResolutionFailure(): void
    {
        $container = new Container(null, new ThrowingDispatcher(
            static fn (object $event): ?Throwable => $event instanceof ResolutionFailed
                ? new NotFoundException('Unrelated')
                : null,
        ));
        $container->bind(CycleThroughNullable::class, NullableCycleImpl::class);

        $this->expectException(CircularDependencyException::class);

        $container->get(CycleThroughNullable::class);
    }

    public function testServiceResolvedListenerCanReenterContainerAtTopLevel(): void
    {
        $events = new ReenteringDispatcher();
        $container = new Container(null, $events);
        $events->container = $container;

        // Terminal events are dispatched after leave(), so the id is no longer
        // on the resolution path and a top-level listener may ask for it again.
        $instance = $container->get(NoConstructor::class);

        self::assertInstanceOf(NoConstructor::class, $instance);
        self::assertTrue($events->reentered);
        self::assertInstanceOf(NoConstructor::class, $events->reenteredInstance);
        self::assertNotSame($instance, $events->reenteredInstance);
    }

    /**
     * Mirror of testContainerWithoutLoggerBehavesAsBefore: with no dispatcher
     * the nullsafe dispatch short-circuits and nothing about resolution changes.
     */
    public function testContainerWithoutDispatcherBehavesAsBefore(): void
    {
        foreach ([new Container(), new Container(new SpyLogger())] as $container) {
            $container->bind(FooInterface::class, Foo::class);
            $container->singleton(SharedService::class);

            self::assertInstanceOf(Foo::class, $container->get(FooInterface::class));
            self::assertInstanceOf(GraphA::class, $container->get(GraphA::class));
            self::assertSame($container->get(SharedService::class), $container->get(SharedService::class));
            self::assertNotSame($container->get(GraphC::class), $container->get(GraphC::class));

            try {
                $container->get(ConcreteCycleA::class);

                self::fail('Expected a CircularDependencyException without a dispatcher.');
            } catch (CircularDependencyException) {
                // Expected: cycle detection is untouched by the events.
            }
        }
    }

    public function testEventsAndLogRecordsCoexistOnOneResolution(): void
    {
        $logger = new SpyLogger();
        $events = new SpyEventDispatcher();
        $container = new Container($logger, $events);

        $container->get(GraphB::class);

        self::assertCount(2, self::recordsMatching($logger, 'Resolving {id}'));
        self::assertCount(2, self::eventsOf($events, ResolutionRequested::class));
        self::assertCount(2, self::eventsOf($events, ServiceResolved::class));
    }

    /**
     * @template TEvent of object
     * @param class-string<TEvent> $class
     * @return list<TEvent>
     */
    private static function eventsOf(SpyEventDispatcher|ThrowingDispatcher $dispatcher, string $class): array
    {
        return array_values(array_filter(
            $dispatcher->events,
            static fn (object $event): bool => $event instanceof $class,
        ));
    }

    /**
     * Count events per (id, depth) pair, so requested and terminal events can
     * be compared as multisets rather than by position.
     *
     * @param list<ResolutionRequested|ResolutionFailed|ServiceResolved> $events
     * @return array<string, int>
     */
    private static function tallyByIdAndDepth(array $events): array
    {
        $counts = [];

        foreach ($events as $event) {
            $key = $event->id . '@' . $event->depth;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }
}

// Fixture classes for the resolution scenarios above. Kept in the same file so
// the dependency graph under test is visible at a glance.

interface FooInterface
{
}

final class Foo implements FooInterface
{
}

final class NoConstructor
{
}

final class EmptyConstructor
{
    public function __construct()
    {
    }
}

final class OptionalParamsConstructor
{
    public function __construct(int $x = 5, string $s = 'a')
    {
    }
}

abstract class AbstractThing
{
}

final class PrivateConstructor
{
    private function __construct()
    {
    }
}

final class GraphD
{
}

final class GraphB
{
    public function __construct(public readonly GraphD $d)
    {
    }
}

final class GraphC
{
}

final class GraphA
{
    public function __construct(public readonly GraphB $b, public readonly GraphC $c)
    {
    }
}

final class NeedsFoo
{
    public function __construct(public readonly FooInterface $foo)
    {
    }
}

final class NeedsBuiltinNoDefault
{
    public function __construct(public readonly int $count)
    {
    }
}

final class NeedsUntyped
{
    public function __construct($whatever)
    {
    }
}

final class MixedClassAndDefault
{
    public function __construct(public readonly GraphD $d, public readonly int $x = 7)
    {
    }
}

final class NeedsUnion
{
    public function __construct(int|string $v)
    {
    }
}

final class DirectCycleA
{
    public function __construct(public readonly DirectCycleB $b)
    {
    }
}

final class DirectCycleB
{
    public function __construct(public readonly DirectCycleA $a)
    {
    }
}

interface CycleIA
{
}

interface CycleIB
{
}

final class CycleA implements CycleIA
{
    public function __construct(public readonly CycleIB $b)
    {
    }
}

final class CycleB implements CycleIB
{
    public function __construct(public readonly CycleIA $a)
    {
    }
}

final class SelfCycle
{
    public function __construct(public readonly SelfCycle $self)
    {
    }
}

interface ChainI1
{
}

interface ChainI2
{
}

interface ChainI3
{
}

final class Chain1 implements ChainI1
{
    public function __construct(public readonly ChainI2 $b)
    {
    }
}

final class Chain2 implements ChainI2
{
    public function __construct(public readonly ChainI3 $c)
    {
    }
}

final class Chain3 implements ChainI3
{
    public function __construct(public readonly ChainI1 $a)
    {
    }
}

final class DiamondD
{
}

final class DiamondB
{
    public function __construct(public readonly DiamondD $d)
    {
    }
}

final class DiamondC
{
    public function __construct(public readonly DiamondD $d)
    {
    }
}

final class DiamondA
{
    public function __construct(
        public readonly DiamondB $b,
        public readonly DiamondC $c,
    ) {
    }
}

final class TwiceD
{
}

final class NeedsTwoD
{
    public function __construct(
        public readonly TwiceD $first,
        public readonly TwiceD $second,
    ) {
    }
}

final class ConcreteCycleA
{
    public function __construct(public readonly ConcreteCycleB $b)
    {
    }
}

final class ConcreteCycleB
{
    public function __construct(public readonly ConcreteCycleC $c)
    {
    }
}

final class ConcreteCycleC
{
    public function __construct(public readonly ConcreteCycleA $a)
    {
    }
}

final class SharedService
{
}

final class SharedDep
{
}

final class ConsumerOne
{
    public function __construct(public readonly SharedDep $dep)
    {
    }
}

final class ConsumerTwo
{
    public function __construct(public readonly SharedDep $dep)
    {
    }
}

interface SharedDepInterface
{
}

final class SharedDepImpl implements SharedDepInterface
{
}

final class NeedsSharedA
{
    public function __construct(public readonly SharedDepInterface $dep)
    {
    }
}

final class NeedsSharedB
{
    public function __construct(public readonly SharedDepInterface $dep)
    {
    }
}

final class NeedsNullableFoo
{
    public function __construct(public readonly ?Foo $foo)
    {
    }
}

interface BarInterface
{
}

final class NeedsNullableBar
{
    public function __construct(public readonly ?BarInterface $bar)
    {
    }
}

interface UPrimary
{
}

interface USecondary
{
}

final class UPrimaryImpl implements UPrimary
{
}

final class USecondaryImpl implements USecondary
{
}

final class NeedsClassUnion
{
    public function __construct(public readonly UPrimary|USecondary $svc)
    {
    }
}

final class NeedsUnionWithDefault
{
    public function __construct(public readonly int|string $v = 0)
    {
    }
}

final class NeedsUnionOfInterfaces
{
    public function __construct(public readonly UPrimary|USecondary $svc)
    {
    }
}

final class NeedsVariadic
{
    public array $foos;

    public function __construct(Foo ...$foos)
    {
        $this->foos = $foos;
    }
}

final class NeedsClassThenVariadic
{
    public array $foos;

    public function __construct(public readonly GraphD $d, Foo ...$foos)
    {
        $this->foos = $foos;
    }
}

final class NeedsNullableUnion
{
    public function __construct(public readonly UPrimary|USecondary|null $svc)
    {
    }
}

interface PaymentGateway
{
}

final class StripeGateway implements PaymentGateway
{
}

final class PaypalGateway implements PaymentGateway
{
}

final class NeedsInjectedImpl
{
    public function __construct(
        #[Inject(StripeGateway::class)] public readonly PaymentGateway $gw,
    ) {
    }
}

#[Singleton]
final class AttrSingleton
{
}

interface AttrSharedInterface
{
}

#[Singleton]
final class AttrSharedImpl implements AttrSharedInterface
{
}

interface InjectCycleMarker
{
}

final class InjectCycleA implements InjectCycleMarker
{
    public function __construct(
        #[Inject(InjectCycleB::class)] public readonly InjectCycleMarker $b,
    ) {
    }
}

final class InjectCycleB implements InjectCycleMarker
{
    public function __construct(
        #[Inject(InjectCycleA::class)] public readonly InjectCycleMarker $a,
    ) {
    }
}

/**
 * PSR-3 logger that records every call instead of writing anywhere, so tests
 * can assert on levels, messages and context. AbstractLogger supplies the
 * level-named shortcuts, leaving only log() to implement.
 */
/**
 * Service-locator style consumer: exists to prove the container can still be
 * autowired as somebody else's dependency after gaining a constructor.
 */
final class NeedsContainer
{
    public function __construct(public Container $container)
    {
    }
}

final class SpyLogger extends AbstractLogger
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
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

/**
 * PSR-14 dispatcher that records every event instead of notifying listeners,
 * so tests can assert on the order, the payloads and the pairing of the
 * resolution lifecycle.
 */
final class SpyEventDispatcher implements EventDispatcherInterface
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

/**
 * Dispatcher whose "listener" calls back into the container on the first
 * successful top-level resolution. The guard flag keeps that from recursing
 * forever, since the re-entrant get() dispatches its own events.
 */
interface CycleThroughNullable
{
}

/**
 * Closes a cycle through its own interface on a nullable parameter, so the
 * cycle is reported from a frame whose parent is willing to fall back to null.
 */
final class NullableCycleImpl implements CycleThroughNullable
{
    public function __construct(public ?CycleThroughNullable $next = null)
    {
    }
}

/**
 * Dispatcher whose listener throws whatever the given factory returns, so a
 * test can pick exactly which event misbehaves.
 */
final class ThrowingDispatcher implements EventDispatcherInterface
{
    /**
     * Every event reaching the dispatcher, recorded before it may throw.
     *
     * @var list<object>
     */
    public array $events = [];

    /**
     * @param callable(object): ?Throwable $failure
     */
    public function __construct(private $failure)
    {
    }

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        $throwable = ($this->failure)($event);

        if ($throwable !== null) {
            throw $throwable;
        }

        return $event;
    }
}

// Fixtures for the tagged variadic collections: a handler hierarchy for the
// happy paths, a broken member, a member that closes a cycle on its consumer
// and a non-variadic parameter carrying a misplaced #[Tagged].

interface TagHandler
{
}

final class TagHandlerA implements TagHandler
{
}

final class TagHandlerB implements TagHandler
{
}

final class TagHandlerC implements TagHandler
{
}

#[Singleton]
final class TagSharedHandler implements TagHandler
{
}

final class TagBrokenHandler implements TagHandler
{
    public function __construct(int $count)
    {
    }
}

final class NeedsTaggedVariadic
{
    /**
     * @var list<TagHandler>
     */
    public array $handlers;

    public function __construct(#[Tagged('handlers')] TagHandler ...$handlers)
    {
        $this->handlers = $handlers;
    }
}

final class NeedsCycleTagged
{
    /**
     * @var list<object>
     */
    public array $members;

    public function __construct(#[Tagged('cycle.handlers')] object ...$members)
    {
        $this->members = $members;
    }
}

final class TagCycleMember
{
    public function __construct(public readonly NeedsCycleTagged $parent)
    {
    }
}

final class TaggedOnNonVariadic
{
    public function __construct(#[Tagged('handlers')] public readonly Foo $foo)
    {
    }
}

final class ReenteringDispatcher implements EventDispatcherInterface
{
    public ?Container $container = null;

    public bool $reentered = false;

    public ?object $reenteredInstance = null;

    public function dispatch(object $event): object
    {
        if (
            $event instanceof ServiceResolved
            && !$event->fromCache
            && !$this->reentered
            && $this->container !== null
        ) {
            $this->reentered = true;
            $this->reenteredInstance = $this->container->get($event->id);
        }

        return $event;
    }
}
