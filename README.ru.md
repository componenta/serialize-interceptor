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

- PHP 8.4+
- `componenta/interceptor`
- `symfony/serializer`

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

## Динамический контекст

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

`ATTR_CONTEXT` объединяется с контекстом атрибута и имеет приоритет при совпадении ключей.

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
