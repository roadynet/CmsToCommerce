<?php

declare(strict_types=1);

namespace App\Service\Product;

use App\Entity\Product;
use App\Entity\ProductSource;
use App\Entity\ProductVariant;
use App\Enum\ProductStatus;
use App\Enum\SourceType;
use App\Service\Import\SectionCodeFileGrouper;
use App\Service\Import\ProductTextNormalizer;
use App\Service\Import\ProductVariantParser;
use App\Service\Media\ProductMediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

final class ProductIntakeManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductTextNormalizer $textNormalizer,
        private readonly SectionCodeFileGrouper $sectionCodeFileGrouper,
        private readonly ProductVariantParser $variantParser,
        private readonly ProductMediaStorage $mediaStorage,
        private readonly SluggerInterface $slugger,
        private readonly string $defaultContentLanguage,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param array<int, UploadedFile> $uploadedFiles
     */
    public function createFromInput(array $input, array $uploadedFiles = []): Product
    {
        $rawText = trim((string) ($input['raw_text'] ?? ''));
        $analysis = $this->textNormalizer->normalize($rawText);
        $normalized = $analysis['normalized'];
        $description = $this->nullable($input['description'] ?? null) ?? $this->buildDescription($analysis);
        $name = $this->nullable($input['name'] ?? null) ?? $normalized['title_candidate'] ?? null;

        if ($name === null) {
            throw new InvalidArgumentException('Bitte Produktname angeben oder im Rohtext einen klaren Titel mitsenden.');
        }

        $product = new Product($name);
        $product
            ->setSlug((string) $this->slugger->slug($name)->lower())
            ->setBrand($this->nullable($input['brand'] ?? null) ?? $normalized['brand'] ?? null)
            ->setCategoryPath($this->nullable($input['category_path'] ?? null) ?? $normalized['category'] ?? $normalized['product_type'] ?? null)
            ->setDescription($description)
            ->setStatus(($rawText !== '' || $uploadedFiles !== []) ? ProductStatus::Imported : ProductStatus::Draft);

        if ($rawText !== '' || isset($input['source_payload'])) {
            $product->addSource($this->buildSource($input, $rawText));
        }

        $variantRows = isset($input['variants']) && is_array($input['variants'])
            ? $this->variantParser->parseStructured($input['variants'])
            : $this->variantParser->parseText($this->nullable($input['variants_text'] ?? $input['variants_raw'] ?? null));

        foreach ($variantRows as $row) {
            $variant = new ProductVariant($row['sku']);
            $variant
                ->setOptionSummary($row['options'])
                ->setEan($row['ean'])
                ->setPriceGross($row['priceGross'])
                ->setCurrency($row['currency'])
                ->setStock($row['stock'])
                ->setEnabled($row['enabled']);

            $product->addVariant($variant);
        }

        foreach (array_values($uploadedFiles) as $index => $uploadedFile) {
            $product->addAsset($this->mediaStorage->store($product, $uploadedFile, $index + 1));
        }

        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createFromApiPayload(array $payload): Product
    {
        $sourcePayload = is_array($payload['source_payload'] ?? null) ? $payload['source_payload'] : $payload;
        unset($sourcePayload['token'], $sourcePayload['api_token'], $sourcePayload['api_schluessel']);

        $variants = $this->firstDefined($payload, ['varianten', 'variants']);

        return $this->createFromInput([
            'name' => $this->firstDefined($payload, ['produkt_name', 'produktname', 'name']),
            'brand' => $this->firstDefined($payload, ['marke', 'brand']),
            'category_path' => $this->firstDefined($payload, ['kategorie_pfad', 'kategoriepfad', 'category_path', 'category', 'kategorie']),
            'description' => $this->firstDefined($payload, ['beschreibung', 'description']),
            'raw_text' => (string) ($this->firstDefined($payload, ['rohtext', 'quelltext', 'raw_text', 'source_text']) ?? ''),
            'cms_system' => (string) ($this->firstDefined($payload, ['cms_system', 'cms']) ?? 'externes_cms'),
            'external_reference' => $this->firstDefined($payload, ['externe_referenz', 'external_reference', 'produkt_id', 'product_id']),
            'language_code' => $this->firstDefined($payload, ['sprache', 'sprachcode', 'language']),
            'source_type' => SourceType::CmsImport->value,
            'variants' => is_array($variants) ? $variants : [],
            'source_payload' => $sourcePayload,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createSourceFromApiPayload(array $payload): ProductSource
    {
        $sourcePayload = is_array($payload['source_payload'] ?? null) ? $payload['source_payload'] : $payload;
        unset($sourcePayload['token'], $sourcePayload['api_token'], $sourcePayload['api_schluessel']);

        return $this->buildSource([
            'raw_text' => (string) ($this->firstDefined($payload, ['rohtext', 'quelltext', 'raw_text', 'source_text']) ?? ''),
            'cms_system' => (string) ($this->firstDefined($payload, ['cms_system', 'cms']) ?? 'externes_cms'),
            'external_reference' => $this->firstDefined($payload, ['externe_referenz', 'external_reference', 'produkt_id', 'product_id']),
            'language_code' => $this->firstDefined($payload, ['sprache', 'sprachcode', 'language']),
            'source_type' => SourceType::CmsImport->value,
            'source_payload' => $sourcePayload,
        ], (string) ($this->firstDefined($payload, ['rohtext', 'quelltext', 'raw_text', 'source_text']) ?? ''));
    }

    /**
     * @param array<int, UploadedFile> $uploadedFiles
     * @param array<string, mixed> $defaults
     *
     * @return array{products: list<Product>, warnings: list<string>}
     */
    public function createFromSectionCodeUpload(array $uploadedFiles, array $defaults = []): array
    {
        $grouped = $this->sectionCodeFileGrouper->group($uploadedFiles);
        $products = [];

        foreach ($grouped['products'] as $group) {
            $rawText = $this->readUploadedTextFile($group['text_file']);
            $sectionCode = $group['section_code'];

            $products[] = $this->createFromInput([
                'name' => $this->inferProductName($rawText, $sectionCode),
                'brand' => $this->nullable($defaults['brand'] ?? null),
                'category_path' => $this->nullable($defaults['category_path'] ?? null),
                'description' => $this->nullable($defaults['description'] ?? null),
                'raw_text' => $rawText,
                'cms_system' => $this->nullable($defaults['cms_system'] ?? null) ?? 'sections_upload',
                'external_reference' => $sectionCode,
                'language_code' => $this->nullable($defaults['language_code'] ?? null) ?? $this->defaultContentLanguage,
                'source_type' => SourceType::FileUpload->value,
                'source_payload' => [
                    'import_typ' => 'sectionscode_upload',
                    'sectionscode' => $sectionCode,
                    'textdatei' => $group['text_file']->getClientOriginalName(),
                    'asset_dateien' => array_map(
                        static fn (UploadedFile $file): string => $file->getClientOriginalName(),
                        $group['asset_files'],
                    ),
                ],
            ], $group['asset_files']);
        }

        $warnings = array_map(
            static fn (string $filename): string => sprintf('Datei "%s" konnte keinem Sectionscode-Produkt zugeordnet werden.', $filename),
            $grouped['unmatched_files'],
        );

        return [
            'products' => $products,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function buildSource(array $input, string $rawText): ProductSource
    {
        $sourceType = $this->resolveSourceType($input['source_type'] ?? null, $input['cms_system'] ?? null);
        $payload = $this->buildRawPayload($rawText, $input['source_payload'] ?? null);
        $source = new ProductSource($sourceType, $payload);
        $source
            ->setCmsSystem($this->nullable($input['cms_system'] ?? null))
            ->setExternalReference($this->nullable($input['external_reference'] ?? null))
            ->setLanguageCode($this->nullable($input['language_code'] ?? null) ?? $this->defaultContentLanguage);

        return $source;
    }

    private function resolveSourceType(mixed $sourceType, mixed $cmsSystem): SourceType
    {
        $value = $this->nullable($sourceType);

        if ($value !== null) {
            try {
                return SourceType::from($value);
            } catch (\ValueError) {
            }
        }

        return $this->nullable($cmsSystem) !== null ? SourceType::CmsImport : SourceType::ManualText;
    }

    private function buildRawPayload(string $rawText, mixed $sourcePayload): string
    {
        if (is_array($sourcePayload) && $sourcePayload !== []) {
            $structured = json_encode($sourcePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return trim($rawText."\n\n".$structured);
        }

        return $rawText;
    }

    private function buildDescription(array $analysis): ?string
    {
        $paragraphs = $analysis['raw']['paragraphs'] ?? [];
        if (is_array($paragraphs) && $paragraphs !== []) {
            return implode("\n\n", array_slice(array_values($paragraphs), 0, 2));
        }

        $bulletLines = $analysis['raw']['bullet_lines'] ?? [];
        if (is_array($bulletLines) && $bulletLines !== []) {
            return implode("\n", array_map(static fn (string $line): string => '• '.$line, array_slice(array_values($bulletLines), 0, 5)));
        }

        return null;
    }

    private function readUploadedTextFile(UploadedFile $file): string
    {
        $content = @file_get_contents($file->getPathname());
        if ($content === false) {
            throw new InvalidArgumentException(sprintf('TXT-Datei "%s" konnte nicht gelesen werden.', $file->getClientOriginalName()));
        }

        $content = trim($content);
        if ($content === '') {
            throw new InvalidArgumentException(sprintf('TXT-Datei "%s" ist leer.', $file->getClientOriginalName()));
        }

        return $content;
    }

    private function inferProductName(string $rawText, string $sectionCode): string
    {
        $analysis = $this->textNormalizer->normalize($rawText);
        $titleCandidate = $this->nullable($analysis['normalized']['title_candidate'] ?? null);

        return $titleCandidate ?? sprintf('Produkt %s', $sectionCode);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     */
    private function firstDefined(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return $payload[$key];
            }
        }

        return null;
    }
}
