# Quality Report

## Automated Tests

Current public repository test evidence:

```text
PHPUnit 13.2.1
Runtime: PHP 8.4.21

54 tests
462 assertions
OK
```

## Test Coverage Areas

### Sectionscode Import

Tests cover grouping TXT and image files into product imports:

- `1.1.txt` starts one product
- `1.1.png`, `1.1.1.png`, `1.1.2.png` attach as media
- `1.2.txt` starts the next product

### Listing Generation

Tests cover listing-draft generation for marketplace-ready product data:

- titles
- bullet points
- descriptions
- attributes
- keywords
- quality-score hints

### Amazon Preparation

Tests cover Amazon Product Type mapping and Listings Item payload preparation.
Live Amazon publishing is intentionally not enabled in the public demo.

### Shopware / External Integrations

Tests cover payload normalization and connector behavior for:

- Shopware
- Amazon SP-API preparation
- JTL
- plentymarkets
- Xentral
- SAP R/3
- Pimcore
- Shopify

### Credentials and Secrets

Tests cover the channel credential manager and ensure secrets can be handled
through private configuration instead of hardcoded repository values.

## Manual / Tool Checks

Performed checks:

- Composer validation
- Symfony container lint
- Twig lint
- PHPStan Level 3
- PHPUnit
- Markdown link check
- secret-token pattern scan
- live demo HTTP check
- GitHub Actions status check

## Important Findings Fixed

- Missing production migration for `external_sync_job` was identified,
  applied and documented as a production case study.
- Demo-/workflow wording was removed from customer-facing Shopware export text.
- Secrets were moved to server/private-config resolution and masked credential
  forms.

## Evidence

- [Portfolio Audit Report](audit-report-2026-07-01.md)
- [Production Evidence](production-evidence.md)
- [Operations Runbook](../OPERATIONS.md)
- [Case Study: Database Migration](case-study-database-migration.md)
- [PHPStan Audit](phpstan-audit-2026-07-01.md)
- [Static Analysis Audit](static-analysis-audit-2026-07-01.md)
