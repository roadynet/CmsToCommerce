<?php

declare(strict_types=1);

namespace App\Tests\Service\Media;

use App\Entity\Product;
use App\Entity\ProductAsset;
use App\Enum\AssetType;
use App\Service\Media\ProductMediaStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class ProductMediaStorageTest extends TestCase
{
    public function testStoreReadsMimeTypeBeforeMove(): void
    {
        $tempDirectory = sys_get_temp_dir().'/ctc-media-'.bin2hex(random_bytes(4));
        mkdir($tempDirectory, 0777, true);

        $sourceFile = tempnam(sys_get_temp_dir(), 'ctc-img-');
        if ($sourceFile === false) {
            self::fail('Temporäre Bilddatei konnte nicht erstellt werden.');
        }

        file_put_contents(
            $sourceFile,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wn3lWQAAAAASUVORK5CYII=', true)
        );

        $uploadedFile = new UploadedFile($sourceFile, '1.1.png', 'image/png', null, true);
        $storage = new ProductMediaStorage($tempDirectory, new AsciiSlugger());

        $asset = $storage->store(new Product('Testprodukt'), $uploadedFile, 1);

        self::assertSame('image/png', $asset->getMimeType());
        self::assertSame('image', $asset->getAssetType()->value);
        self::assertFileExists($tempDirectory.'/'.$asset->getStoragePath());
    }

    public function testDeleteProductMediaRemovesFilesAndEmptyDirectory(): void
    {
        $tempDirectory = sys_get_temp_dir().'/ctc-media-'.bin2hex(random_bytes(4));
        mkdir($tempDirectory, 0777, true);

        $product = new Product('Testprodukt');
        $productDirectory = $tempDirectory.'/'.(string) $product->getPublicId();
        mkdir($productDirectory, 0777, true);

        $absoluteFile = $productDirectory.'/existing.png';
        file_put_contents($absoluteFile, 'demo');

        $asset = new ProductAsset(
            AssetType::Image,
            'existing.png',
            'existing.png',
            'image/png',
            (string) $product->getPublicId().'/existing.png',
        );
        $asset->setPosition(1);
        $product->addAsset($asset);

        $storage = new ProductMediaStorage($tempDirectory, new AsciiSlugger());
        $storage->deleteProductMedia($product);

        self::assertFileDoesNotExist($absoluteFile);
        self::assertDirectoryDoesNotExist($productDirectory);
    }

    public function testStoreRemoteUrlDownloadsImage(): void
    {
        $tempDirectory = sys_get_temp_dir().'/ctc-media-'.bin2hex(random_bytes(4));
        mkdir($tempDirectory, 0777, true);

        $httpClient = new MockHttpClient([
            new MockResponse(
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wn3lWQAAAAASUVORK5CYII=', true) ?: '',
                ['response_headers' => ['content-type: image/png']],
            ),
        ]);

        $storage = new ProductMediaStorage($tempDirectory, new AsciiSlugger(), $httpClient);
        $asset = $storage->storeRemoteUrl(new Product('Remoteprodukt'), 'https://example.com/images/hero.png', 1, null, 'Hero');

        self::assertSame('image/png', $asset->getMimeType());
        self::assertSame('hero.png', $asset->getOriginalName());
        self::assertSame('Hero', $asset->getAltText());
        self::assertFileExists($tempDirectory.'/'.$asset->getStoragePath());
    }
}
