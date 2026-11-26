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

- PHP 8.5.13+
- `componenta/interceptor` 4 with DI 6 and Config 4
- `symfony/serializer` 8.1+

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

## Callback Context

Append `contextFactory` to compute options after a successful handler call:

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

The callback receives the selected serialization data and the exact context passed into `SerializeInterceptor`. If a return wrapper is used, the data is its `ATTR_DATA` value. Context replacements made inside the continuation are not propagated back to this callback. Named and positional arguments are available through the public context's `getParameter()` contract.

Context is combined in this order: static options and parameter mappings, return-level `ATTR_CONTEXT`, then `contextFactory` overrides. Merging is shallow: a dynamic key replaces the whole previous value, an explicit null remains null, and an empty array adds no overrides. Existing context maps and return wrappers remain supported.

The factory must return an array with string keys. Invalid results raise `InvalidArgumentException` before serialization. Handler, callback, and serializer exceptions propagate unchanged. A handler failure skips the callback and serializer; a callback failure skips the serializer. Each invocation computes its own context.

Invocation is native PHP, with no service injection into additional callback parameters. Reassigning callback parameters, including parameters passed by reference, does not replace the selected data or entry context. Shared nested objects retain normal PHP mutation semantics; no deep copy is made.

Use a capture-free `static function` or a first-class function/static-method reference such as `contextFactory: self::serializationContext(...)`. Private static method scope is retained. Arrow functions and `use` captures are not supported inside PHP 8.5 attribute arguments. Direct construction of `SerializeInterceptor` also accepts `contextFactory` as its last optional argument.

## Parameter-Mapped Context

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

`ATTR_CONTEXT` is merged with attribute context and wins on duplicate keys. An optional `contextFactory` runs afterward and has the final override priority.

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
