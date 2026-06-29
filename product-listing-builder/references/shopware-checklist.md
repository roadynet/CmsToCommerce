# Shopware checklist

Use this reference when the target marketplace includes Shopware.

## Core principle

Prepare content so it is readable for buyers and clean for import mapping. Keep structured data outside the prose wherever possible.

## Required-content mindset

- Preserve identifiers such as SKU, article number, EAN/GTIN, manufacturer, and model when available.
- Keep category-relevant properties in structured attributes, not only in the description.
- Separate variant options from the base product content.

## Name and description checks

- Write a clear product name that reflects the actual buyable item.
- Keep the long description useful, readable, and easy to convert into plain text or simple HTML.
- Remove filler and duplicate claims already covered by structured properties.

## Attribute checks

- Map size, color, material, dimensions, compatibility, pack count, and technical values into dedicated fields when possible.
- Surface missing import-critical values instead of burying them in notes.
- Keep units consistent.

## SEO and discoverability checks

- Add concise keywords or meta-oriented fields only if the workflow needs them.
- Keep search intent broad enough to help discovery, but grounded in the actual product.

## Variant checks

- Define the variant model clearly: base product, option groups, option values, and child-specific differences.
- Do not mix accessories, bundles, or different product families into one variant set.

## Media checks

- Order images intentionally: hero, alternate view, details, scale, included items, packaging, and usage context if available.
- Flag when the current image set is too weak for a premium listing.

## Escalate when needed

- If the import depends on an exact Shopware schema, category property set, or plugin-specific field mapping, confirm those current target fields before final export.
