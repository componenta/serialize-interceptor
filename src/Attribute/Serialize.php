<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Serialization\Attribute;

use Attribute;
use Componenta\Interceptor\Attribute\Intercept;
use Componenta\Interceptor\Serialization\SerializeInterceptor;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final class Serialize extends Intercept
{
    public const string ATTR_DATA = '__serialize_data';
    public const string ATTR_CONTEXT = '__serialize_context';
    public const string ATTR_MAP = '__serialize_map';

    /**
     * @param array<string, mixed> $context Static serializer context or dynamic parameter-to-property map.
     */
    public function __construct(
        string $format = 'json',
        array $context = [],
    ) {
        parent::__construct(SerializeInterceptor::class, [
            'format' => $format,
            'context' => $context,
        ]);
    }
}
