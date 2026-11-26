# Callback-built serializer context

**Status:** normative implementation contract. The implementation targets PHP 8.5.13+, Interceptor 4, DI 6, Config 4, and Symfony Serializer 8.1+.

## Objective

Allow `#[Serialize]` to compute serializer context through an explicit native callback. The callback must observe well-defined invocation inputs, return a validated serializer context, and execute at a documented point between successful handler completion and serialization. Application-dependent context computation must not require implicit parameter-to-property conventions.

## Evidence and target baseline

- Repository: `componenta/serialize-interceptor`.
- Pre-feature baseline: `main` at `5451834606cf521a0b2c1321887d27477002dc29`.
- No `AGENTS.md` or existing specification was present in the inspected tree.
- Production surface consists of `src/Attribute/Serialize.php` and `src/SerializeInterceptor.php`.
- `Serialize` extends `Componenta\Interceptor\Attribute\Intercept` and forwards `format` and `context` constructor arguments to `SerializeInterceptor` through the factory path.
- Baseline `context` is `array<string, mixed>` in documentation. Its implementation also interprets a string key matching a target parameter name plus a string value as a parameter-property map. `Serialize::ATTR_MAP` renames those extracted properties.
- Existing result wrapping uses `Serialize::ATTR_DATA` and `Serialize::ATTR_CONTEXT`. When the data marker exists, the wrapper context overrides attribute context. Invalid non-array or non-string-key return context is rejected.
- The implementation executes the handler first, resolves context second, and serializes third. It does not configure Symfony Serializer; applications provide `Symfony\Component\Serializer\SerializerInterface`.
- The pre-feature `composer.json` required PHP `^8.4`, Interceptor `^3.0.0`, Symfony Serializer `^7.4 || ^8.0`; development dependencies are DI `^5.0.0`, Config `^3.0.0`, and Pest `^4.0`.
- Target integration uses Interceptor `spec/attribute-callbacks-2026-11-26`, derived from `refactor/di6-interceptor` at `cb68b34fd47b9c4d430c237a9ef25756e54aa055`. Its public context contract includes `getParameter`, prepared arguments, and nullable reflection. The DI compatibility branch `fix/callback-dependency-baseline-2026-11-26` supplies DI 6 with Config 4.

Source permalinks:

- [Serialize attribute](https://github.com/componenta/serialize-interceptor/blob/5451834606cf521a0b2c1321887d27477002dc29/src/Attribute/Serialize.php)
- [Current serialization behavior](https://github.com/componenta/serialize-interceptor/blob/5451834606cf521a0b2c1321887d27477002dc29/src/SerializeInterceptor.php)
- [Current behavior tests](https://github.com/componenta/serialize-interceptor/blob/5451834606cf521a0b2c1321887d27477002dc29/tests/SerializeInterceptorTest.php)
- [Named-context integration tests](https://github.com/componenta/serialize-interceptor/blob/5451834606cf521a0b2c1321887d27477002dc29/tests/NamedContextTest.php)
- [Dependency manifest](https://github.com/componenta/serialize-interceptor/blob/5451834606cf521a0b2c1321887d27477002dc29/composer.json)
- [Target public invocation context](https://github.com/componenta/interceptor/blob/cb68b34fd47b9c4d430c237a9ef25756e54aa055/src/CallableContextInterface.php)
- [PHP closures in constant expressions](https://wiki.php.net/rfc/closures_in_const_expr) and [first-class callable expressions](https://wiki.php.net/rfc/fcc_in_const_expr)
- [PHP 8.5.13 fixes](https://www.php.net/ChangeLog-8.php#8.5.13)

## Scope and boundaries

This package owns conversion of a callable result to a serialized string and construction of the serializer context for that operation. It consumes Symfony's `SerializerInterface` and Componenta's public interceptor contracts. The callback is invoked natively with explicit semantic inputs; DI suffix injection is outside this contract. Factory construction continues to integrate through the public interceptor/DI boundary.

It does not own normalizer registration, encoder registration, authorization policy, HTTP response creation, content negotiation, DI container construction, or serializer-context key semantics defined by Symfony or application normalizers.

No new callback registry, serializer abstraction duplicating Symfony's interface, generic expression language, or application build phase is justified by this change.

## Settled design constraints

1. Context callback execution occurs only after successful continuation completion and before `SerializerInterface::serialize`.
2. If the continuation throws, context computation and serialization do not run.
3. If context computation throws or produces an invalid context, serialization does not run. No automatic retry or rollback is introduced.
4. A successful interception returns the serializer's exact string result.
5. The serializer receives the same data identity/value selected by the serialization contract. Reassigning a callback argument, including a by-reference parameter, must not replace the package's selected serialization-data slot. The callback cannot replace the format. This is top-level slot protection, not deep cloning: mutations to shared nested objects retain normal PHP object identity and effects.
6. Static context data and context produced for one invocation must not be mutated or retained as another invocation's dynamic state. Nested objects follow normal shared-object semantics unless an explicit ownership contract says otherwise; do not promise deep cloning.
7. Interceptor attribute ordering remains unchanged: a response wrapper outside `#[Serialize]` receives the serialized string, and an inner serializer receives the result returned by its continuation.
8. Use `CallableContextInterface` for invocation state. The callback receives the exact context instance passed into `SerializeInterceptor::intercept`. Do not assume the concrete `CallableContext` implementation or a non-null reflector. An inner continuation's immutable `withParameters` or `withCallable` replacement does not automatically replace the context observed by this interceptor.
9. Production DI integration uses public contracts, never `Container` or `Componenta\DI\Internal`.
10. PHP native attribute callback syntax and scope follow the DI 6 baseline: capture-free `static function` or a declared function/static-method first-class reference, with PHP `^8.5.13` for the selected integration.

## Public API

Retain the existing `context` array, including supported parameter mappings, and append an optional constructor argument `?Closure $contextFactory = null` to the attribute and its runtime interceptor. `Serialize` forwards the factory through the existing interceptor factory parameters. The dedicated argument separates callback invocation from array context configuration:

```php
#[Serialize(
    context: ['groups' => ['public']],
    contextFactory: static function (
        mixed $result,
        CallableContextInterface $invocation,
    ): array {
        $query = $invocation->getParameter('query');

        return ['include_author' => $query->includeAuthor];
    },
)]
```

The merge contract is: resolve the existing static options and parameter-property mappings, apply any return-level wrapper context, invoke the factory once when present, validate its result, and shallowly replace earlier keys with dynamic keys. Nested arrays are replaced rather than recursively merged; null is an explicit value; an empty dynamic array leaves static context unchanged. Neither input array is mutated.

A `context: array|Closure` input is not recommended: a separate factory lets callers keep ordinary static serializer options alongside the computed overrides without reconstructing them in every callback. The feature adds only `contextFactory`, not parallel equivalent callback entry points.

### Callback contract

- Invoke natively with `(mixed $result, CallableContextInterface $invocation)` after successful continuation completion. Business values are available explicitly through the public `getParameter` contract. `$invocation` is the exact context instance originally supplied to this interceptor; a downstream layer may pass a different immutable context to its own continuation, but that replacement is not propagated backward through the returned result.
- Do not resolve additional callback parameters through DI. Missing required extra parameters and incompatible types follow native PHP invocation errors. Parameter attributes on the callback do not trigger DI processing.
- A user-defined callback may omit unused trailing inputs according to native PHP calling semantics. Invoke it using callback-local argument slots: if a callback declares its result or invocation input by reference and reassigns that parameter, the reassignment must not replace the selected serialization data or the interceptor's invocation-context slot. Nested objects retain their supplied identity; their mutation is not isolated or rolled back, and the package does not deep-clone input graphs.
- Require a returned `array<string, mixed>`; reject a non-array or any integer key with `InvalidArgumentException` before invoking the serializer. Context values remain open because Symfony and application normalizers own them.
- Callback-body Throwables, native invocation errors, and serializer Throwables propagate unchanged.
- With an ordinary continuation result, `$result` is that exact value/object. With the existing return wrapper, `$result` is the selected `Serialize::ATTR_DATA` value. Wrapper context is validated before the callback; callback overrides have final priority. Malformed wrapper context prevents callback execution and serialization.

## Existing behavior and compatibility

The callback feature is additive. A separate optional `contextFactory` makes executable context construction explicit while retaining existing consumers' parameter maps and return wrappers. Removing those features is not required to support native callbacks or the latest dependency contracts.

| Existing contract | Selected behavior |
|---|---|
| Literal `context` options | Preserve existing options as the first context layer. |
| Parameter-name / property-name inference | Preserve existing interpretation; applications can instead calculate explicit options through `contextFactory`. |
| `Serialize::ATTR_MAP` | Preserve renaming of mapped properties. |
| `Serialize::ATTR_DATA` / `ATTR_CONTEXT` | Preserve data selection and validated return context. The callback sees selected data and its context overrides run after return context. |
| Direct `new SerializeInterceptor(...)` | Append optional `contextFactory`; no required invoker/container dependency is added. |
| Optional reflector | A null reflector disables declaration-based parameter mapping; ordinary static options and callback context still work. |

The dependency transition is an upcoming major package line: PHP 8.5.13+, Interceptor 4, DI 6, Config 4, and Symfony Serializer 8.1+. The feature branch declares the real `2.0.x-dev` alias. Runtime behavior for declarations without the new callback is preserved within those current dependency contracts.

## Invocation and lifetime constraints

The sequence is successful continuation, existing static/mapped context resolution, optional wrapper context validation and data selection, callback invocation if present, computed context validation and override, and one serializer call. A callback cannot be evaluated during metadata discovery or application bootstrap.

Attribute metadata may be cached, but per-invocation callbacks and their temporary objects must follow native attribute materialization. Ordinary `serialize()` cannot persist PHP Closures; this change does not introduce persistent serialized attribute instances. Repeated execution of the same declaration must preserve cold/warm behavior, exception phase, private/trait lexical scope, and per-call dependency selection.

Context-dependent authorization stays in application policy. This package must not cache one actor's generated serializer context and reuse it for another actor.

## Contract-driven verification design

Use a recording implementation of the public Symfony `SerializerInterface` to verify the data, format, and context at the actual package boundary. It is an existing substitution boundary, not a reason to add another production interface. Use a public `CallableContextInterface` conformance implementation to avoid accidentally depending on the concrete context.

| Contract property | Acceptance evidence |
|---|---|
| Callback receives declared inputs | Fixed result and public context are observed with identity/value preserved. |
| Top-level callback slot isolation | A callback with by-reference result/context parameters reassigns both. The serializer still receives the originally selected data, and the interceptor retains its original context slot. A separate control mutates a nested object and proves that its identity and ordinary shared mutation are preserved without deep cloning. |
| Context observed after continuation | An inner layer uses `withParameters` or `withCallable` and passes that replacement to its continuation. The callback still receives the exact context originally passed into `SerializeInterceptor`; it does not receive the downstream replacement or infer its parameters from the returned result. |
| Successful sequence | Trace continuation, context callback, and serializer; each executes once in the specified order. |
| Handler failure | Neither context callback nor serializer runs; the original Throwable reaches the caller. |
| Callback failure | Serializer remains uncalled and callback Throwable identity is preserved. |
| Invalid callback result | Non-array and integer-key context values fail before serialization. |
| Static/dynamic composition | Resolved static/mapped context is the base; returned context overrides it; computed context overrides both. Overlapping keys, explicit null, empty arrays, and nested arrays match the shallow-merge contract. |
| Native invocation without DI suffix | A required third callback parameter fails natively and no service resolver is consulted; the serializer is not called. |
| Named and positional invocation | Callback parameter lookup works through prepared public contexts for both call forms. |
| No reflector assumption | Static and callback context work for a valid custom context whose reflector is null. |
| Data/result boundary | Null, scalar, array, object, and the supported return wrapper follow the data-selection rule. |
| Native syntax/scope | Inline static closure, function reference, private static method reference, and a trait used by two classes preserve scope. |
| Cache/lifetime parity | Cold/warm calls see current invocation inputs; weak references reveal no retention of per-call result/context objects. |
| Real integration | `#[Serialize]` through native `AttributeInterceptor`, public DI 6 services, a real Symfony serializer, and an outer HTTP response wrapper produces the expected response body. |
| Existing conventions | Parameter mapping, `ATTR_MAP`, and return wrappers keep their existing behavior; callback overrides are applied last. |
| Dependency boundaries | Production code uses public interceptor/DI/Symfony contracts and an installable Composer graph. |

Each implemented behavior requires a failing public-contract test before its implementation. Existing `composer test` invokes Pest. Do not add tests of private context-resolution helpers or replicate implementation expressions as assertions.

## Grounded change surface

- `src/Attribute/Serialize.php`: optional callback declaration and forwarding through the existing factory parameters; keep existing constants.
- `src/SerializeInterceptor.php`: explicit sequence, callback-local argument slots, context validation/composition, and nullable-reflector support.
- `tests/SerializeInterceptorTest.php`: static context, callback context, serializer boundary, errors, and order.
- `tests/NamedContextTest.php`: target DI 6 integration and named/prepared argument behavior.
- `tests/bootstrap.php`: test runtime wiring only if needed by the selected DI/Interceptor version.
- `README.md` and `README.ru.md`: real callback syntax, context result rules, source/result timing, and migration examples.
- `composer.json`: require the selected PHP/Interceptor/DI/Config/Symfony graph and the real feature-branch alias. Production code consumes the interceptor and serializer contracts; DI is used by integration tests through the existing factory boundary.

## Implementation and verification

The implementation first established failing public capability tests, then added the optional attribute/runtime constructor argument, deterministic merge sequence, callback input-slot isolation, result validation, and null-reflector handling. Existing parameter-mapping and return-wrapper tests remain preservation controls.

`tests/ContextFactoryTest.php` covers direct invocation, returned data identity, order, errors, override priority, repeated calls, native invocation, and the real DI 6/attribute/Symfony pipeline. `tests/SerializeInterceptorTest.php` and `tests/NamedContextTest.php` retain the established static, mapped, and returned-context behaviors. Static analysis is part of the package's `composer analyse` command.

CI checks out the actual Interceptor feature branch and DI Config 4 compatibility branch, using their declared aliases and explicit root development constraints. Production dependency ranges remain semantic constraints. The runtime matrix covers PHP 8.5.13 and the latest 8.5 patch. See [callback development verification](../development/attribute-callbacks.md) for installation steps, dependency revisions, and observed results.

## Final convergence requirement

Compare the implementation with the callback API, context/data selection sequence, compatibility decisions, dependency direction, and per-invocation lifecycle. Resolve every material omission or unrequested second path before completion.
