#!/usr/bin/env python3

from __future__ import annotations

import argparse
import json
import re
import sys
import unicodedata
from pathlib import Path


FIELD_SYNONYMS = {
    "title": {"titel", "produktname", "name", "article name", "product name"},
    "brand": {"marke", "brand", "hersteller", "manufacturer"},
    "model": {"modell", "model", "modellnummer", "model number"},
    "sku": {"sku", "artikelnummer", "artnr", "art-nr", "art nr", "item number"},
    "mpn": {"mpn", "herstellernummer", "manufacturer part number"},
    "ean": {"ean", "gtin", "barcode"},
    "category": {"kategorie", "category"},
    "product_type": {"produktart", "produkttyp", "product type"},
    "color": {"farbe", "color"},
    "material": {"material"},
    "size": {"groesse", "größe", "size"},
    "dimensions": {"abmessungen", "masse", "maße", "dimensions"},
    "weight": {"gewicht", "weight"},
    "compatibility": {"kompatibilitaet", "kompatibilität", "compatible with", "compatibility"},
    "price": {"preis", "price"},
    "language": {"sprache", "language"},
    "marketplace": {"marktplatz", "marketplace"},
}

EAN_RE = re.compile(r"\b\d{8,14}\b")
DIMENSION_RE = re.compile(
    r"\b\d+(?:[.,]\d+)?\s*(?:x|×)\s*\d+(?:[.,]\d+)?(?:\s*(?:x|×)\s*\d+(?:[.,]\d+)?)?\s*(?:mm|cm|m)\b",
    re.IGNORECASE,
)
WEIGHT_RE = re.compile(r"\b\d+(?:[.,]\d+)?\s*(?:mg|g|kg|ml|l)\b", re.IGNORECASE)
PACK_RE = re.compile(r"\b\d+\s*(?:stk|stueck|stück|pcs|pieces|pack)\b", re.IGNORECASE)


def normalize_key(value: str) -> str:
    value = value.strip().lower()
    value = unicodedata.normalize("NFKD", value)
    value = "".join(ch for ch in value if not unicodedata.combining(ch))
    value = re.sub(r"[^a-z0-9]+", " ", value).strip()
    return value


def canonical_field(key: str) -> str | None:
    norm = normalize_key(key)
    for canonical, aliases in FIELD_SYNONYMS.items():
        if norm == canonical or norm in aliases:
            return canonical
    return None


def unique(values: list[str]) -> list[str]:
    seen: set[str] = set()
    result: list[str] = []
    for value in values:
        compact = " ".join(value.split())
        if compact and compact not in seen:
            seen.add(compact)
            result.append(compact)
    return result


def parse_text(raw_text: str) -> dict:
    lines = [line.strip() for line in raw_text.replace("\r\n", "\n").split("\n")]
    non_empty_lines = [line for line in lines if line]

    key_values: dict[str, str] = {}
    unmatched_lines: list[str] = []
    bullet_lines: list[str] = []

    for line in non_empty_lines:
        if re.match(r"^[-*•]\s+", line):
            bullet_lines.append(re.sub(r"^[-*•]\s+", "", line).strip())
            continue

        match = re.match(r"^([^:=]{2,80})\s*[:=]\s*(.+)$", line)
        if match:
            raw_key, value = match.group(1).strip(), match.group(2).strip()
            field = canonical_field(raw_key)
            if field:
                key_values[field] = value
            else:
                key_values[normalize_key(raw_key)] = value
            continue

        unmatched_lines.append(line)

    title_candidate = key_values.get("title")
    if not title_candidate:
        for line in unmatched_lines:
            if len(line) <= 140:
                title_candidate = line
                break

    paragraphs = []
    current: list[str] = []
    for line in non_empty_lines:
        if line:
            current.append(line)
        elif current:
            paragraphs.append(" ".join(current))
            current = []
    if current:
        paragraphs.append(" ".join(current))

    detected = {
        "ean_candidates": unique(EAN_RE.findall(raw_text)),
        "dimension_candidates": unique(DIMENSION_RE.findall(raw_text)),
        "weight_candidates": unique(WEIGHT_RE.findall(raw_text)),
        "pack_candidates": unique(PACK_RE.findall(raw_text)),
    }

    if "ean" not in key_values and detected["ean_candidates"]:
        key_values["ean"] = detected["ean_candidates"][0]
    if "dimensions" not in key_values and detected["dimension_candidates"]:
        key_values["dimensions"] = detected["dimension_candidates"][0]
    if "weight" not in key_values and detected["weight_candidates"]:
        key_values["weight"] = detected["weight_candidates"][0]

    normalized = {
        "title_candidate": title_candidate,
        "brand": key_values.get("brand"),
        "model": key_values.get("model"),
        "sku": key_values.get("sku"),
        "mpn": key_values.get("mpn"),
        "ean": key_values.get("ean"),
        "category": key_values.get("category"),
        "product_type": key_values.get("product_type"),
        "color": key_values.get("color"),
        "material": key_values.get("material"),
        "size": key_values.get("size"),
        "dimensions": key_values.get("dimensions"),
        "weight": key_values.get("weight"),
        "compatibility": key_values.get("compatibility"),
        "price": key_values.get("price"),
        "language": key_values.get("language"),
        "marketplace": key_values.get("marketplace"),
    }

    missing_core_fields = [
        field
        for field in ("title_candidate", "brand", "product_type")
        if not normalized.get(field)
    ]

    notes = []
    if len(non_empty_lines) <= 2:
        notes.append("Very little source text detected.")
    if not bullet_lines:
        notes.append("No bullet-style lines detected; rely more on paragraph extraction.")
    if unmatched_lines and len(unmatched_lines) == len(non_empty_lines):
        notes.append("Source appears mostly unstructured; review inferred fields manually.")

    return {
        "normalized": normalized,
        "raw": {
            "line_count": len(non_empty_lines),
            "key_values": key_values,
            "bullet_lines": bullet_lines,
            "unmatched_lines": unmatched_lines,
            "paragraphs": paragraphs,
        },
        "detected_values": detected,
        "missing_core_fields": missing_core_fields,
        "notes": notes,
    }


def read_input(path: str | None, inline_text: str | None) -> str:
    if inline_text:
        return inline_text
    if path:
        return Path(path).read_text(encoding="utf-8")
    return sys.stdin.read()


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Normalize semi-structured product TXT into JSON."
    )
    parser.add_argument("input", nargs="?", help="Path to a TXT or text-like source file")
    parser.add_argument("--text", help="Inline source text")
    parser.add_argument("--output", help="Optional output JSON path")
    parser.add_argument("--indent", type=int, default=2, help="JSON indentation level")
    args = parser.parse_args()

    raw_text = read_input(args.input, args.text)
    result = parse_text(raw_text)
    rendered = json.dumps(result, ensure_ascii=False, indent=args.indent)

    if args.output:
        Path(args.output).write_text(rendered + "\n", encoding="utf-8")
    else:
        print(rendered)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
