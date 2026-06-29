<?php

declare(strict_types=1);

namespace App\Service\Listing;

use App\Dto\ListingDraft;
use App\Entity\Product;
use App\Entity\ProductAsset;
use App\Enum\ChannelType;
use App\Enum\ProductStatus;
use App\Service\Import\ProductTextNormalizer;

final class ProductListingDraftBuilder
{
    public function __construct(
        private readonly ProductTextNormalizer $textNormalizer,
    ) {
    }

    public function build(Product $product, ChannelType $channel): ListingDraft
    {
        $source = $product->getSources()->first() ?: null;
        $analysis = $this->textNormalizer->normalize($source?->getRawPayload() ?? '');
        $normalized = $analysis['normalized'];
        $raw = $analysis['raw'];

        $productType = $this->firstFilled([
            $normalized['product_type'] ?? null,
            $product->getCategoryPath(),
        ]);
        $brand = $this->firstFilled([
            $product->getBrand(),
            $normalized['brand'] ?? null,
        ]);
        $category = $this->firstFilled([
            $product->getCategoryPath(),
            $normalized['category'] ?? null,
        ]);
        $language = $source?->getLanguageCode() ?? $normalized['language'] ?? 'de';
        $variantModel = $this->variantModel($product);

        $observedFacts = [];
        $inferredFacts = [];
        $missing = [];
        $conflicts = [];

        if ($productType !== null) {
            $observedFacts[] = sprintf('Produktart: %s', $productType);
        } else {
            $missing[] = 'Produktart ist unklar.';
        }

        if ($brand !== null) {
            $observedFacts[] = sprintf('Marke: %s', $brand);
        } else {
            $missing[] = 'Marke fehlt.';
        }

        if ($category !== null) {
            $observedFacts[] = sprintf('Kategorie: %s', $category);
        } else {
            $missing[] = 'Kategorie ist noch offen.';
        }

        foreach (['model', 'ean', 'material', 'color', 'size', 'dimensions', 'weight', 'compatibility', 'price'] as $field) {
            if (($normalized[$field] ?? null) !== null) {
                $observedFacts[] = sprintf('%s: %s', $this->humanize($field), (string) $normalized[$field]);
            }
        }

        if ($raw['bullet_lines'] !== []) {
            $observedFacts[] = sprintf('%d strukturierte Bullet-Zeilen aus der Quelle erkannt.', count($raw['bullet_lines']));
        }

        if ($product->getAssets()->count() > 0) {
            $observedFacts[] = sprintf('%d Medium/Medien hochgeladen.', $product->getAssets()->count());
        } else {
            $missing[] = 'Produktbilder fehlen.';
        }

        if ($product->getVariants()->count() > 0) {
            $observedFacts[] = sprintf('%d Variante(n) erfasst.', $product->getVariants()->count());
            if ($variantModel !== null) {
                $observedFacts[] = sprintf('Variantenachse: %s', $variantModel);
            } else {
                $conflicts[] = 'Varianten vorhanden, aber Achsen sind nicht sauber erkennbar.';
            }
        }

        foreach ($analysis['notes'] as $note) {
            $inferredFacts[] = $note;
        }

        if ($product->getDescription() !== null) {
            $observedFacts[] = 'Manuelle oder importierte Beschreibung vorhanden.';
        } else {
            $missing[] = 'Beschreibung fehlt.';
        }

        if (($normalized['brand'] ?? null) !== null && $product->getBrand() !== null && mb_strtolower((string) $normalized['brand']) !== mb_strtolower($product->getBrand())) {
            $conflicts[] = sprintf('Marke im Rohtext ("%s") weicht vom Produktstamm ("%s") ab.', $normalized['brand'], $product->getBrand());
        }

        if (($normalized['title_candidate'] ?? null) !== null && !$this->containsIgnoreCase($product->getName(), (string) $normalized['title_candidate']) && !$this->containsIgnoreCase((string) $normalized['title_candidate'], $product->getName())) {
            $inferredFacts[] = sprintf('Produkttitel im Stamm wurde manuell von "%s" auf "%s" verdichtet.', $normalized['title_candidate'], $product->getName());
        }

        $title = $this->buildTitle($product, $channel, $productType, $brand, $normalized);
        $bulletPoints = $this->buildBulletPoints($product, $productType, $normalized, $raw);
        $description = $this->buildDescription($product, $productType, $brand, $normalized, $raw);
        $searchTerms = $this->buildSearchTerms($product, $normalized, $channel);
        $imageGuidance = $this->buildImageGuidance($product);

        if ($product->getSources()->count() === 0) {
            $missing[] = 'Keine CMS- oder Rohtextquelle hinterlegt.';
        }

        if ($product->getStatus() === ProductStatus::Draft) {
            $inferredFacts[] = 'Produkt ist noch im Entwurfsstatus und sollte vor einer Live-Veröffentlichung geprüft werden.';
        }

        $technicalAttributes = array_filter([
            'brand' => $brand,
            'category_path' => $category,
            'product_type' => $productType,
            'model' => $normalized['model'] ?? null,
            'ean' => $normalized['ean'] ?? null,
            'material' => $normalized['material'] ?? null,
            'color' => $normalized['color'] ?? null,
            'size' => $normalized['size'] ?? null,
            'dimensions' => $normalized['dimensions'] ?? null,
            'weight' => $normalized['weight'] ?? null,
            'compatibility' => $normalized['compatibility'] ?? null,
            'price_hint' => $normalized['price'] ?? null,
            'product_status' => $product->getStatus()->value,
            'language' => $language,
            'source_count' => $product->getSources()->count(),
            'asset_count' => $product->getAssets()->count(),
            'variant_count' => $product->getVariants()->count(),
            'variant_model' => $variantModel,
            'marketplace_ruleset' => $channel->value,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $scoreParts = $this->score($product, $title, $bulletPoints, $searchTerms, $missing, $conflicts, $variantModel);
        $qualityScore = array_sum($scoreParts);
        $hardBlockers = $this->hardBlockers($productType, $brand, $missing, $conflicts);
        $qualityGrade = $this->grade($qualityScore, $hardBlockers !== []);

        $strengths = [];
        if ($brand !== null) {
            $strengths[] = 'Marke ist im Listing verankert.';
        }

        if ($product->getAssets()->count() >= 3) {
            $strengths[] = 'Es liegen mehrere Bilder für die Reihenfolgeplanung vor.';
        }

        if (count($bulletPoints) >= 3) {
            $strengths[] = 'Die wichtigsten Käuferfragen werden bereits in Bullet-Form beantwortet.';
        }

        if ($searchTerms !== []) {
            $strengths[] = 'Suchbegriffe wurden aus beobachtbaren Produktmerkmalen abgeleitet.';
        }

        $blockers = array_values(array_unique([...$hardBlockers, ...$conflicts]));
        $fixes = $this->fixes($missing, $conflicts, $product);

        return new ListingDraft(
            $channel,
            $title,
            $bulletPoints,
            $description,
            $technicalAttributes,
            $searchTerms,
            max(0, min(100, $qualityScore)),
            $qualityGrade,
            [
                'observed_facts' => array_values(array_unique($observedFacts)),
                'inferred_facts' => array_values(array_unique($inferredFacts)),
                'missing_or_unverified' => array_values(array_unique($missing)),
                'conflicts' => array_values(array_unique($conflicts)),
            ],
            $imageGuidance,
            [
                'strengths' => $strengths,
                'blockers' => $blockers,
                'fixes_to_reach_a_level' => $fixes,
                'confidence_note' => $hardBlockers === []
                    ? 'Automatisch auf Basis der vorhandenen Stamm- und Quelldaten erstellt.'
                    : 'Automatisch erstellt, aber A-Niveau ist noch durch fehlende oder widersprüchliche Pflichtdaten blockiert.',
            ],
        );
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function buildTitle(Product $product, ChannelType $channel, ?string $productType, ?string $brand, array $normalized): string
    {
        $parts = array_filter([
            $brand,
            $product->getName(),
            $normalized['color'] ?? null,
            $normalized['size'] ?? null,
            $normalized['material'] ?? null,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        $title = implode(' ', $parts);
        if ($title === '') {
            $title = $product->getName();
        }

        if ($channel === ChannelType::Shopware && $productType !== null && !str_contains(mb_strtolower($title), mb_strtolower($productType))) {
            $title = trim($title.' '.$productType);
        }

        return trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    }

    /**
     * @param array<string, mixed> $normalized
     * @param array<string, mixed> $raw
     *
     * @return list<string>
     */
    private function buildBulletPoints(Product $product, ?string $productType, array $normalized, array $raw): array
    {
        $bullets = [];
        $headlineBits = [];

        if ($productType !== null) {
            $headlineBits[] = $productType;
        }

        if (($normalized['material'] ?? null) !== null) {
            $headlineBits[] = 'aus '.(string) $normalized['material'];
        }

        if (($normalized['color'] ?? null) !== null) {
            $headlineBits[] = 'in '.(string) $normalized['color'];
        }

        if ($headlineBits !== []) {
            $bullets[] = $this->sentence(implode(' ', $headlineBits));
        }

        $dimensionBits = array_filter([
            ($normalized['size'] ?? null) !== null && !$this->containsIgnoreCase($product->getName(), (string) $normalized['size'])
                ? 'Größe: '.(string) $normalized['size']
                : null,
            ($normalized['dimensions'] ?? null) !== null ? 'Abmessungen: '.(string) $normalized['dimensions'] : null,
            ($normalized['weight'] ?? null) !== null ? 'Gewicht: '.(string) $normalized['weight'] : null,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        if ($dimensionBits !== []) {
            $bullets[] = $this->sentence(implode(' · ', $dimensionBits));
        }

        if (($normalized['compatibility'] ?? null) !== null) {
            $bullets[] = $this->sentence(sprintf('Geeignet für %s', $normalized['compatibility']));
        }

        foreach (array_slice($raw['bullet_lines'] ?? [], 0, 5) as $line) {
            $candidate = $this->sentence($line);
            if ($candidate !== null) {
                $bullets[] = $candidate;
            }
        }

        $bullets = array_values(array_unique(array_filter($bullets, static fn (string $value): bool => trim($value) !== '')));

        return array_slice($bullets, 0, 5);
    }

    /**
     * @param array<string, mixed> $normalized
     * @param array<string, mixed> $raw
     */
    private function buildDescription(Product $product, ?string $productType, ?string $brand, array $normalized, array $raw): string
    {
        $paragraphs = [];

        $leadParagraph = $this->buildLeadParagraph($product, $productType, $brand, $normalized);
        if ($leadParagraph !== null) {
            $paragraphs[] = $leadParagraph;
        }

        foreach ($this->naturalDescriptionParagraphs($product, $raw) as $paragraph) {
            $paragraphs[] = $paragraph;
            if (count($paragraphs) >= 2) {
                break;
            }
        }

        $detailsParagraph = $this->buildDetailsParagraph($normalized);
        if ($detailsParagraph !== null) {
            $paragraphs[] = $detailsParagraph;
        }

        return trim(implode("\n\n", array_values(array_unique(array_filter($paragraphs, static fn (string $value): bool => trim($value) !== '')))));
    }

    /**
     * @param array<string, mixed> $normalized
     *
     * @return list<string>
     */
    private function buildSearchTerms(Product $product, array $normalized, ChannelType $channel): array
    {
        $terms = [
            $product->getBrand(),
            $product->getName(),
            $product->getCategoryPath(),
            $normalized['product_type'] ?? null,
            $normalized['material'] ?? null,
            $normalized['color'] ?? null,
            $normalized['size'] ?? null,
            $normalized['compatibility'] ?? null,
            $channel->label(),
        ];

        $deduplicated = [];
        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ($term === '') {
                continue;
            }

            $key = mb_strtolower($term);
            $deduplicated[$key] = $term;
        }

        return array_values($deduplicated);
    }

    /**
     * @return array{
     *     sequence: list<array{position: int, label: string, original_name: string}>,
     *     improvement_notes: list<string>
     * }
     */
    private function buildImageGuidance(Product $product): array
    {
        $sequence = [];
        $labels = ['Hauptbild', 'Alternativansicht', 'Detailaufnahme', 'Maßstab / Größenbezug', 'Verpackung / Lieferumfang', 'Anwendungskontext'];

        /** @var ProductAsset $asset */
        foreach ($product->getAssets() as $index => $asset) {
            $sequence[] = [
                'position' => $asset->getPosition() > 0 ? $asset->getPosition() : $index + 1,
                'label' => $labels[$index] ?? 'Zusatzbild',
                'original_name' => $asset->getOriginalName(),
            ];
        }

        $improvementNotes = [];
        if ($product->getAssets()->count() === 0) {
            $improvementNotes[] = 'Mindestens ein klares Hauptbild des verkauften Artikels fehlt.';
        } elseif ($product->getAssets()->count() < 3) {
            $improvementNotes[] = 'Für hochwertige Produktseiten fehlen noch Detail- und Kontextbilder.';
        }

        if ($product->getVariants()->count() > 1) {
            $improvementNotes[] = 'Für jede relevante Variante sollte mindestens ein eindeutig zuordenbares Bild vorliegen.';
        }

        if ($product->getAssets()->count() > 0 && $product->getAssets()->count() < 5) {
            $improvementNotes[] = 'Prüfen, ob Verpackung, Lieferumfang oder Maßstab noch visuell belegt werden müssen.';
        }

        return [
            'sequence' => $sequence,
            'improvement_notes' => $improvementNotes,
        ];
    }

    /**
     * @param list<string> $missing
     * @param list<string> $conflicts
     *
     * @return array<int, int>
     */
    private function score(Product $product, string $title, array $bulletPoints, array $searchTerms, array $missing, array $conflicts, ?string $variantModel): array
    {
        $completeness = 10;
        if ($product->getBrand() !== null) {
            $completeness += 4;
        }
        if ($product->getDescription() !== null) {
            $completeness += 4;
        }
        if ($product->getAssets()->count() > 0) {
            $completeness += 4;
        }
        if ($product->getSources()->count() > 0) {
            $completeness += 3;
        }
        $completeness -= min(10, count($missing) * 2);

        $consistency = max(0, 20 - (count($conflicts) * 5));
        $buyerClarity = min(15, 5 + min(6, count($bulletPoints) * 2) + (mb_strlen($title) >= 12 ? 2 : 0) + ($product->getDescription() !== null ? 2 : 0));
        $seo = min(15, 4 + min(7, count($searchTerms)) + (mb_strlen($title) <= 180 ? 4 : 0));
        $marketplaceFit = min(15, 5 + (count($bulletPoints) >= 3 ? 4 : 0) + ($product->getAssets()->count() > 0 ? 3 : 0) + ($product->getSources()->count() > 0 ? 3 : 0));
        $variantIntegrity = $product->getVariants()->count() === 0 ? 8 : ($variantModel !== null ? 10 : 3);

        return [
            max(0, min(25, $completeness)),
            $consistency,
            $buyerClarity,
            $seo,
            $marketplaceFit,
            $variantIntegrity,
        ];
    }

    /**
     * @param list<string> $missing
     * @param list<string> $conflicts
     *
     * @return list<string>
     */
    private function hardBlockers(?string $productType, ?string $brand, array $missing, array $conflicts): array
    {
        $blockers = [];

        if ($productType === null) {
            $blockers[] = 'Produktart ist unklar.';
        }

        if ($brand === null) {
            $blockers[] = 'Marke fehlt oder ist nicht verifiziert.';
        }

        foreach ($missing as $item) {
            if (in_array($item, ['Produktbilder fehlen.', 'Beschreibung fehlt.'], true)) {
                $blockers[] = $item;
            }
        }

        return array_values(array_unique([...$blockers, ...$conflicts]));
    }

    /**
     * @param list<string> $missing
     * @param list<string> $conflicts
     *
     * @return list<string>
     */
    private function fixes(array $missing, array $conflicts, Product $product): array
    {
        $fixes = [];

        foreach ($missing as $item) {
            $fixes[] = match ($item) {
                'Produktart ist unklar.' => 'Produkttyp oder Zielkategorie eindeutig im Import oder im Produktstamm ergänzen.',
                'Marke fehlt.' => 'Marke aus Herstellerdaten oder Verpackung verifizieren und im Produktstamm hinterlegen.',
                'Kategorie ist noch offen.' => 'Shopware-/Amazon-Zielkategorie vor dem Publishing festlegen.',
                'Produktbilder fehlen.' => 'Mindestens Haupt-, Detail- und Kontextbild hochladen.',
                'Beschreibung fehlt.' => 'Rohtext oder Herstellerbeschreibung ergänzen und Entwurf neu generieren.',
                'Keine CMS- oder Rohtextquelle hinterlegt.' => 'Quelle aus CMS oder manuell eingeben, damit Audit und Nachvollziehbarkeit bestehen.',
                default => $item,
            };
        }

        foreach ($conflicts as $conflict) {
            $fixes[] = 'Widersprüchliche Stamm- und Quelldaten bereinigen, bevor das Listing live geschaltet wird.';
        }

        if ($product->getVariants()->count() > 1) {
            $fixes[] = 'Variantenachsen und variantenspezifische Bilder überprüfen.';
        }

        return array_values(array_unique($fixes));
    }

    private function grade(int $score, bool $hasHardBlockers): string
    {
        if ($score >= 90 && !$hasHardBlockers) {
            return 'A';
        }

        if ($score >= 80) {
            return 'B';
        }

        if ($score >= 65) {
            return 'C';
        }

        return 'D';
    }

    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function humanize(string $field): string
    {
        return match ($field) {
            'model' => 'Modell',
            'ean' => 'EAN',
            'material' => 'Material',
            'color' => 'Farbe',
            'size' => 'Größe',
            'dimensions' => 'Abmessungen',
            'weight' => 'Gewicht',
            'compatibility' => 'Kompatibilität',
            'price' => 'Preis',
            default => ucfirst(str_replace('_', ' ', $field)),
        };
    }

    private function sentence(string $line): ?string
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        return rtrim($line, ". \t\n\r\0\x0B").'.';
    }

    private function variantModel(Product $product): ?string
    {
        $keys = [];
        foreach ($product->getVariants() as $variant) {
            foreach (array_keys($variant->getOptionSummary()) as $optionKey) {
                $keys[$optionKey] = true;
            }
        }

        if ($keys === []) {
            return null;
        }

        return implode(', ', array_keys($keys));
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function buildLeadParagraph(Product $product, ?string $productType, ?string $brand, array $normalized): ?string
    {
        $subject = $product->getName();
        if ($brand !== null && !$this->containsIgnoreCase($subject, $brand)) {
            $subject = $brand.' '.$subject;
        }

        $featureBits = array_filter([
            ($normalized['material'] ?? null) !== null ? 'aus '.(string) $normalized['material'] : null,
            ($normalized['color'] ?? null) !== null ? 'in '.(string) $normalized['color'] : null,
            ($normalized['size'] ?? null) !== null && !$this->containsIgnoreCase($subject, (string) $normalized['size'])
                ? 'in der Größe '.(string) $normalized['size']
                : null,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        $sentences = [];
        $lead = trim($subject);

        if ($lead === '' && $productType !== null) {
            $lead = $productType;
        }

        if ($lead !== '') {
            if ($featureBits !== []) {
                $lead .= ' '.implode(' ', $featureBits);
            }

            $sentences[] = $this->sentence($lead);
        }

        if (($normalized['compatibility'] ?? null) !== null) {
            $sentences[] = $this->sentence(sprintf('Geeignet für %s', $normalized['compatibility']));
        }

        $sentences = array_values(array_filter($sentences));

        return $sentences !== [] ? implode(' ', $sentences) : null;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return list<string>
     */
    private function naturalDescriptionParagraphs(Product $product, array $raw): array
    {
        $candidates = [];

        if ($product->getDescription() !== null) {
            $candidates = array_merge($candidates, preg_split('/\R{2,}/u', trim($product->getDescription())) ?: []);
        }

        if (($raw['paragraphs'] ?? []) !== []) {
            $candidates = array_merge($candidates, array_slice($raw['paragraphs'], 0, 3));
        }

        if (($raw['bullet_lines'] ?? []) !== []) {
            foreach (array_slice($raw['bullet_lines'], 0, 2) as $line) {
                $candidates[] = $line;
            }
        }

        $paragraphs = [];
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '' || $this->looksLikeStructuredMetadata($candidate)) {
                continue;
            }

            $paragraph = rtrim($candidate);
            if ($paragraph !== '') {
                $paragraphs[] = $paragraph;
            }
        }

        return array_values(array_unique($paragraphs));
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function buildDetailsParagraph(array $normalized): ?string
    {
        $details = [];

        if (($normalized['dimensions'] ?? null) !== null) {
            $details[] = $this->sentence('Abmessungen: '.(string) $normalized['dimensions']);
        }

        if (($normalized['weight'] ?? null) !== null) {
            $details[] = $this->sentence('Gewicht: '.(string) $normalized['weight']);
        }

        return $details !== [] ? implode(' ', $details) : null;
    }

    private function looksLikeStructuredMetadata(string $text): bool
    {
        $colonCount = substr_count($text, ':');
        $labelCount = preg_match_all('/\b(Titel|Marke|Produktart|Kategorie|EAN|Material|Farbe|Größe|Groesse|Abmessungen|Gewicht|Kompatibilität|Kompatibilitaet|Preis)\s*:/iu', $text);

        return $colonCount >= 4 || $labelCount >= 3;
    }

    private function containsIgnoreCase(string $haystack, string $needle): bool
    {
        return str_contains(mb_strtolower($haystack), mb_strtolower($needle));
    }
}
