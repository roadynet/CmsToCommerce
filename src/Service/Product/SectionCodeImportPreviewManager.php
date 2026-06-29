<?php

declare(strict_types=1);

namespace App\Service\Product;

use App\Service\Import\ProductTextNormalizer;
use App\Service\Import\SectionCodeFileGrouper;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SectionCodeImportPreviewManager
{
    public function __construct(
        private readonly string $importPreviewPath,
        private readonly SectionCodeFileGrouper $sectionCodeFileGrouper,
        private readonly ProductTextNormalizer $textNormalizer,
        private readonly ProductIntakeManager $productIntakeManager,
    ) {
    }

    /**
     * @param array<int, UploadedFile> $textFiles
     * @param array<int, UploadedFile> $assetFiles
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    public function prepare(array $textFiles, array $assetFiles, array $defaults = []): array
    {
        $allFiles = [...$textFiles, ...$assetFiles];
        if ($allFiles === []) {
            throw new InvalidArgumentException('Bitte mindestens eine TXT-Datei oder Bilddatei auswählen.');
        }

        $grouped = $this->sectionCodeFileGrouper->group($allFiles);
        $token = bin2hex(random_bytes(16));
        $baseDirectory = $this->directoryFor($token);
        $this->ensureDirectory($baseDirectory.'/text');
        $this->ensureDirectory($baseDirectory.'/assets');

        $manifest = [
            'preview_token' => $token,
            'defaults' => [
                'cms_system' => $this->nullable($defaults['cms_system'] ?? null) ?? 'Sections-Upload',
                'category_path' => $this->nullable($defaults['category_path'] ?? null),
                'language_code' => $this->nullable($defaults['language_code'] ?? null) ?? 'de',
            ],
            'files' => [],
            'summary' => [
                'produkt_anzahl' => count($grouped['products']),
                'txt_anzahl' => count($textFiles),
                'bild_anzahl' => count($assetFiles),
                'nicht_zugeordnet_anzahl' => count($grouped['unmatched_files']),
            ],
            'products' => [],
            'warnings' => array_map(
                static fn (string $filename): string => sprintf('Datei "%s" konnte keinem Sectionscode-Produkt zugeordnet werden.', $filename),
                $grouped['unmatched_files'],
            ),
        ];

        foreach ($textFiles as $file) {
            $manifest['files'][] = $this->storeUploadedFile($baseDirectory, $file, 'text');
        }

        foreach ($assetFiles as $file) {
            $manifest['files'][] = $this->storeUploadedFile($baseDirectory, $file, 'assets');
        }

        foreach ($grouped['products'] as $group) {
            $rawText = $this->readUploadedTextFile($group['text_file']);
            $analysis = $this->textNormalizer->normalize($rawText);
            $normalized = $analysis['normalized'];
            $productName = $this->nullable($normalized['title_candidate'] ?? null) ?? sprintf('Produkt %s', $group['section_code']);
            $notes = $analysis['notes'];

            if ($group['asset_files'] === []) {
                $notes[] = 'Keine Bilder zugeordnet.';
            }

            $manifest['products'][] = [
                'sectionscode' => $group['section_code'],
                'produktname' => $productName,
                'marke' => $this->nullable($normalized['brand'] ?? null) ?? $this->nullable($defaults['brand'] ?? null),
                'produktart' => $this->nullable($normalized['product_type'] ?? null),
                'kategorie' => $this->nullable($normalized['category'] ?? null) ?? $this->nullable($defaults['category_path'] ?? null),
                'textdatei' => $group['text_file']->getClientOriginalName(),
                'bild_anzahl' => count($group['asset_files']),
                'bilddateien' => array_map(
                    static fn (UploadedFile $file): string => $file->getClientOriginalName(),
                    $group['asset_files'],
                ),
                'fehlende_pflichtfelder' => array_map([$this, 'fieldLabel'], $analysis['missing_core_fields']),
                'hinweise' => array_values(array_unique($notes)),
            ];
        }

        file_put_contents(
            $baseDirectory.'/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $manifest;
    }

    /**
     * @return array<string, mixed>
     */
    public function load(string $token): array
    {
        $manifestPath = $this->directoryFor($token).'/manifest.json';
        if (!is_file($manifestPath)) {
            throw new InvalidArgumentException('Die Import-Vorschau wurde nicht gefunden oder ist bereits abgelaufen.');
        }

        $content = file_get_contents($manifestPath);
        if ($content === false) {
            throw new InvalidArgumentException('Die Import-Vorschau konnte nicht gelesen werden.');
        }

        $manifest = json_decode($content, true);
        if (!is_array($manifest)) {
            throw new InvalidArgumentException('Die Import-Vorschau ist beschädigt.');
        }

        return $manifest;
    }

    /**
     * @return array{products: list<\App\Entity\Product>, warnings: list<string>}
     */
    public function importPrepared(string $token): array
    {
        $manifest = $this->load($token);
        $baseDirectory = $this->directoryFor($token);
        $uploadedFiles = [];

        foreach ($manifest['files'] ?? [] as $file) {
            if (!is_array($file)) {
                continue;
            }

            $storedPath = $baseDirectory.'/'.ltrim((string) ($file['stored_path'] ?? ''), '/');
            if (!is_file($storedPath)) {
                throw new InvalidArgumentException(sprintf('Die Vorschau-Datei "%s" fehlt.', (string) ($file['original_name'] ?? 'unbekannt')));
            }

            $uploadedFiles[] = new UploadedFile(
                $storedPath,
                (string) ($file['original_name'] ?? basename($storedPath)),
                (string) ($file['mime_type'] ?? 'application/octet-stream'),
                null,
                true,
            );
        }

        $result = $this->productIntakeManager->createFromSectionCodeUpload(
            $uploadedFiles,
            is_array($manifest['defaults'] ?? null) ? $manifest['defaults'] : [],
        );

        $this->discard($token);

        return $result;
    }

    public function discard(string $token): void
    {
        $directory = $this->directoryFor($token);
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());

                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($directory);
    }

    /**
     * @return array<string, string>
     */
    private function storeUploadedFile(string $baseDirectory, UploadedFile $file, string $subDirectory): array
    {
        $targetDirectory = $baseDirectory.'/'.$subDirectory;
        $this->ensureDirectory($targetDirectory);

        $extension = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
        $storedFilename = sprintf('%s%s', bin2hex(random_bytes(12)), $extension !== '' ? '.'.$extension : '');
        $targetPath = $targetDirectory.'/'.$storedFilename;

        if (!copy($file->getPathname(), $targetPath)) {
            throw new InvalidArgumentException(sprintf('Datei "%s" konnte nicht für die Vorschau zwischengespeichert werden.', $file->getClientOriginalName()));
        }

        return [
            'stored_path' => $subDirectory.'/'.$storedFilename,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
        ];
    }

    private function directoryFor(string $token): string
    {
        return rtrim($this->importPreviewPath, '/\\').DIRECTORY_SEPARATOR.$token;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new InvalidArgumentException(sprintf('Vorschau-Verzeichnis "%s" konnte nicht erstellt werden.', $directory));
        }
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

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'title_candidate' => 'Titel',
            'brand' => 'Marke',
            'product_type' => 'Produktart',
            default => $field,
        };
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
