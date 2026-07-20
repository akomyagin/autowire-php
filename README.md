# AutowirePHP

Framework-agnostic PHP DI-контейнер с автовайрингом на базе Reflection API.

Контейнер строит граф зависимостей сам: читает конструктор через Reflection,
разбирает type-hint'ы параметров и рекурсивно создаёт зависимости — без ручной
регистрации каждого класса. Никаких внешних сервисов, БД или Docker: чистая
composer-библиотека.

> Learning-проект (первый на чистом PHP в портфеле). Главная техническая задача —
> детект циклических зависимостей **через интерфейсы** при ленивом резолвинге.
> Не production-фреймворк; экономика не оценивается.

## Статус

В разработке. Скелет Этапа 0 на месте (`composer test` проходит). Разбивка по
пяти MVP-этапам — в [`docs/`](docs/).

## Требования

- PHP >= 8.3
- Composer 2

## Установка

```bash
composer require akomyagin/autowire-php
```

## Пример (целевой API)

```php
use AutowirePHP\Container;

interface LoggerInterface {}
final class FileLogger implements LoggerInterface {}

final class ReportService
{
    public function __construct(private LoggerInterface $logger) {}
}

$container = new Container();
$container->bind(LoggerInterface::class, FileLogger::class);

// Автовайринг: контейнер сам увидит, что ReportService просит LoggerInterface,
// развернёт биндинг в FileLogger и соберёт весь граф.
$service = $container->get(ReportService::class);
```

> Пример отражает целевой API MVP. По мере прохождения этапов (см. ниже)
> поведение `get()` наполняется; сейчас `get()` — заглушка.

## Документация

- [`docs/PLAN.md`](docs/PLAN.md) — видение, архитектура, этапы, «После MVP».
- [`docs/TECHNICAL_PLAN.md`](docs/TECHNICAL_PLAN.md) — стек и детальная разбивка по этапам.
- [`docs/POST_MVP_PLAN.md`](docs/POST_MVP_PLAN.md) — attributes, компиляция графа, PSR-11.

## Разработка

```bash
composer install
composer test          # или vendor/bin/phpunit
```

## Лицензия

MIT — см. [`LICENSE`](LICENSE).
