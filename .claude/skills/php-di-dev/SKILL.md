---
name: php-di-dev
description: Конвенции проекта AutowirePHP — паттерны работы с ReflectionClass/ReflectionParameter, алгоритм детекта циклических зависимостей (resolution-stack по пути резолвинга, а не глобальный visited-set), стиль тестирования на PHPUnit. Использовать при реализации любого этапа кодирования AutowirePHP.
---

# SKILL: php-di-dev — конвенции проекта AutowirePHP

Специфика разработки DI-контейнера AutowirePHP. Это **первый чистый-PHP проект**
портфеля, поэтому Reflection-паттерны и алгоритм детекта циклов задокументированы
явно, а не «по памяти».

## Общее

- PHP 8.3, `declare(strict_types=1)` в каждом файле, PSR-4/PSR-12,
  `final class` по умолчанию.
- Namespace: `Akomyagin\AutowirePHP\` (`src/`),
  `Akomyagin\AutowirePHP\Tests\` (`tests/`).
- Внешних зависимостей у рантайма нет — только Reflection API.

## Работа с Reflection API

Базовый паттерн резолвинга конструктора:

```php
$reflection = new \ReflectionClass($concrete);

if (!$reflection->isInstantiable()) {
    // interface/abstract без биндинга, приватный конструктор и т.п.
    throw new NotInstantiableException($concrete);
}

$constructor = $reflection->getConstructor();
if ($constructor === null) {
    // нет конструктора — просто создаём
    return $reflection->newInstance();
}

$args = [];
foreach ($constructor->getParameters() as $param) {
    $args[] = $this->resolveParameter($param, $concrete);
}

return $reflection->newInstanceArgs($args);
```

Разбор `ReflectionParameter` (Этапы 2 и 5):

- `$param->getType()` → `ReflectionType|null`.
- `ReflectionNamedType`: `->isBuiltin()` отличает `int/string/...` от классов;
  `->getName()` даёт class-string для рекурсивного `get()`.
- `ReflectionUnionType`: `->getTypes()` возвращает список `ReflectionNamedType` —
  перебирать по порядку до первого разрешимого (Этап 5).
- `$param->allowsNull()` — nullable-стратегия (Этап 5).
- `$param->isDefaultValueAvailable()` → `$param->getDefaultValue()` — дефолт для
  builtin/неразрешимого (Этап 5).
- `$param->isVariadic()` — variadic-стратегия (Этап 5): по умолчанию пустой набор.

Приоритет разрешения параметра (документируем и соблюдаем): 
**резолвим class-тип → union по порядку → default value → null (если nullable) →
`UnresolvableParameterException`.**

## Детект циклов — ГЛАВНОЕ (Этап 3)

**Правило:** трекаем **текущий путь резолвинга** (resolution-stack), а НЕ
глобальный visited-set. В стек кладём **и абстрактные id (интерфейсы), и
конкретные классы**.

Почему не глобальный visited-set: он помечает класс «уже видели» навсегда и не
различает «зависимость встречается дважды в разных ветках графа» (это НЕ цикл) от
«зависимость встречается на текущем пути» (это цикл). Для DI нужен именно
path-scoped стек.

Почему в стек кладём абстрактные id: цикл `A -> IB -> B -> IA -> A` при ленивом
резолвинге разворачивает `IB` в `B` и `IA` в `A`. Если трекать только конкретные
классы и «прыгать» сразу на реализацию, цикл через интерфейсную косвенность можно
пропустить. Пуш и абстрактного, и конкретного id гарантирует, что повтор
поймается на любом звене.

Скелет алгоритма:

```php
/** @var array<string,true> $stack — множество id на текущем пути */
/** @var list<string> $chain — упорядоченный путь для сообщения */

private function enter(string $id): void
{
    if (isset($this->stack[$id])) {
        $cycle = [...$this->chain, $id];
        throw new CircularDependencyException($cycle); // "IA -> A -> IB -> B -> IA"
    }
    $this->stack[$id] = true;
    $this->chain[] = $id;
}

private function leave(string $id): void
{
    unset($this->stack[$id]);
    array_pop($this->chain);
}
```

Использование с гарантией очистки (критично: без `finally` контейнер «залипнет»
после первого же цикла):

```php
$this->enter($abstractId);
try {
    $concrete = $this->bindings[$abstractId] ?? $abstractId;
    $this->enter($concrete);
    try {
        return $this->build($concrete);
    } finally {
        $this->leave($concrete);
    }
} finally {
    $this->leave($abstractId);
}
```

`CircularDependencyException` хранит цепочку и отдаёт человекочитаемое сообщение
со стрелками. Обязательно тест: **после пойманного цикла повторный `get()`
валидного графа проходит** (стек очищен).

## Стиль тестирования (PHPUnit)

- Файлы `*Test.php` в `tests/`, зеркалят `src/`, namespace
  `Akomyagin\AutowirePHP\Tests\`.
- Фикстурные классы графа (`A`, `B`, `IFoo`, `Foo`, ...) держим **рядом с
  тестом**, который их использует — граф зависимостей виден в одном файле. Мелкие
  фикстуры можно объявлять в том же файле после тест-класса.
- Один тест — одно поведение; имя метода описывает сценарий
  (`testResolvesInterfaceThroughBinding`,
  `testDetectsCycleThroughInterfaces`).
- Lifecycle-тесты: `assertSame` для singleton, `assertNotSame` для transient.
- Цикл-тесты: `expectException(CircularDependencyException::class)` +
  проверка, что сообщение содержит полную цепочку.
- Прогон: `composer test` или `vendor/bin/phpunit`. Перед коммитом — зелёный.

## Чего НЕ делать в MVP

- Не вводить PHP 8 attributes-конфигурацию — строго post-MVP.
- Не компилировать/не кешировать граф — строго post-MVP, только
  runtime-reflection.
- Не тащить внешние зависимости в `require` (dev — только PHPUnit).
