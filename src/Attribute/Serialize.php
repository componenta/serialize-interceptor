<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Serialization\Attribute;

use Attribute;
use Closure;
use Componenta\Interceptor\Attribute\Intercept;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\Serialization\SerializeInterceptor;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final class Serialize extends Intercept
{
    public const string ATTR_DATA = '__serialize_data';
    public const string ATTR_CONTEXT = '__serialize_context';
    public const string ATTR_MAP = '__serialize_map';

    /**
     * Calls $contextFactory($selectedData, $entryContext), when provided, after
     * the continuation and return-wrapper processing. It must return an array
     * with string keys. The data parameter may declare the selected data type;
     * PHP validates that type at invocation. The entry context implements
     * CallableContextInterface.
     *
     * @param array<string, mixed> $context Static serializer context or dynamic parameter-to-property map.
     * @param Closure|null $contextFactory
     */
    public function __construct(
        string $format = 'json',
        array $context = [],
        ?Closure $contextFactory = null,
    ) {
        parent::__construct(SerializeInterceptor::class, [
            'format' => $format,
            'context' => $context,
            'contextFactory' => $contextFactory,
        ]);
    }
}
