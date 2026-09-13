<?php

declare(strict_types=1);
use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\ContainerFactory;
use Componenta\Interceptor\InterceptingExecutor;
use Componenta\Interceptor\Serialization\Attribute\Serialize;
use Componenta\Interceptor\Serialization\SerializeInterceptor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Serializer;

it('resolves dynamic serializer context from named callable arguments', function (): void {
    $container = (new ContainerFactory())->create(new Config([], new Environment([])), new DependencyDefinitions([]));
    $interceptor = new SerializeInterceptor(new Serializer([], [new JsonEncoder()]), context: [
        'options' => 'flags', Serialize::ATTR_MAP => ['flags' => 'json_encode_options'],
    ]);
    $pipeline = new InterceptingExecutor($container->get(CallableExecutorInterface::class), $interceptor);
    $options = (object) ['flags' => JSON_UNESCAPED_SLASHES];
    $callable = static fn (object $options): array => ['url' => '/items'];
    expect($pipeline->call($callable, ['options' => $options]))->toBe('{"url":"/items"}');
});

it('rejects malformed return-level serializer context explicitly', function (mixed $returnedContext): void {
    $container = (new ContainerFactory())->create(new Config([], new Environment([])), new DependencyDefinitions([]));
    $pipeline = new InterceptingExecutor(
        $container->get(CallableExecutorInterface::class),
        new SerializeInterceptor(new Serializer([], [new JsonEncoder()]))
    );
    expect(fn () => $pipeline->call(static fn (): array => [
        Serialize::ATTR_DATA => ['value' => 1],
        Serialize::ATTR_CONTEXT => $returnedContext,
    ]))->toThrow(InvalidArgumentException::class, 'serializer context');
})->with(['non-array' => ['invalid'], 'numeric keys' => [[1]]]);
