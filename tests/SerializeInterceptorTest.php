<?php

declare(strict_types=1);

use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\Serialization\Attribute\Serialize;
use Componenta\Interceptor\Serialization\SerializeInterceptor;
use Symfony\Component\Serializer\SerializerInterface;

final class RecordingSerializer implements SerializerInterface
{
    public mixed $data = null;
    public string $format = '';
    public array $context = [];

    public function serialize(mixed $data, string $format, array $context = []): string
    {
        $this->data = $data;
        $this->format = $format;
        $this->context = $context;

        return json_encode(['data' => $data, 'context' => $context], JSON_THROW_ON_ERROR);
    }

    public function deserialize(mixed $data, string $type, string $format, array $context = []): mixed
    {
        return null;
    }
}

final readonly class FixedResultHandler implements ContextHandlerInterface
{
    public function __construct(private mixed $result) {}

    public function handle(CallableContextInterface $context): mixed
    {
        return $this->result;
    }
}

it('serializes handler result with static context', function () {
    $serializer = new RecordingSerializer();
    $interceptor = new SerializeInterceptor($serializer, context: ['groups' => ['public']]);
    $context = new CallableContext(static fn() => null);

    $result = $interceptor->intercept($context, new FixedResultHandler(['id' => 1]));

    expect($result)->toBe('{"data":{"id":1},"context":{"groups":["public"]}}')
        ->and($serializer->format)->toBe('json')
        ->and($serializer->context)->toBe(['groups' => ['public']]);
});

it('merges return-level serializer context', function () {
    $serializer = new RecordingSerializer();
    $interceptor = new SerializeInterceptor($serializer, context: ['groups' => ['base']]);
    $context = new CallableContext(static fn() => null);

    $interceptor->intercept($context, new FixedResultHandler([
        Serialize::ATTR_DATA => ['id' => 1],
        Serialize::ATTR_CONTEXT => ['groups' => ['detail']],
    ]));

    expect($serializer->data)->toBe(['id' => 1])
        ->and($serializer->context)->toBe(['groups' => ['detail']]);
});

it('resolves dynamic context from callable parameters', function () {
    $serializer = new RecordingSerializer();
    $query = new class {
        public bool $includeDrafts = true;
    };
    $callable = static fn(object $query) => null;
    $context = new CallableContext($callable, [$query]);
    $interceptor = new SerializeInterceptor($serializer, context: [
        'query' => 'includeDrafts',
        Serialize::ATTR_MAP => ['includeDrafts' => 'include_drafts'],
    ]);

    $interceptor->intercept($context, new FixedResultHandler(['id' => 1]));

    expect($serializer->context)->toBe(['include_drafts' => true]);
});
