<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Serialization;

use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptorInterface;
use Componenta\Interceptor\Serialization\Attribute\Serialize;
use InvalidArgumentException;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class SerializeInterceptor implements InterceptorInterface
{
    /**
     * @param array<string, mixed> $context Static serializer context or dynamic parameter-to-property map.
     */
    public function __construct(
        private SerializerInterface $serializer,
        private string $format = 'json',
        private array $context = [],
    ) {}

    public function intercept(
        CallableContextInterface $context,
        ContextHandlerInterface $handler,
    ): string {
        $result = $handler->handle($context);
        $serializerContext = $this->resolveContext($context);

        if (is_array($result) && array_key_exists(Serialize::ATTR_DATA, $result)) {
            $serializerContext = [
                ...$serializerContext,
                ...($result[Serialize::ATTR_CONTEXT] ?? []),
            ];

            return $this->serializer->serialize($result[Serialize::ATTR_DATA], $this->format, $serializerContext);
        }

        return $this->serializer->serialize($result, $this->format, $serializerContext);
    }

    /**
     * @return array<string, mixed>
     */
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

            if (is_string($key) && is_string($value) && isset($paramPositions[$key])) {
                $position = $paramPositions[$key];

                if (!array_key_exists($position, $context->parameters)) {
                    throw new InvalidArgumentException(sprintf(
                        '#[Serialize] context references parameter "%s" (position %d) which was not resolved.',
                        $key,
                        $position,
                    ));
                }

                $param = $context->parameters[$position];

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

        foreach ($context->reflector->getParameters() as $param) {
            $map[$param->getName()] = $param->getPosition();
        }

        return $map;
    }
}
