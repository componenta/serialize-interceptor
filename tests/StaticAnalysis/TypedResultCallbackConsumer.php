<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Serialization\Tests\StaticAnalysis;

use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\Serialization\Attribute\Serialize;
use Componenta\Interceptor\Serialization\SerializeInterceptor;
use Symfony\Component\Serializer\SerializerInterface;

/** Public consumer: the context factory may declare the selected data type. */
final class TypedResultCallbackConsumer
{
    #[Serialize(contextFactory: self::context(...))]
    public function value(): int
    {
        return 7;
    }

    /** @return array<string, mixed> */
    public static function context(int $result, CallableContextInterface $context): array
    {
        return ['json_encode_options' => $result];
    }

    /** @return list<Serialize|SerializeInterceptor> */
    public function consumers(SerializerInterface $serializer): array
    {
        return [
            new Serialize(contextFactory: self::context(...)),
            new SerializeInterceptor($serializer, contextFactory: self::context(...)),
        ];
    }
}
