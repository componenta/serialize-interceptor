# Callback-built serializer context

**Readiness:** blocked by reconciliation with the user-requested conversation, compatibility decisions, and the target DI 6 / Interceptor 4 dependency graph.

This is a grounded design draft. The recommendations below are proposed decisions, not decisions attributed to an unavailable discussion. The public shape and disposition of legacy conventions must be reconciled with the requested design context before production slices become executable.

## Objective

Allow `#[Serialize]` to compute serializer context through an explicit native callback. The callback must observe well-defined invocation inputs, return a validated serializer context, and execute at a documented point between successful handler completion and serialization. Application-dependent context computation must not require implicit parameter-to-property conventions.

## Evidence and target baseline

- Repository: `componenta/serialize-interceptor`.
- Current and only inspected branch: `main` at `5451834606cf521a0b2c1321887d27477002dc29`.
- No `AGENTS.md` or existing specification was present in the inspected tree.
- Production surface consists of `src/Attribute/Serialize.php` and `src/SerializeInterceptor.php`.
- `Serialize` extends `Componenta\Interceptor\Attribute\Intercept` and forwards `format` and `context` constructor arguments to `SerializeInterceptor` through the factory path.
- Current `context` is `array<string, mixed>` in documentation. Its implementation also interprets a string key matching a target parameter name plus a string value as a parameter-property map. `Serialize::ATTR_MAP` renames those extracted properties.
- Current result wrapping uses `Serialize::ATTR_DATA` and `Serialize::ATTR_CONTEXT`. When the data marker exists, the wrapper context overrides attribute context. Invalid non-array or non-string-key return context is rejected.
- The implementation executes the handler first, resolves context second, and serializes third. It does not configure Symfony Serializer; applications provide `Symfony\Component\Serializer\SerializerInterface`.
- `composer.json` requires PHP `^8.4`, Interceptor `^3.0.0`, Symfony Serializer `^7.4 || ^8.0`; development dependencies are DI `^5.0.0`, Config `^3.0.0`, and Pest `^4.0`.
- Proposed target integration uses Interceptor's `refactor/di6-interceptor` at `cb68b34fd47b9c4d430c237a9ef25756e54aa055`, whose public context contract includes `getParameter`, prepared arguments, and nullable reflection.

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

This package owns conversion of a callable result to a serialized string and construction of the serializer context for that operation. It consumes Symfony's `SerializerInterface` and Componenta's public interceptor contracts. The recommended callback is invoked natively with explicit semantic inputs; DI suffix injection is not planned in this proposal. Factory construction continues to integrate through the public interceptor/DI boundary.

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

## Recommended public API proposal

Retain a literal static `context` array and append an optional constructor argument `?Closure $contextFactory = null` to the attribute and its runtime interceptor. `Serialize` forwards the factory through the existing interceptor factory parameters. This makes static data and executable context construction unambiguous:

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

This is a proposed API, not existing supported syntax. Its recommended merge contract is: start with the literal static context, invoke the factory once when present, validate its result, and shallowly replace static keys with dynamic keys. Nested arrays are replaced rather than recursively merged; null is an explicit value; an empty dynamic array leaves static context unchanged. Neither input array is mutated.

A `context: array|Closure` input is not recommended: a separate factory lets callers keep ordinary static serializer options alongside the computed overrides without reconstructing them in every callback. The proposal adds only `contextFactory`, not parallel equivalent callback entry points.

### Proposed callback contract

- Invoke natively with `(mixed $result, CallableContextInterface $invocation)` after successful continuation completion. Business values are available explicitly through the public `getParameter` contract. `$invocation` is the exact context instance originally supplied to this interceptor; a downstream layer may pass a different immutable context to its own continuation, but that replacement is not propagated backward through the returned result.
- Do not resolve additional callback parameters through DI. Missing required extra parameters and incompatible types follow native PHP invocation errors. Parameter attributes on the callback do not trigger DI processing.
- A user-defined callback may omit unused trailing inputs according to native PHP calling semantics. Invoke it using callback-local argument slots: if a callback declares its result or invocation input by reference and reassigns that parameter, the reassignment must not replace the selected serialization data or the interceptor's invocation-context slot. Nested objects retain their supplied identity; their mutation is not isolated or rolled back, and the package does not deep-clone input graphs.
- Require a returned `array<string, mixed>`; reject a non-array or any integer key with `InvalidArgumentException` before invoking the serializer. Context values remain open because Symfony and application normalizers own them.
- Callback-body Throwables, native invocation errors, and serializer Throwables propagate unchanged.
- With an ordinary continuation result, `$result` is that exact value/object. If legacy return wrappers remain supported, whether the callback receives the wrapper or its selected data and how wrapper context combines with dynamic context must be settled during reconciliation. That material unresolved interaction keeps the whole affected execution path blocked.

## Legacy behavior and compatibility decision

The current array context serves two meanings: literal serializer options and implicit parameter-property mapping. The final target must explicitly choose the disposition of:

| Current contract | Required decision |
|---|---|
| Literal `context` array | Recommended target: always literal static serializer options, merged before callback overrides. |
| Parameter-name / property-name inference | Recommended target: replace with explicit `contextFactory`; reconcile the breaking migration against the missing design context before removal. |
| `Serialize::ATTR_MAP` | Proposed removal together with implicit mapping; migrate known uses into explicit callback logic and remove parser/docs/tests prescribing the superseded convention only after reconciliation. |
| `Serialize::ATTR_DATA` / `ATTR_CONTEXT` result wrapper | Retain with exact callback timing/precedence, or replace with an explicit result value contract if required by the requested design. |
| Direct `new SerializeInterceptor(...)` usage | Recommended target appends optional `contextFactory`, adding no required DI invoker dependency. Changes caused by the legacy mapping/result-wrapper disposition still need a migration rule. |

No assumption that backward compatibility is required has been made. Retaining legacy inference indefinitely just because it exists would preserve ambiguous context interpretation. Conversely, removing it without recovering the user's decisions would invent a requirement. Existing applications and independently published consumers need an explicit migration description once the target is selected.

## Invocation and lifetime constraints

For an ordinary result, the proposed sequence is continuation result, literal static context, callback invocation if present, dynamic context validation and override, and one serializer call. The placement and precedence of optional legacy wrapper handling remains blocked until the missing design context is reconciled. A callback cannot be evaluated during metadata discovery or application bootstrap.

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
| Static/dynamic composition | Literal static context is the base; dynamic keys override it. Overlapping keys, explicit null, empty arrays, and nested arrays match the proposed shallow-merge contract. |
| Native invocation without DI suffix | A required third callback parameter fails natively and no service resolver is consulted; the serializer is not called. |
| Named and positional invocation | Callback parameter lookup works through prepared public contexts for both call forms. |
| No reflector assumption | Static and callback context work for a valid custom context whose reflector is null. |
| Data/result boundary | Null, scalar, array, object, and any retained return wrapper follow the finalized data-selection rule. |
| Native syntax/scope | Inline static closure, function reference, private static method reference, and a trait used by two classes preserve scope. |
| Cache/lifetime parity | Cold/warm calls see current invocation inputs; weak references reveal no retention of per-call result/context objects. |
| Real integration | `#[Serialize]` through native `AttributeInterceptor`, public DI 6 services, a real Symfony serializer, and an outer HTTP response wrapper produces the expected response body. |
| Clean migration | Removed magic keys are ordinary serialized data/context keys or rejected exactly as specified; no old parser path or obsolete examples remain. |
| Dependency boundaries | Production code uses public interceptor/DI/Symfony contracts and an installable Composer graph. |

Each implemented behavior requires a failing public-contract test before its implementation. Existing `composer test` invokes Pest. Do not add tests of private context-resolution helpers or replicate implementation expressions as assertions.

## Grounded change surface

- `src/Attribute/Serialize.php`: final callback declaration input, forwarding, and disposition of magic constants.
- `src/SerializeInterceptor.php`: explicit sequence, callback invocation, context validation/composition, and removal or retention of old inference.
- `tests/SerializeInterceptorTest.php`: static context, callback context, serializer boundary, errors, and order.
- `tests/NamedContextTest.php`: target DI 6 integration and named/prepared argument behavior.
- `tests/bootstrap.php`: test runtime wiring only if needed by the selected DI/Interceptor version.
- `README.md` and `README.ru.md`: real callback syntax, context result rules, source/result timing, and migration examples.
- `composer.json`: move to the selected PHP/Interceptor/DI/Config graph; production DI requirements must be declared if production code directly consumes DI interfaces.

## Bounded evidence-acquisition plan

### E1 — Recover requested design decisions

Obtain the relevant user-supplied design decisions from the unavailable prior discussion. Compare them with the recommended optional `contextFactory`, native two-input invocation without DI suffix, literal-static-then-dynamic override, and proposed removal of implicit parameter mapping. Explicitly settle the result wrapper's retention/removal, callback data selection, and context precedence. Stop when the normative sequence and compatibility obligations are complete.

### E2 — Settle installable dependency baseline

Use the target Interceptor 4 / DI 6 public contracts and verify Composer constraints together. The inspected Interceptor branch requires Config `^4.0`; inspected DI 6 refs require `^3.0.0`. Determine the owner and revision of the required Config migration before claiming end-to-end acceptance. No manifest changes are authorized by this specification-only draft.

## Production execution outline — not executable while blocked

Once E1 and E2 are resolved, derive vertical slices for: (1) callback context through direct interceptor use with its boundary tests; (2) native attribute and real DI integration; (3) removal/migration of superseded context conventions, if selected, with negative controls; (4) scope/lifetime/cache parity and documentation convergence. Keep each slice coherent and include failure proof, minimum implementation, cleanup, and observable done conditions. Do not split all tests from all implementation.

## Final convergence requirement

Compare the implementation with the finalized callback API, context/data selection sequence, compatibility decisions, dependency direction, and per-invocation lifecycle. Resolve every material omission or unrequested second path before completion.
