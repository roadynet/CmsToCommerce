<?php

declare(strict_types=1);

namespace App\Service\Amazon;

use App\Dto\ListingDraft;
use App\Entity\Product;

final class AmazonProductTypeMapper
{
    /**
     * @var list<array{
     *     id: string,
     *     match_terms: list<string>,
     *     search_keywords: list<string>,
     *     confidence: string,
     *     reason: string
     * }>
     */
    private const RULES = [
        [
            'id' => 'drinkware_bottle',
            'match_terms' => ['trinkflasche', 'thermo bottle', 'insulated bottle', 'water bottle', 'drink bottle'],
            'search_keywords' => ['water bottle', 'insulated bottle', 'drink bottle'],
            'confidence' => 'hoch',
            'reason' => 'Produktart, Titel und Kategorie deuten klar auf Flaschen/Drinkware hin.',
        ],
        [
            'id' => 'kitchen_cutting_board',
            'match_terms' => ['schneidebrett', 'cutting board', 'chopping board', 'servierbrett'],
            'search_keywords' => ['cutting board', 'chopping board', 'serving board'],
            'confidence' => 'hoch',
            'reason' => 'Die Merkmale passen zu Küchenbrettern mit Fokus auf Schneiden und Servieren.',
        ],
        [
            'id' => 'lighting_desk_lamp',
            'match_terms' => ['schreibtischlampe', 'tischleuchte', 'desk lamp', 'table lamp', 'task light'],
            'search_keywords' => ['desk lamp', 'table lamp', 'task light'],
            'confidence' => 'hoch',
            'reason' => 'Titel und Produktart weisen auf Beleuchtung für Schreibtisch oder Homeoffice hin.',
        ],
    ];

    /**
     * @return array{
     *     ctc_produktart: ?string,
     *     regel: string,
     *     strategie: string,
     *     suchbegriffe: list<string>,
     *     artikelname: string,
     *     locale: string,
     *     vertrauen: string,
     *     begruendung: string,
     *     muss_via_amazon_bestaetigt_werden: bool,
     *     naechste_schritte: list<string>
     * }
     */
    public function map(Product $product, ListingDraft $draft): array
    {
        $ctcProductType = $this->stringOrNull($draft->technicalAttributes['product_type'] ?? null);
        $categoryPath = $this->stringOrNull($draft->technicalAttributes['category_path'] ?? null);
        $language = $this->stringOrNull($draft->technicalAttributes['language'] ?? null) ?? 'de';

        $searchSpace = mb_strtolower(implode(' ', array_filter([
            $ctcProductType,
            $categoryPath,
            $product->getName(),
            $draft->title,
            $draft->description,
        ])));

        $matchedRule = null;
        foreach (self::RULES as $rule) {
            foreach ($rule['match_terms'] as $term) {
                if (str_contains($searchSpace, mb_strtolower($term))) {
                    $matchedRule = $rule;
                    break 2;
                }
            }
        }

        $artikelname = $this->buildArticleName($product, $draft);

        if ($matchedRule === null) {
            $fallbackKeywords = array_values(array_unique(array_filter([
                $ctcProductType,
                $product->getName(),
            ], static fn (?string $value): bool => $value !== null && trim($value) !== '')));

            return [
                'ctc_produktart' => $ctcProductType,
                'regel' => 'fallback_freisuche',
                'strategie' => 'item_name',
                'suchbegriffe' => $fallbackKeywords,
                'artikelname' => $artikelname,
                'locale' => $this->localeForLanguage($language),
                'vertrauen' => 'mittel',
                'begruendung' => 'Keine feste Zuordnungsregel getroffen. CTC nutzt deshalb den Artikelnamen für die Amazon-Produkttyp-Suche.',
                'muss_via_amazon_bestaetigt_werden' => true,
                'naechste_schritte' => $this->defaultNextSteps(),
            ];
        }

        return [
            'ctc_produktart' => $ctcProductType,
            'regel' => $matchedRule['id'],
            'strategie' => 'keywords',
            'suchbegriffe' => $matchedRule['search_keywords'],
            'artikelname' => $artikelname,
            'locale' => $this->localeForLanguage($language),
            'vertrauen' => $matchedRule['confidence'],
            'begruendung' => $matchedRule['reason'],
            'muss_via_amazon_bestaetigt_werden' => true,
            'naechste_schritte' => $this->defaultNextSteps(),
        ];
    }

    /**
     * @return list<string>
     */
    private function defaultNextSteps(): array
    {
        return [
            'searchDefinitionsProductTypes aufrufen und echte Amazon-Kandidaten laden',
            'besten Amazon Product Type mit getDefinitionsProductType validieren',
            'Pflichtattribute aus dem Definitionsschema auf das CTC-Produkt mappen',
            'erst danach das erste putListingsItem-Payload erzeugen',
        ];
    }

    private function buildArticleName(Product $product, ListingDraft $draft): string
    {
        $parts = [];
        $parts[] = trim($draft->title) !== '' ? trim($draft->title) : $product->getName();

        if ($draft->bulletPoints !== []) {
            $parts[] = trim($draft->bulletPoints[0]);
        }

        if (trim($draft->description) !== '') {
            $parts[] = $this->shorten($draft->description, 180);
        }

        return trim(implode(' - ', array_filter($parts, static fn (string $value): bool => $value !== '')));
    }

    private function localeForLanguage(string $language): string
    {
        return match (mb_strtolower(trim($language))) {
            'de', 'de-de' => 'de_DE',
            'en', 'en-gb' => 'en_GB',
            'en-us' => 'en_US',
            'fr', 'fr-fr' => 'fr_FR',
            'it', 'it-it' => 'it_IT',
            'es', 'es-es' => 'es_ES',
            'nl', 'nl-nl' => 'nl_NL',
            default => 'DEFAULT',
        };
    }

    private function shorten(string $text, int $length): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $length - 1)).'…';
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
