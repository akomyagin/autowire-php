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

    public function testThrowsNotInstantiableForRequiredConstructorParameters(): void
    {
        $container = new Container();

        try {
            $container->get(RequiredParamConstructor::class);
            self::fail('Expected NotInstantiableException was not thrown.');
        } catch (NotInstantiableException $exception) {
            self::assertStringContainsString('autowiring', $exception->getMessage());
            self::assertStringContainsString('stage 2', $exception->getMessage());
            self::assertSame(RequiredParamConstructor::class, $exception->getClassName());
            self::assertStringContainsString('stage 2', $exception->getReason());
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

final class RequiredParamConstructor
{
    public function __construct(FooInterface $foo)
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
