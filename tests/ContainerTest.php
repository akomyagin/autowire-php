<?php

declare(strict_types=1);

namespace AutowirePHP\Tests;

use AutowirePHP\Container;
use AutowirePHP\Exception\ContainerException;
use AutowirePHP\Exception\NotFoundException;
use AutowirePHP\Exception\NotInstantiableException;
use PHPUnit\Framework\TestCase;
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

    public function testThrowsNotInstantiableForBuiltinParameterWithoutDefault(): void
    {
        $container = new Container();

        try {
            $container->get(NeedsBuiltinNoDefault::class);
            self::fail('Expected NotInstantiableException was not thrown.');
        } catch (NotInstantiableException $exception) {
            self::assertSame(NeedsBuiltinNoDefault::class, $exception->getClassName());
            self::assertStringContainsString('count', $exception->getMessage());
            self::assertStringContainsString('built-in', $exception->getReason());
            self::assertStringNotContainsString('stage', $exception->getMessage());
        }
    }

    public function testThrowsNotInstantiableForUntypedParameter(): void
    {
        $container = new Container();

        try {
            $container->get(NeedsUntyped::class);
            self::fail('Expected NotInstantiableException was not thrown.');
        } catch (NotInstantiableException $exception) {
            self::assertSame(NeedsUntyped::class, $exception->getClassName());
            self::assertStringContainsString('whatever', $exception->getMessage());
        }
    }

    public function testResolvesMixedClassAndDefaultBuiltinParameters(): void
    {
        $container = new Container();

        $m = $container->get(MixedClassAndDefault::class);

        self::assertInstanceOf(GraphD::class, $m->d);
        self::assertSame(7, $m->x);
    }

    public function testThrowsNotInstantiableForUnionTypedParameterWithoutDefault(): void
    {
        $container = new Container();

        try {
            $container->get(NeedsUnion::class);
            self::fail('Expected NotInstantiableException was not thrown.');
        } catch (NotInstantiableException $exception) {
            self::assertSame(NeedsUnion::class, $exception->getClassName());
            self::assertStringContainsString('v', $exception->getMessage());
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
