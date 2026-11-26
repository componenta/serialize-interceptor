# Componenta Serialize Interceptor

Перехватчик результата для `componenta/interceptor`, который сериализует возвращаемое значение через `Symfony\Component\Serializer\SerializerInterface`.

**[English documentation](README.md)**

## Граница пакета

Пакет содержит только атрибут `#[Serialize]` и `SerializeInterceptor`. Он не настраивает Symfony Serializer за приложение: контейнер должен возвращать сервис `SerializerInterface`.

Если нужна только цепочка перехватчиков без сериализации, используйте `componenta/interceptor`.

## Установка

```bash
composer require componenta/serialize-interceptor
```

## Требования

- PHP 8.5.13+
- `componenta/interceptor` 4 с DI 6 и Config 4
- `symfony/serializer` 8.1+

## Быстрый старт

```php
use Componenta\Interceptor\Http\Attribute\Respond;
use Componenta\Interceptor\Serialization\Attribute\Serialize;

final class UserController
{
    #[Respond(200, 'application/json')]
    #[Serialize(context: ['groups' => ['user:read']])]
    public function show(): User
    {
        return $this->user;
    }
}
```

`#[Serialize]` наследует `Componenta\Interceptor\Attribute\Intercept`, поэтому его читает обычный `AttributeInterceptor`. Инстанс `SerializeInterceptor` создается через DI-фабрику, а `SerializerInterface` берется из контейнера.

## Контекст через функцию обратного вызова

Аргумент `contextFactory` вычисляет параметры сериализации после успешного вызова обработчика:

```php
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\Serialization\Attribute\Serialize;

#[Serialize(
    context: ['groups' => ['public']],
    contextFactory: static function (
        mixed $data,
        CallableContextInterface $invocation,
    ): array {
        $query = $invocation->getParameter('query');

        return ['include_author' => $query->includeAuthor];
    },
)]
function listPosts(PostListQuery $query): array { return []; }
```

Функция получает выбранные данные для сериализации и тот же контекст, который был передан в `SerializeInterceptor`. При использовании обёртки результата данными служит значение `ATTR_DATA`. Замена контекста внутри продолжения не передаётся обратно этой функции. Именованные и позиционные аргументы доступны через публичный метод `getParameter()`.

Порядок объединения: статические параметры и сопоставления свойств, затем `ATTR_CONTEXT` из результата, затем переопределения от `contextFactory`. Объединение поверхностное: ключ заменяет предыдущее значение целиком, явный null сохраняется, пустой массив ничего не переопределяет. Существующие сопоставления и обёртки результата сохраняются.

Функция должна вернуть массив со строковыми ключами. Иной результат вызывает `InvalidArgumentException` до сериализации. Исключения обработчика, функции и сериализатора передаются без изменения. Ошибка обработчика пропускает вычисление контекста и сериализацию; ошибка функции пропускает сериализацию. Контекст вычисляется заново для каждого вызова.

Вызов выполняется средствами PHP без внедрения сервисов в дополнительные параметры функции. Переприсваивание её параметров, включая параметры по ссылке, не заменяет выбранные данные или входной контекст. Вложенные объекты сохраняют обычное поведение PHP при совместном использовании и изменении; глубокого копирования нет.

В атрибуте используйте `static function` без захвата переменных либо ссылку на функцию или статический метод вида `contextFactory: self::serializationContext(...)`. Область видимости закрытого метода сохраняется. Стрелочные функции и захват через `use` в аргументах атрибутов PHP 8.5 не поддерживаются. При прямом создании `SerializeInterceptor` аргумент `contextFactory` также доступен последним необязательным параметром.

## Контекст из параметров обработчика

Контекст можно собрать из уже разрешенных параметров метода. Ключ массива — имя параметра, значение — имя публичного свойства:

```php
use Componenta\Interceptor\Serialization\Attribute\Serialize;

final class PostController
{
    #[Serialize(context: [
        'query' => 'includeAuthor',
        Serialize::ATTR_MAP => ['includeAuthor' => 'with_author'],
    ])]
    public function index(PostListQuery $query): array
    {
        return $this->posts->list($query);
    }
}
```

Если `$query->includeAuthor` равен `true`, в serializer context попадет `['with_author' => true]`.

## Контекст из результата

Метод может вернуть специальные ключи, если контекст зависит от результата:

```php
use Componenta\Interceptor\Serialization\Attribute\Serialize;

return [
    Serialize::ATTR_DATA => $post,
    Serialize::ATTR_CONTEXT => ['groups' => ['post:detail']],
];
```

`ATTR_CONTEXT` объединяется с контекстом атрибута и имеет приоритет при совпадении ключей. Необязательная функция `contextFactory` выполняется после этого и имеет последний приоритет переопределения.

## Порядок с другими перехватчиками

Если результат должен стать HTTP-ответом, ставьте response-перехватчик выше `#[Serialize]`:

```php
#[Respond(200, 'application/json')] // внешний слой
#[Serialize]                        // внутренний слой, первым получает raw result
public function show(): User {}
```

Подробнее: [`componenta/interceptor`](https://github.com/componenta/interceptor/blob/main/README.ru.md) описывает порядок слоев и работу `AttributeInterceptor`.

## Лицензия

MIT
