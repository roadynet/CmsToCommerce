<?php

declare(strict_types=1);

namespace App\Tests\Service\Product;

use App\Entity\Product;
use App\Entity\ProductAsset;
use App\Entity\ProductVariant;
use App\Enum\AssetType;
use App\Enum\ProductStatus;
use App\Service\Import\ProductVariantParser;
use App\Service\Media\ProductMediaStorage;
use App\Service\Product\ProductEditorManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class ProductEditorManagerTest extends TestCase
{
    public function testUpdateReplacesVariantsAndAppendsNewImages(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $mediaRoot = sys_get_temp_dir().'/ctc-editor-media-'.bin2hex(random_bytes(4));
        mkdir($mediaRoot, 0777, true);

        $manager = new ProductEditorManager(
            $entityManager,
            new ProductVariantParser(),
            new ProductMediaStorage($mediaRoot, new AsciiSlugger()),
            new AsciiSlugger(),
        );

        $product = (new Product('Altprodukt'))
            ->setBrand('Altmarke')
            ->setCategoryPath('Alt > Kategorie')
            ->setDescription('Altbeschreibung')
            ->setStatus(ProductStatus::Draft);

        $existingVariant = (new ProductVariant('ALT-1'))->setStock(1);
        $product->addVariant($existingVariant);

        $existingAsset = new ProductAsset(AssetType::Image, 'existing.png', 'existing.png', 'image/png', 'demo/existing.png');
        $existingAsset->setPosition(1);
        $product->addAsset($existingAsset);

        $uploadPath = tempnam(sys_get_temp_dir(), 'ctc-edit-upload-');
        if ($uploadPath === false) {
            self::fail('Temporäre Upload-Datei konnte nicht erstellt werden.');
        }

        file_put_contents(
            $uploadPath,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wn3lWQAAAAASUVORK5CYII=', true)
        );

        $uploadedFile = new UploadedFile($uploadPath, 'new.png', 'image/png', null, true);

        $entityManager->expects(self::once())->method('persist')->with($product);
        $entityManager->expects(self::once())->method('flush');

        $manager->update($product, [
            'name' => 'Neues Produkt',
            'brand' => 'Neue Marke',
            'category_path' => 'Neu > Kategorie',
            'status' => ProductStatus::Approved->value,
            'description' => 'Neue Beschreibung',
            'variants_text' => '[{"sku":"NEU-1","optionen":{"Farbe":"Schwarz"},"ean":"4259001100001","preis":"29,90","bestand":12,"aktiv":true}]',
        ], [$uploadedFile]);

        self::assertSame('Neues Produkt', $product->getName());
        self::assertSame('neues-produkt', $product->getSlug());
        self::assertSame('Neue Marke', $product->getBrand());
        self::assertSame('Neu > Kategorie', $product->getCategoryPath());
        self::assertSame(ProductStatus::Approved, $product->getStatus());
        self::assertSame('Neue Beschreibung', $product->getDescription());
        self::assertCount(1, $product->getVariants());
        self::assertSame('NEU-1', $product->getVariants()->first()->getSku());
        self::assertCount(2, $product->getAssets());

        $storedAsset = $product->getAssets()->last();
        self::assertInstanceOf(ProductAsset::class, $storedAsset);
        self::assertSame(2, $storedAsset->getPosition());
        self::assertFileExists($mediaRoot.'/'.$storedAsset->getStoragePath());
    }

    public function testDeleteRemovesMediaAndProduct(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $mediaRoot = sys_get_temp_dir().'/ctc-editor-delete-'.bin2hex(random_bytes(4));
        mkdir($mediaRoot, 0777, true);

        $mediaStorage = new ProductMediaStorage($mediaRoot, new AsciiSlugger());
        $manager = new ProductEditorManager(
            $entityManager,
            new ProductVariantParser(),
            $mediaStorage,
            new AsciiSlugger(),
        );

        $product = new Product('Löschprodukt');
        $productDirectory = $mediaRoot.'/'.(string) $product->getPublicId();
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

        $entityManager->expects(self::once())->method('remove')->with($product);
        $entityManager->expects(self::once())->method('flush');

        $manager->delete($product);

        self::assertFileDoesNotExist($absoluteFile);
        self::assertDirectoryDoesNotExist($productDirectory);
    }
}
