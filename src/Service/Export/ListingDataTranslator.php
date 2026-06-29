<?php

declare(strict_types=1);

namespace App\Service\Export;

final class ListingDataTranslator
{
    /**
     * @param array<string, scalar|array|null> $technicalAttributes
     *
     * @return array<string, scalar|array|null>
     */
    public function technicalAttributes(array $technicalAttributes): array
    {
        $translated = [];

        foreach ($technicalAttributes as $key => $value) {
            $translated[$this->translateTechnicalAttributeKey($key)] = $value;
        }

        return $translated;
    }

    /**
     * @param array<string, mixed> $sourceAudit
     *
     * @return array<string, mixed>
     */
    public function sourceAudit(array $sourceAudit): array
    {
        return [
            'beobachtete_fakten' => $sourceAudit['observed_facts'] ?? [],
            'abgeleitete_fakten' => $sourceAudit['inferred_facts'] ?? [],
            'fehlend_oder_ungeprueft' => $sourceAudit['missing_or_unverified'] ?? [],
            'konflikte' => $sourceAudit['conflicts'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $qualityReview
     *
     * @return array<string, mixed>
     */
    public function qualityReview(array $qualityReview): array
    {
        return [
            'staerken' => $qualityReview['strengths'] ?? [],
            'blocker' => $qualityReview['blockers'] ?? [],
            'massnahmen_fuer_a_niveau' => $qualityReview['fixes_to_reach_a_level'] ?? [],
            'hinweis' => $qualityReview['confidence_note'] ?? null,
        ];
    }

    /**
     * @param array{
     *     sequence?: list<array{position: int, label: string, original_name: string}>,
     *     improvement_notes?: list<string>
     * } $imageGuidance
     *
     * @return array{
     *     bildreihenfolge: list<array{position: int, rolle: string, originaldateiname: string}>,
     *     bild_hinweise: list<string>
     * }
     */
    public function imageGuidance(array $imageGuidance): array
    {
        return [
            'bildreihenfolge' => $this->imageSequence($imageGuidance['sequence'] ?? []),
            'bild_hinweise' => $imageGuidance['improvement_notes'] ?? [],
        ];
    }

    /**
     * @param list<array{position: int, label: string, original_name: string}> $sequence
     *
     * @return list<array{position: int, rolle: string, originaldateiname: string}>
     */
    public function imageSequence(array $sequence): array
    {
        $translated = [];

        foreach ($sequence as $image) {
            $translated[] = [
                'position' => $image['position'],
                'rolle' => $image['label'],
                'originaldateiname' => $image['original_name'],
            ];
        }

        return $translated;
    }

    private function translateTechnicalAttributeKey(string $key): string
    {
        return match ($key) {
            'brand' => 'marke',
            'category_path' => 'kategorie_pfad',
            'product_type' => 'produktart',
            'model' => 'modell',
            'ean' => 'ean',
            'material' => 'material',
            'color' => 'farbe',
            'size' => 'groesse',
            'dimensions' => 'abmessungen',
            'weight' => 'gewicht',
            'compatibility' => 'kompatibilitaet',
            'price_hint' => 'preis_hinweis',
            'product_status' => 'produkt_status',
            'language' => 'sprache',
            'source_count' => 'quellen_anzahl',
            'asset_count' => 'medien_anzahl',
            'variant_count' => 'varianten_anzahl',
            'variant_model' => 'varianten_modell',
            'marketplace_ruleset' => 'marktplatz_regelwerk',
            default => $key,
        };
    }
}
