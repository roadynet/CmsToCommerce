<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\Product;
use App\Entity\ProductAsset;
use App\Enum\AssetType;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ProductMediaStorage
{
    public function __construct(
        private readonly string $mediaStoragePath,
        private readonly SluggerInterface $slugger,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    public function store(Product $product, UploadedFile $file, int $position): ProductAsset
    {
        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getMimeType() ?: 'application/octet-stream';
        $tempPath = $file->getPathname();

        $asset = $this->storeLocalPath(
            $product,
            $tempPath,
            $originalName,
            $mimeType,
            $position,
            static function (string $sourcePath, string $targetDirectory, string $filename, UploadedFile $uploadedFile): void {
                $uploadedFile->move($targetDirectory, $filename);
            },
            $file,
        );
        $asset->setAltText($product->getName());

        return $asset;
    }

    public function storeRemoteUrl(Product $product, string $url, int $position, ?string $originalName = null, ?string $altText = null): ProductAsset
    {
        $url = trim($url);
        if ($url === '') {
            throw new RuntimeException('Leere Medien-URL kann nicht importiert werden.');
        }

        $response = ($this->httpClient ?? HttpClient::create())->request('GET', $url, [
            'headers' => [
                'Accept' => '*/*',
            ],
        ]);
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            throw new RuntimeException(sprintf('Medienabruf von "%s" ist mit HTTP %d fehlgeschlagen.', $url, $statusCode));
        }

        $content = $response->getContent(false);
        if ($content === '') {
            throw new RuntimeException(sprintf('Medienabruf von "%s" hat keinen Inhalt geliefert.', $url));
        }

        $headers = $response->getHeaders(false);
        $mimeType = strtolower(trim((string) (($headers['content-type'][0] ?? 'application/octet-stream'))));
        if (str_contains($mimeType, ';')) {
            $mimeType = trim((string) strtok($mimeType, ';'));
        }

        $originalName = $originalName !== null && trim($originalName) !== ''
            ? trim($originalName)
            : $this->filenameFromUrl($url, $mimeType);

        $tempPath = tempnam(sys_get_temp_dir(), 'ctc-remote-media-');
        if ($tempPath === false) {
            throw new RuntimeException('Temporäre Datei für den Medienabruf konnte nicht erstellt werden.');
        }

        file_put_contents($tempPath, $content);

        try {
            $asset = $this->storeLocalPath(
                $product,
                $tempPath,
                $originalName,
                $mimeType,
                $position,
                static function (string $sourcePath, string $targetDirectory, string $filename, ?UploadedFile $uploadedFile = null): void {
                    $targetPath = $targetDirectory.DIRECTORY_SEPARATOR.$filename;
                    if (!@rename($sourcePath, $targetPath)) {
                        if (!@copy($sourcePath, $targetPath)) {
                            throw new RuntimeException(sprintf('Remote-Medium "%s" konnte nicht gespeichert werden.', $filename));
                        }
                        @unlink($sourcePath);
                    }
                },
            );
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }

        $asset->setAltText($altText !== null && trim($altText) !== '' ? trim($altText) : $product->getName());

        return $asset;
    }

    public function resolveAbsolutePath(ProductAsset $asset): string
    {
        return rtrim($this->mediaStoragePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$asset->getStoragePath();
    }

    public function deleteProductMedia(Product $product): void
    {
        $directories = [];

        foreach ($product->getAssets() as $asset) {
            $absolutePath = $this->resolveAbsolutePath($asset);
            $directories[dirname($absolutePath)] = true;

            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }

        $directoryPaths = array_keys($directories);
        usort($directoryPaths, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($directoryPaths as $directoryPath) {
            if (!is_dir($directoryPath)) {
                continue;
            }

            $entries = scandir($directoryPath);
            if ($entries === false) {
                continue;
            }

            if (count(array_diff($entries, ['.', '..'])) === 0) {
                @rmdir($directoryPath);
            }
        }
    }

    /**
     * @param callable(string, string, string, UploadedFile|null): void $mover
     */
    private function storeLocalPath(
        Product $product,
        string $sourcePath,
        string $originalName,
        string $mimeType,
        int $position,
        callable $mover,
        ?UploadedFile $uploadedFile = null,
    ): ProductAsset {
        $relativeDirectory = (string) $product->getPublicId();
        $absoluteDirectory = rtrim($this->mediaStoragePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relativeDirectory;

        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) {
            throw new RuntimeException(sprintf('Media-Verzeichnis "%s" konnte nicht erstellt werden.', $absoluteDirectory));
        }

        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = $this->extensionFromMimeType($mimeType);
        }

        $safeName = (string) $this->slugger->slug($baseName !== '' ? $baseName : 'asset')->lower();
        $filename = sprintf('%s-%s.%s', $safeName !== '' ? $safeName : 'asset', bin2hex(random_bytes(4)), $extension !== '' ? $extension : 'bin');

        $mover($sourcePath, $absoluteDirectory, $filename, $uploadedFile);

        $asset = new ProductAsset(
            str_starts_with($mimeType, 'image/') ? AssetType::Image : AssetType::Document,
            $filename,
            $originalName,
            $mimeType !== '' ? $mimeType : 'application/octet-stream',
            $relativeDirectory.'/'.$filename,
        );
        $asset->setPosition($position);

        return $asset;
    }

    private function filenameFromUrl(string $url, string $mimeType): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $basename = trim(basename($path));
        if ($basename !== '' && $basename !== '/' && $basename !== '.') {
            return $basename;
        }

        return 'remote-asset.'.$this->extensionFromMimeType($mimeType);
    }

    private function extensionFromMimeType(string $mimeType): string
    {
        return match (strtolower($mimeType)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }
}
