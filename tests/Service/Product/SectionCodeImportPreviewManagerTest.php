<?php

declare(strict_types=1);

namespace App\Tests\Service\Product;

use App\Service\Import\ProductTextNormalizer;
use App\Service\Import\ProductVariantParser;
use App\Service\Import\SectionCodeFileGrouper;
use App\Service\Media\ProductMediaStorage;
use App\Service\Product\ProductIntakeManager;
use App\Service\Product\SectionCodeImportPreviewManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class SectionCodeImportPreviewManagerTest extends TestCase
{
    public function testPrepareLoadAndDiscardPreview(): void
    {
        $previewRoot = sys_get_temp_dir().'/ctc-preview-'.bin2hex(random_bytes(4));
        mkdir($previewRoot, 0777, true);

        $grouper = new SectionCodeFileGrouper();
        $normalizer = new ProductTextNormalizer();

        $previewManager = new SectionCodeImportPreviewManager(
            $previewRoot,
            $grouper,
            $normalizer,
            new ProductIntakeManager(
                $this->createStub(EntityManagerInterface::class),
                $normalizer,
                $grouper,
                new ProductVariantParser(),
                new ProductMediaStorage($previewRoot.'/media', new AsciiSlugger()),
                new AsciiSlugger(),
                'de',
            ),
        );

        $textFile = $this->createUploadedFile(
            '1.1.txt',
            "Marke: Acme\nProdukttyp: Tischleuchte\nTitel: Aurora LED Tischleuchte\nKategorie: Wohnen > Licht",
            'text/plain',
        );
        $imageFile = $this->createUploadedFile(
            '1.1.1.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wn3lWQAAAAASUVORK5CYII=', true) ?: '',
            'image/png',
        );

        $preview = $previewManager->prepare([$textFile], [$imageFile], [
            'cms_system' => 'Sections-Upload',
            'language_code' => 'de',
        ]);

        self::assertSame(1, $preview['summary']['produkt_anzahl']);
        self::assertSame(1, $preview['summary']['txt_anzahl']);
        self::assertSame(1, $preview['summary']['bild_anzahl']);
        self::assertCount(1, $preview['products']);
        self::assertSame('1.1', $preview['products'][0]['sectionscode']);
        self::assertSame('1.1.txt', $preview['products'][0]['textdatei']);
        self::assertSame(1, $preview['products'][0]['bild_anzahl']);
        self::assertSame(['1.1.1.png'], $preview['products'][0]['bilddateien']);

        $loadedPreview = $previewManager->load($preview['preview_token']);
        self::assertSame($preview['preview_token'], $loadedPreview['preview_token']);
        self::assertFileExists($previewRoot.'/'.$preview['preview_token'].'/manifest.json');

        $previewManager->discard($preview['preview_token']);
        self::assertDirectoryDoesNotExist($previewRoot.'/'.$preview['preview_token']);
    }

    private function createUploadedFile(string $filename, string $content, string $mimeType): UploadedFile
    {
        $filePath = tempnam(sys_get_temp_dir(), 'ctc-preview-file-');
        if ($filePath === false) {
            self::fail('Temporäre Datei konnte nicht erstellt werden.');
        }

        file_put_contents($filePath, $content);

        return new UploadedFile($filePath, $filename, $mimeType, null, true);
    }
}
