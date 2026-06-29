---
name: product-listing-builder
description: Build marketplace-ready product listings from TXT source data and product photos for Amazon and Shopware. Use when Codex needs to convert raw product text, manufacturer copy, article exports, image sets, or optional inputs such as price, brand, language, category, and variants into a complete listing with title, bullet points, description, technical attributes, keywords, image order guidance, quality scoring, missing-data flags, and export-ready structured output.
---

# Product Listing Builder

## Overview

Turn mixed product inputs into a clean, channel-aware listing without inventing facts. Normalize the source first, separate observed evidence from inference, then produce listing copy, structured attributes, image guidance, and a scored quality review.

## Quick start

1. Gather the available inputs: TXT, product photos, and optional price, brand, target marketplace, language, category, or variant info.
2. If the TXT is messy, run `python scripts/normalize-product-data.py <input-file>` and use the JSON output as the working fact base.
3. Read [references/quality-rules.md](references/quality-rules.md) for every run.
4. Read [references/amazon-checklist.md](references/amazon-checklist.md) when the target includes Amazon.
5. Read [references/shopware-checklist.md](references/shopware-checklist.md) when the target includes Shopware.
6. Produce the result in the canonical output structure unless the user requests a different export shape.
7. Stop and flag missing or conflicting data instead of guessing.

## Working rules

- Treat every fact as one of three states:
  - Observed: explicit in text or clearly visible in images
  - Inferred: likely from combined evidence, but not directly stated
  - Missing: needed for a strong listing, but unsupported by evidence
- Prefer precision over hype. Remove filler, empty superlatives, and duplicated claims.
- Keep text and images consistent. If the photo set conflicts with the TXT, surface the conflict in the audit.
- Do not invent certifications, safety claims, compatibility, dimensions, materials, quantities, or box contents.
- For rule-sensitive marketplace checks, verify against the latest official documentation when internet access is available. If not, mark the compliance check as best-effort.
- When confidence is low, say so plainly and ask for the smallest missing data point needed to improve the listing.

## Workflow

### 1. Normalize the inputs

- Extract all explicit facts from the TXT and image set.
- Identify product type, likely category, brand, model, material, color, size, compatibility, pack count, and likely variant axes.
- Build a compact fact table with confidence notes and source evidence.

### 2. Detect gaps and risks

- Flag mandatory or high-impact missing fields before writing copy.
- Detect contradictions between product text, filenames, visible packaging, and photos.
- If multiple products or child variants appear in one source, split them before drafting final export rows.
- If variants exist, confirm that the variant family has a clean axis such as size, color, or quantity. Do not merge unrelated products.

### 3. Write the listing

- Write one clear title for the chosen marketplace and language.
- Write bullets that combine features with buyer value instead of repeating specs mechanically.
- Write a description that clarifies use, differentiators, and important constraints without sounding padded.
- Output technical data separately from the marketing copy.
- Generate search terms from intent, synonyms, and relevant attributes without turning the output into keyword spam.

### 4. Review and score

- Score the listing with [references/quality-rules.md](references/quality-rules.md).
- Do not claim A-level if mandatory data is missing, the photo set is incomplete for the product type, or variant logic is broken.
- Add a short remediation list for the exact issues blocking A-level quality.

## Canonical output

Return the result in this structure unless the user requests a different format:

```yaml
product_summary:
  product_type:
  target_marketplace:
  language:
  category:
  brand:
  variant_model:
  confidence_note:

source_audit:
  observed_facts:
  inferred_facts:
  missing_or_unverified:
  conflicts:

listing:
  title:
  bullet_points:
  description:
  technical_attributes:
  search_terms:
  image_sequence:
  image_improvement_notes:

quality_review:
  score:
  grade:
  strengths:
  blockers:
  fixes_to_reach_a_level:

export:
  amazon:
  shopware:
```

## Export guidance

### Amazon

- Prefer one compact, searchable title with brand, product type, and the most relevant evidence-backed differentiators.
- Prefer five buyer-relevant bullet points unless the user or template requires a different count.
- Keep backend search terms distinct from the title and bullets where possible.
- Include explicit variant notes, image ordering guidance, and unsupported-claim checks.
- Use [references/amazon-checklist.md](references/amazon-checklist.md) before finalizing.

### Shopware

- Preserve structured attributes so they can be mapped cleanly during import.
- Keep long description blocks readable in plain text or simple HTML, depending on the user's target workflow.
- Separate category properties, manufacturer data, identifiers, and variant options from the prose description.
- Include meta-oriented fields only when the user wants import-ready SEO data.
- Use [references/shopware-checklist.md](references/shopware-checklist.md) before finalizing.

## Image review standard

- Evaluate whether the image set covers hero shot, angle/context, details, scale or dimensions, included items, packaging, and variant-specific visuals where relevant.
- Propose the best image order for the target marketplace.
- If the images do not support a strong listing, say exactly what photo is missing.

## Missing-data behavior

- If a required field is absent, list it under `missing_or_unverified`.
- If a high-impact field can be inferred but not proven, place it under `inferred_facts` and lower the quality score accordingly.
- If the user asks for a final export despite gaps, deliver the best safe draft and keep unresolved values visibly marked.

## Resource use

- Use `scripts/normalize-product-data.py` when the source TXT is noisy, semi-structured, or copied from mixed ERP/manufacturer exports.
- Use `references/quality-rules.md` for every run.
- Use the marketplace-specific checklist only for the marketplaces actually requested.

## Final checks

- Ensure the title, bullets, description, and attributes agree with each other.
- Ensure the selected marketplace export contains no hidden contradictions.
- Ensure the final score is justified by the blocker list.
- If exact marketplace policy compliance is critical, recommend a live rules verification pass before publishing.
