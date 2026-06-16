# Componenta Serialize Interceptor

Result interceptor for `componenta/interceptor` that serializes callable return values through `Symfony\Component\Serializer\SerializerInterface`.

**[Русская документация](README.ru.md)**

## Boundary

This package contains only the `#[Serialize]` attribute and `SerializeInterceptor`. It does not configure Symfony Serializer for the application: the container must provide `SerializerInterface`.

Use `componenta/interceptor` directly when you only need the callable interceptor pipeline.

## Installation

```bash
composer require componenta/serialize-interceptor
```

## Requirements

- PHP 8.4+
- `componenta/interceptor`
- `symfony/serializer`

## Quick Start

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

`#[Serialize]` extends `Componenta\Interceptor\Attribute\Intercept`, so it is read by the standard `AttributeInterceptor`. `SerializeInterceptor` is created through the DI factory, and `SerializerInterface` is resolved from the container.

## Dynamic Context

Context can be built from already resolved method parameters. The array key is the parameter name, and the value is the public property name:

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

If `$query->includeAuthor` is `true`, serializer context receives `['with_author' => true]`.

## Return-Level Context

A method may return special keys when context depends on the result:

```php
use Componenta\Interceptor\Serialization\Attribute\Serialize;

return [
    Serialize::ATTR_DATA => $post,
    Serialize::ATTR_CONTEXT => ['groups' => ['post:detail']],
];
```

`ATTR_CONTEXT` is merged with attribute context and wins on duplicate keys.

## Ordering With Other Interceptors

If the result must become an HTTP response, place the response interceptor above `#[Serialize]`:

```php
#[Respond(200, 'application/json')] // outer layer
#[Serialize]                        // inner layer, receives the raw result first
public function show(): User {}
```

Details: [`componenta/interceptor`](https://github.com/componenta/interceptor/blob/main/README.md) describes layer ordering and `AttributeInterceptor`.

## License

MIT
