<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Serialization;

use Closure;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptorInterface;
use Componenta\Interceptor\Serialization\Attribute\Serialize;
use InvalidArgumentException;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class SerializeInterceptor implements InterceptorInterface
{
    /**
     * Calls $contextFactory($selectedData, $entryContext), when provided, after
     * the continuation and return-wrapper processing. It must return an array
     * with string keys. The data parameter may declare the selected data type;
     * PHP validates that type at invocation.
     *
     * @param array<string, mixed> $context Static serializer context or dynamic parameter-to-property map.
     * @param Closure|null $contextFactory
     */
    public function __construct(
        private SerializerInterface $serializer,
        private string $format = 'json',
        private array $context = [],
        private ?Closure $contextFactory = null,
    ) {
    }

    public function intercept(
        CallableContextInterface $context,
        ContextHandlerInterface $handler,
    ): string {
        $result = $handler->handle($context);
        $serializerContext = $this->resolveContext($context);

        if (is_array($result) && array_key_exists(Serialize::ATTR_DATA, $result)) {
            $serializerContext = [
                ...$serializerContext,
                ...$this->validateContext($result[Serialize::ATTR_CONTEXT] ?? [], 'Returned'),
            ];

            $result = $result[Serialize::ATTR_DATA];
        }

        if ($this->contextFactory !== null) {
            // Reassigning callback input slots must not replace the selected data.
            // Nested objects and reference-bearing values retain native sharing.
            $callbackData = $result;
            $callbackContext = $context;
            $serializerContext = [
                ...$serializerContext,
                ...$this->validateContext(($this->contextFactory)($callbackData, $callbackContext), 'Computed'),
            ];
        }

        return $this->serializer->serialize($result, $this->format, $serializerContext);
    }

    /** @return array<string, mixed> */
    private function validateContext(mixed $value, string $origin): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException($origin . ' serializer context must be an array with string keys.');
        }
        $context = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new InvalidArgumentException($origin . ' serializer context must have string keys.');
            }
            $context[$key] = $item;
        }
        return $context;
    }

    /** @return array<string, mixed> */
    private function resolveContext(CallableContextInterface $context): array
    {
        if ($this->context === []) {
            return [];
        }

        $paramPositions = $this->getParameterPositions($context);
        /** @var array<string, string> $map */
        $map = $this->context[Serialize::ATTR_MAP] ?? [];
        $resolved = [];

        foreach ($this->context as $key => $value) {
            if ($key === Serialize::ATTR_MAP) {
                continue;
            }

            if (is_string($value) && isset($paramPositions[$key])) {
                $position = $paramPositions[$key];
                $parameterKey = array_key_exists($key, $context->parameters) ? $key : $position;

                if (!array_key_exists($parameterKey, $context->parameters)) {
                    throw new InvalidArgumentException(sprintf(
                        '#[Serialize] context references parameter "%s" (position %d) which was not resolved.',
                        $key,
                        $position,
                    ));
                }

                $param = $context->parameters[$parameterKey];

                if (!is_object($param) || !property_exists($param, $value)) {
                    throw new InvalidArgumentException(sprintf(
                        '#[Serialize] context references property "%s" on parameter "%s", but %s.',
                        $value,
                        $key,
                        is_object($param)
                            ? 'the property does not exist on ' . $param::class
                            : 'the parameter is not an object (got ' . get_debug_type($param) . ')',
                    ));
                }

                $contextKey = $map[$value] ?? $value;
                $resolved[$contextKey] = $param->$value;
                continue;
            }

            $resolved[$key] = $value;
        }

        return $resolved;
    }

    /**
     * @return array<string, int>
     */
    private function getParameterPositions(CallableContextInterface $context): array
    {
        $map = [];

        foreach ($context->reflector?->getParameters() ?? [] as $param) {
            $map[$param->getName()] = $param->getPosition();
        }

        return $map;
    }
}
