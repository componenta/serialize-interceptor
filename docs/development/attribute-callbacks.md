# Serializer callback development and verification

## Dependency target

This package targets PHP 8.5.13+, Interceptor 4, DI 6, Config 4, and Symfony Serializer 8.1+. Its feature branch has the real `2.0.x-dev` alias. The tracked Composer manifest expresses these semantic dependency ranges.

The verified source integration uses Interceptor's `spec/attribute-callbacks-2026-11-26` branch and DI [`ded9a6dc44db2775c4753e73d9581d70caac6043`](https://github.com/componenta/di/commit/ded9a6dc44db2775c4753e73d9581d70caac6043), whose Config 4 compatibility change resolves the previous dependency conflict. Composer selected Config 4.0.0, Reflection 3.0.0, Scope 1.0.0, Validation 3.1.0, and Symfony Serializer 8.1.12.

## Reproduce from a clean checkout

Run from this package's feature branch with PHP 8.5.13+ and Composer:

```sh
git clone --no-checkout https://github.com/componenta/di.git .dependencies/di
git -C .dependencies/di switch -c fix/callback-dependency-baseline-2026-11-26 ded9a6dc44db2775c4753e73d9581d70caac6043
git clone --branch spec/attribute-callbacks-2026-11-26 https://github.com/componenta/interceptor.git .dependencies/interceptor
php tools/development-composer.php
COMPOSER=.composer-development.json composer update --no-interaction --prefer-dist
composer test
composer analyse
```

The helper creates an ignored development manifest with real path repositories and explicit root branch constraints. It leaves the tracked manifest unchanged and does not override package versions or ignore platform requirements. Optional arguments select existing DI and Interceptor checkouts, in that order. CI uses the same recipe on PHP 8.5.13 and the latest 8.5 patch.

## Observed verification

On PHP 8.5.13 and Composer 2.11.2:

- The 15 new callback cases failed on the missing capability and passed after implementation, including real native attribute/DI integration.
- The complete package suite passed: 21 tests, 47 assertions.
- PHPStan at maximum level passed for production code and public consumers with concrete data callback types. The consumer first reproduced three incompatible PHPDoc diagnostics, then passed after the documentation was aligned with native invocation.
- Existing static context, parameter mapping, named parameter resolution, and return wrappers remain covered.

The new cases verify callback timing, selected data identity, override precedence, shallow merging, explicit null, invalid results, failure identity, by-reference reassignment isolation, shared nested objects, original entry context, independent repeated invocations, null reflection, native argument failures, and a private static first-class callback executed through DI 6 and Symfony Serializer.

The change retains existing maps and return wrappers. `contextFactory` executes after their context/data selection and has final override priority. Its parameters are explicit native arguments; service suffix injection is outside this contract.
