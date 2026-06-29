<?php

declare(strict_types=1);

namespace App\Service\Import;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SectionCodeFileGrouper
{
    /**
     * @param array<int, UploadedFile> $files
     *
     * @return array{
     *     products: list<array{
     *         section_code: string,
     *         text_file: UploadedFile,
     *         asset_files: list<UploadedFile>
     *     }>,
     *     unmatched_files: list<string>
     * }
     */
    public function group(array $files): array
    {
        $textFilesByCode = [];
        $assetFiles = [];
        $unmatchedFiles = [];

        foreach ($files as $file) {
            $originalName = trim($file->getClientOriginalName());
            if ($originalName === '') {
                continue;
            }

            $sectionCode = $this->extractSectionCode($originalName);
            if ($sectionCode === null) {
                $unmatchedFiles[] = $originalName;

                continue;
            }

            if ($this->isTextFile($originalName)) {
                if (isset($textFilesByCode[$sectionCode])) {
                    throw new InvalidArgumentException(sprintf('Sectionscode "%s" ist mehrfach als TXT-Datei vorhanden.', $sectionCode));
                }

                $textFilesByCode[$sectionCode] = $file;

                continue;
            }

            $assetFiles[] = $file;
        }

        if ($textFilesByCode === []) {
            throw new InvalidArgumentException('Es wurde keine Sectionscode-TXT-Datei gefunden. Erwartet werden Dateien wie 1.1.txt oder 1.2.txt.');
        }

        $sectionCodes = array_keys($textFilesByCode);
        usort($sectionCodes, 'strnatcmp');

        $products = [];
        foreach ($sectionCodes as $sectionCode) {
            $products[$sectionCode] = [
                'section_code' => $sectionCode,
                'text_file' => $textFilesByCode[$sectionCode],
                'asset_files' => [],
            ];
        }

        foreach ($assetFiles as $assetFile) {
            $assetSectionCode = $this->extractSectionCode($assetFile->getClientOriginalName());
            if ($assetSectionCode === null) {
                $unmatchedFiles[] = $assetFile->getClientOriginalName();

                continue;
            }

            $matchedCode = $this->matchSectionCodeForAsset($assetSectionCode, $sectionCodes);
            if ($matchedCode === null) {
                $unmatchedFiles[] = $assetFile->getClientOriginalName();

                continue;
            }

            $products[$matchedCode]['asset_files'][] = $assetFile;
        }

        foreach ($products as $sectionCode => $product) {
            usort(
                $products[$sectionCode]['asset_files'],
                fn (UploadedFile $left, UploadedFile $right): int => $this->compareAssetFiles($sectionCode, $left, $right),
            );
        }

        return [
            'products' => array_values($products),
            'unmatched_files' => $unmatchedFiles,
        ];
    }

    private function isTextFile(string $filename): bool
    {
        return strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)) === 'txt';
    }

    private function extractSectionCode(string $filename): ?string
    {
        $basename = trim((string) pathinfo($filename, PATHINFO_FILENAME));
        if ($basename === '') {
            return null;
        }

        return preg_match('/^\d+(?:\.\d+)*$/', $basename) === 1 ? $basename : null;
    }

    /**
     * @param list<string> $sectionCodes
     */
    private function matchSectionCodeForAsset(string $assetCode, array $sectionCodes): ?string
    {
        $matches = [];

        foreach ($sectionCodes as $sectionCode) {
            if ($assetCode === $sectionCode || str_starts_with($assetCode, $sectionCode.'.')) {
                $matches[] = $sectionCode;
            }
        }

        if ($matches === []) {
            return null;
        }

        usort(
            $matches,
            static fn (string $left, string $right): int => strlen($right) <=> strlen($left),
        );

        return $matches[0];
    }

    private function compareAssetFiles(string $sectionCode, UploadedFile $left, UploadedFile $right): int
    {
        $leftBase = (string) pathinfo($left->getClientOriginalName(), PATHINFO_FILENAME);
        $rightBase = (string) pathinfo($right->getClientOriginalName(), PATHINFO_FILENAME);

        $leftWeight = $leftBase === $sectionCode ? 0 : 1;
        $rightWeight = $rightBase === $sectionCode ? 0 : 1;

        if ($leftWeight !== $rightWeight) {
            return $leftWeight <=> $rightWeight;
        }

        return strnatcmp($leftBase, $rightBase);
    }
}
