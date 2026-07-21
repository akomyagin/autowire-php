<?php

declare(strict_types=1);

namespace AutowirePHP\Tests;

use AutowirePHP\Container;
use AutowirePHP\Exception\CircularDependencyException;
use AutowirePHP\Exception\ContainerException;
use AutowirePHP\Exception\NotFoundException;
use AutowirePHP\Exception\NotInstantiableException;
use AutowirePHP\Exception\UnresolvableParameterException;
use PHPUnit\Framework\TestCase;
use ReflectionObject;
use stdClass;

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
