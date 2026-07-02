# PHPStan Audit - 2026-07-01

## Scope

This audit records the PHPStan/static-analysis status for CTC.

CTC now commits a repeatable PHPStan setup and runs it in GitHub Actions.

## Current State

Current repeatable gates:

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

Recorded result:

```text
54 tests
462 assertions
PHPStan: no errors
CI: success
```

## Baseline Position

No PHPStan baseline is committed. The level 3 analysis runs directly against
the current codebase with Symfony extension support.

## Implemented Setup

Committed setup:

```text
phpstan.neon
phpstan/phpstan
phpstan/phpstan-symfony
GitHub Actions step: vendor/bin/phpstan analyse --memory-limit=1G
```

The first run fixed redundant timestamp fallback logic reported by PHPStan.

## Audit Position

PHPStan is now an enforced public quality gate alongside Symfony linting,
PHPUnit and CI documentation checks.
