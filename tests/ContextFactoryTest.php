<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Serialization\Tests;

use Componenta\Config\Config;
use Componenta\Config\ConfigKey;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\ContainerFactory;
use Componenta\DI\FactoryInterface;
use Componenta\Interceptor\AttributeInterceptor;
use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptingExecutor;
use Componenta\Interceptor\Serialization\Attribute\Serialize;
use Componenta\Interceptor\Serialization\SerializeInterceptor;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

final class ContextFactorySerializer implements SerializerInterface
{
    public array $calls = [];

    public function serialize(mixed $data, string $format, array $context = []): string
    {
        $this->calls[] = [$data, $format, $context];
        return 'encoded';
    }

    public function deserialize(mixed $data, string $type, string $format, array $context = []): never
    {
        throw new \LogicException('Deserialization is outside this interceptor.');
    }
}

function contextFactoryResult(mixed $value): ContextHandlerInterface
{
    return new class($value) implements ContextHandlerInterface {
        public function __construct(private mixed $value) {}
        public function handle(CallableContextInterface $context): mixed { return $this->value; }
    };
}

it('builds context after successful continuation and serializes its exact selected data', function (): void {
    $serializer = new ContextFactorySerializer();
    $result = (object) ['id' => 7];
    $invocation = new CallableContext(static fn (): mixed => null);
    $observed = [];
    $handler = new class($result, $observed) implements ContextHandlerInterface {
        public function __construct(private object $result, private array &$observed) {}
        public function handle(CallableContextInterface $context): mixed
        {
            $this->observed[] = 'handler';
            return $this->result;
        }
    };
    $interceptor = new SerializeInterceptor($serializer, format: 'custom', context: ['groups' => ['base']], contextFactory:
        static function (mixed $data, CallableContextInterface $context) use (&$observed, $serializer): array {
            $observed[] = [$data, $context, $serializer->calls];
            return ['groups' => ['detail']];
        },
    );

    expect($interceptor->intercept($invocation, $handler))->toBe('encoded')
        ->and($observed)->toBe(['handler', [$result, $invocation, []]])
        ->and($serializer->calls)->toBe([[$result, 'custom', ['groups' => ['detail']]]]);
});

it('merges computed overrides after attribute and returned context without recursive merging', function (): void {
    $serializer = new ContextFactorySerializer();
    $data = (object) ['id' => 3];
    $seen = null;
    $interceptor = new SerializeInterceptor($serializer, context: [
        'base' => true, 'groups' => ['base'], 'nested' => ['base' => true], 'nullable' => 'old',
    ], contextFactory: static function (mixed $result) use (&$seen): array {
        $seen = $result;
        return ['groups' => ['callback'], 'nested' => ['callback' => true], 'nullable' => null];
    });

    $interceptor->intercept(new CallableContext(static fn (): mixed => null), contextFactoryResult([
        Serialize::ATTR_DATA => $data,
        Serialize::ATTR_CONTEXT => ['groups' => ['returned'], 'returned' => true],
    ]));

    expect($seen)->toBe($data)
        ->and($serializer->calls)->toBe([[$data, 'json', [
            'base' => true, 'groups' => ['callback'], 'nested' => ['callback' => true], 'nullable' => null, 'returned' => true,
        ]]]);
});

it('preserves parameter mapping as a base for explicit callback overrides', function (): void {
    $serializer = new ContextFactorySerializer();
    $context = new CallableContext(static fn (object $query): mixed => null, ['query' => (object) ['includeDrafts' => true]]);
    $interceptor = new SerializeInterceptor($serializer, context: [
        'query' => 'includeDrafts', Serialize::ATTR_MAP => ['includeDrafts' => 'include_drafts'],
    ], contextFactory: static fn (): array => ['include_author' => true]);

    $interceptor->intercept($context, contextFactoryResult('data'));

    expect($serializer->calls)->toBe([['data', 'json', ['include_drafts' => true, 'include_author' => true]]]);
});

it('does not compute serializer context or serialize when the continuation throws', function (): void {
    $serializer = new ContextFactorySerializer();
    $failure = new RuntimeException('handler');
    $called = false;
    $interceptor = new SerializeInterceptor($serializer, contextFactory: static function () use (&$called): array {
        $called = true;
        return [];
    });
    $handler = new class($failure) implements ContextHandlerInterface {
        public function __construct(private RuntimeException $failure) {}
        public function handle(CallableContextInterface $context): never { throw $this->failure; }
    };

    try {
        $interceptor->intercept(new CallableContext(static fn (): mixed => null), $handler);
        test()->fail('The handler failure must propagate.');
    } catch (RuntimeException $actual) {
        expect($actual)->toBe($failure)->and($called)->toBeFalse()->and($serializer->calls)->toBe([]);
    }
});

it('preserves callback failure identity and does not call the serializer', function (): void {
    $serializer = new ContextFactorySerializer();
    $failure = new RuntimeException('context');
    $interceptor = new SerializeInterceptor($serializer, contextFactory: static function () use ($failure): never { throw $failure; });

    try {
        $interceptor->intercept(new CallableContext(static fn (): mixed => null), contextFactoryResult('value'));
        test()->fail('The callback failure must propagate.');
    } catch (RuntimeException $actual) {
        expect($actual)->toBe($failure)->and($serializer->calls)->toBe([]);
    }
});

it('rejects invalid computed context before serialization', function (mixed $invalid): void {
    $serializer = new ContextFactorySerializer();
    $interceptor = new SerializeInterceptor($serializer, contextFactory: static fn (): mixed => $invalid);

    expect(fn () => $interceptor->intercept(new CallableContext(static fn (): mixed => null), contextFactoryResult('value')))
        ->toThrow(InvalidArgumentException::class, 'serializer context')
        ->and($serializer->calls)->toBe([]);
})->with(['null' => [null], 'string' => ['invalid'], 'integer keys' => [['invalid']], 'mixed keys' => [['valid' => 1, 2 => 3]]]);

it('isolates callback input reassignment while preserving nested object identity', function (): void {
    $serializer = new ContextFactorySerializer();
    $shared = (object) ['count' => 0];
    $original = ['shared' => $shared];
    $context = new CallableContext(static fn (): mixed => null);
    $interceptor = new SerializeInterceptor($serializer, contextFactory: static function (mixed &$data, CallableContextInterface &$invocation): array {
        ++$data['shared']->count;
        $data = 'replacement';
        $invocation = $invocation->withAttribute('replacement', true);
        return [];
    });

    $interceptor->intercept($context, contextFactoryResult($original));

    expect($serializer->calls)->toBe([[$original, 'json', []]])
        ->and($shared->count)->toBe(1)
        ->and($context->getAttribute('replacement'))->toBeNull();
});

it('exposes the entry context rather than replacements used inside the continuation', function (): void {
    $serializer = new ContextFactorySerializer();
    $context = new CallableContext(static fn (string $name): string => $name, ['name' => 'entry']);
    $seen = null;
    $interceptor = new SerializeInterceptor($serializer, contextFactory: static function (mixed $data, CallableContextInterface $invocation) use (&$seen): array {
        $seen = $invocation;
        return ['name' => $invocation->getParameter('name')];
    });
    $handler = new class implements ContextHandlerInterface {
        public function handle(CallableContextInterface $context): mixed
        {
            $replacement = $context->withParameters(['name' => 'inner']);
            return ($replacement->getCallable())(...$replacement->invocationArguments());
        }
    };

    $interceptor->intercept($context, $handler);

    expect($seen)->toBe($context)->and($serializer->calls)->toBe([['inner', 'json', ['name' => 'entry']]]);
});

it('recomputes callback context on every call without retaining previous overrides', function (): void {
    $serializer = new ContextFactorySerializer();
    $base = ['groups' => ['base']];
    $interceptor = new SerializeInterceptor($serializer, context: $base, contextFactory:
        static fn (mixed $data, CallableContextInterface $context): array => $context->getParameter('context', []),
    );
    $context = new CallableContext(static fn (array $context): mixed => null);

    $interceptor->intercept($context->withParameters(['context' => ['groups' => ['first']]]), contextFactoryResult(1));
    $interceptor->intercept($context->withParameters(['context' => []]), contextFactoryResult(2));

    expect($base)->toBe(['groups' => ['base']])
        ->and($serializer->calls)->toBe([[1, 'json', ['groups' => ['first']]], [2, 'json', ['groups' => ['base']]]]);
});

it('accepts a public context without reflection for static and callback serializer options', function (): void {
    $target = new class {
        public function __call(string $name, array $arguments): mixed { return null; }
    };
    $context = new CallableContext([$target, 'dynamic']);
    $serializer = new ContextFactorySerializer();
    $interceptor = new SerializeInterceptor($serializer, context: ['groups' => ['base']], contextFactory: static fn (): array => ['extra' => true]);

    $interceptor->intercept($context, contextFactoryResult('value'));

    expect($context->reflector)->toBeNull()
        ->and($serializer->calls)->toBe([['value', 'json', ['groups' => ['base'], 'extra' => true]]]);
});

it('uses native argument errors instead of injecting callback suffix dependencies', function (): void {
    $serializer = new ContextFactorySerializer();
    $interceptor = new SerializeInterceptor($serializer, contextFactory: static function (mixed $data, CallableContextInterface $context, \stdClass $service): array { return []; });

    expect(fn () => $interceptor->intercept(new CallableContext(static fn (): mixed => null), contextFactoryResult('value')))
        ->toThrow(\ArgumentCountError::class)
        ->and($serializer->calls)->toBe([]);
});

final class NativeContextFactoryTarget
{
    #[Serialize(contextFactory: self::serializerContext(...))]
    public function show(object $options): array
    {
        return ['url' => '/items'];
    }

    private static function serializerContext(array $result, CallableContextInterface $invocation): array
    {
        return ['json_encode_options' => $invocation->getParameter('options')->flags];
    }
}

it('executes a private native attribute context factory through the real DI and serializer pipeline', function (): void {
    $serializer = new Serializer([], [new JsonEncoder()]);
    $container = (new ContainerFactory())->create(new Config([], new Environment([])), new DependencyDefinitions([
        ConfigKey::SERVICES => [SerializerInterface::class => $serializer],
    ]));
    $pipeline = new InterceptingExecutor(
        $container->get(CallableExecutorInterface::class),
        new AttributeInterceptor($container->get(FactoryInterface::class)),
    );
    $target = new NativeContextFactoryTarget();

    expect($pipeline->call([$target, 'show'], ['options' => (object) ['flags' => JSON_UNESCAPED_SLASHES]]))->toBe('{"url":"/items"}')
        ->and($pipeline->call([$target, 'show'], ['options' => (object) ['flags' => 0]]))->toBe('{"url":"\\/items"}');
});
