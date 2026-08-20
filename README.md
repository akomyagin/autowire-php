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

MVP реализован: ручные биндинги, reflection-based автовайринг конструктора,
детект циклических зависимостей через интерфейсы, lifecycle-контроль
(singleton/transient), edge cases конструктора (nullable, union, variadic).
После MVP добавлена конфигурация резолвинга атрибутами `#[Inject]` и
`#[Singleton]`.

Поддерживаемые PSR:

| PSR | Как поддержан |
|---|---|
| **PSR-4** | Автозагрузка `AutowirePHP\` из `src/` |
| **PSR-11** | `Container implements Psr\Container\ContainerInterface`; исключения совместимы с `ContainerExceptionInterface` |
| **PSR-12** | Стиль кода, проверяется `composer lint` |
| **PSR-3** | Опциональный логгер резолвинга (второй аргумент — см. ниже), уровень `debug` |
| **PSR-14** | Опциональный диспетчер событий резолвинга |

Логгер и диспетчер необязательны: `new Container()` работает как прежде.

```php
$container = new Container($logger, $dispatcher);
```

События (`AutowirePHP\Event\`) предназначены **только для наблюдения** —
слушатель не может подменить создаваемый объект:

| Событие | Когда |
|---|---|
| `ResolutionRequested` | На каждый `get()`, включая попадание в кеш |
| `ServiceResolved` | Успех; поле `fromCache` различает сборку и кеш |
| `ResolutionFailed` | Провал кадра, перед пробросом исключения |

Каждому `ResolutionRequested` соответствует ровно одно терминальное событие с
теми же `id` и `depth`. Слушатель не должен бросать исключения: если он всё же
бросит, контейнер не даст ему переписать исход резолвинга — на успешном пути
провал слушателя приходит как `ListenerException`, на пути провала побеждает
исходное исключение резолвинга.

## Требования

- PHP >= 8.3
- Composer 2

## Установка

```bash
composer require akomyagin/autowire-php
```

## Пример

```php
use AutowirePHP\Container;

interface ReportStorage {}
final class FileStorage implements ReportStorage {}

final class ReportService
{
    public function __construct(private ReportStorage $storage) {}
}

$container = new Container();
$container->bind(ReportStorage::class, FileStorage::class);

// Автовайринг: контейнер сам увидит, что ReportService просит ReportStorage,
// развернёт биндинг в FileStorage и соберёт весь граф.
$service = $container->get(ReportService::class);
```

## Документация

Планы разработки (`docs/PLAN.md`, `docs/TECHNICAL_PLAN.md`,
`docs/POST_MVP_PLAN.md`) ведутся только в локальной рабочей копии и в git не
попадают — в свежем клоне их нет.

## Разработка

```bash
composer install
composer test          # или vendor/bin/phpunit
composer lint          # проверка PSR-12, файлы не меняются
composer lint:fix      # автоисправление стиля
```

## Лицензия

MIT — см. [`LICENSE`](LICENSE).
