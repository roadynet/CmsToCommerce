# PHPStan Audit - 2026-07-01

## Scope

This audit records the PHPStan/static-analysis status for CTC.

Unlike SkillBuilder's private codebase, CTC does not yet commit a PHPStan
configuration or baseline. The current public evidence is therefore documented
as a static-analysis readiness audit rather than a completed PHPStan cleanup.

## Current State

Current repeatable gates:

```text
composer validate --strict
php bin/console lint:container --env=test
php bin/console lint:twig templates
php bin/phpunit
Markdown link check
secret-token pattern check
GitHub Actions CI
```

Recorded result:

```text
54 tests
462 assertions
CI: success
```

## Why No Baseline Is Published Yet

No `phpstan.neon` or `phpstan-baseline.neon` is committed. Publishing a
PHPStan-audit claim without a repeatable setup would be misleading.

## Next Step

Recommended implementation path:

```text
composer require --dev phpstan/phpstan phpstan/phpstan-symfony
vendor/bin/phpstan analyse src tests
```

Start with service and integration layers, then add Doctrine-aware support for
entities if needed.

## Audit Position

PHPStan is a documented next quality gate. Current code quality is protected by
Symfony linting, PHPUnit and CI, while this file keeps the static-analysis gap
visible and actionable.
