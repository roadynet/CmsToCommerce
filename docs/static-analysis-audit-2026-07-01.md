# Static Analysis Audit - 2026-07-01

## Scope

This audit documents the current static-analysis and quality-gate position for
CTC.

SkillBuilder has a documented PHPStan cleanup in its private codebase. CTC does
not yet publish a PHPStan baseline. Instead, the current public gate combines
Composer validation, Symfony container linting, Twig linting, PHPUnit and
secret/link checks.

## Current Gates

```text
composer validate --strict
php bin/console lint:container --env=test
php bin/console lint:twig templates
php bin/phpunit
Markdown link check
secret-token pattern check
GitHub Actions CI
```

## Current Result

```text
54 tests
462 assertions
CI: success
```

## Why PHPStan Is Not Claimed Yet

No public PHPStan baseline is committed for CTC at this stage. Claiming PHPStan
coverage without a repeatable committed setup would weaken the evidence.

The honest current position is:

- Symfony container lint validates service wiring and constructor injection.
- PHPUnit protects domain and integration-normalization logic.
- Twig lint protects templates.
- CI makes the checks repeatable.
- PHPStan remains a tracked next quality gate.

## Proposed Next Step

Add a dedicated PHPStan setup:

```text
composer require --dev phpstan/phpstan phpstan/extension-installer phpstan/phpstan-symfony
```

Then add:

```text
phpstan.neon
phpstan-baseline.neon if needed
CI step: vendor/bin/phpstan analyse
```

The preferred approach is to start narrow, for example `src/Service`,
`src/Integration` and `tests`, then expand toward entities/controllers with a
small explainable baseline.

## Audit Position

The project has repeatable CI quality gates today and a clearly documented path
to static analysis tomorrow. This is intentionally documented as a gap rather
than hidden behind vague quality claims.
