# Static Analysis Audit - 2026-07-01

## Scope

This audit documents the current static-analysis and quality-gate position for
CTC.

CTC now publishes a PHPStan setup without a baseline. The public gate combines
Composer validation, Symfony container linting, Twig linting, PHPStan, PHPUnit
and secret/link checks.

## Current Gates

```text
composer validate --strict
php bin/console lint:container --env=test
php bin/console lint:twig templates
vendor/bin/phpstan analyse --memory-limit=1G
php bin/phpunit
Markdown link check
secret-token pattern check
GitHub Actions CI
```

## Current Result

```text
54 tests
462 assertions
PHPStan: no errors
CI: success
```

## PHPStan Position

The current position is:

- Symfony container lint validates service wiring and constructor injection.
- PHPUnit protects domain and integration-normalization logic.
- Twig lint protects templates.
- PHPStan level 3 protects PHP code paths in `src` and `tests`.
- CI makes the checks repeatable.
- No baseline is needed for the current level 3 setup.

## Implemented Setup

Committed setup:

```text
phpstan.neon
phpstan/phpstan
phpstan/phpstan-symfony
CI step: vendor/bin/phpstan analyse --memory-limit=1G
```

## Audit Position

The project has repeatable CI quality gates including static analysis. Future
work can raise the PHPStan level gradually once the current level remains stable.
