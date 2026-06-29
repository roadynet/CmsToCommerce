<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Entity\Product;
use App\Entity\ProductSource;
use App\Entity\ProductVariant;
use App\Enum\SourceType;
use App\Repository\ProductRepository;
use App\Service\Import\ProductTextNormalizer;
use App\Service\Import\ProductVariantParser;
use App\Service\Import\SectionCodeFileGrouper;
use App\Service\Integration\ExternalSystemProductSyncManager;
use App\Service\Media\ProductMediaStorage;
use App\Service\Product\ProductIntakeManager;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class ExternalSystemProductSyncManagerTest extends TestCase
{
    public function testSyncCreatesProductAndDownloadsRemoteMedia(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $mediaRoot = sys_get_temp_dir().'/ctc-sync-media-'.bin2hex(random_bytes(4));
        mkdir($mediaRoot, 0777, true);

        $productIntakeManager = new ProductIntakeManager(
            $entityManager,
            new ProductTextNormalizer(),
            new SectionCodeFileGrouper(),
            new ProductVariantParser(),
            new ProductMediaStorage($mediaRoot, new AsciiSlugger(), new MockHttpClient([
                new MockResponse(
                    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wn3lWQAAAAASUVORK5CYII=', true) ?: '',
                    ['response_headers' => ['content-type: image/png']],
                ),
            ])),
            new AsciiSlugger(),
            'de',
        );

        $repository = $this->createStub(ProductRepository::class);
        $repository->method('findOneBySourceReference')->willReturn(null);
        $repository->method('findOneByVariantSku')->willReturn(null);

        $manager = new ExternalSystemProductSyncManager(
            $repository,
            $productIntakeManager,
            new ProductVariantParser(),
            new ProductMediaStorage($mediaRoot, new AsciiSlugger(), new MockHttpClient([
                new MockResponse(
                    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wn3lWQAAAAASUVORK5CYII=', true) ?: '',
                    ['response_headers' => ['content-type: image/png']],
                ),
            ])),
            $entityManager,
            new AsciiSlugger(),
        );

        $result = $manager->sync([
            'produkt_name' => 'Edelstahl Trinkflasche 750 ml',
            'marke' => 'North Trail',
            'cms_system' => 'jtl',
            'external_reference' => '4711',
            'rohtext' => 'Material: Edelstahl',
            'asset_urls' => [[
                'url' => 'https://example.com/images/flasche-front.png',
                'name' => 'flasche-front.png',
                'alt' => 'Frontansicht',
            ]],
        ]);

        self::assertTrue($result->created);
        self::assertFalse($result->deltaOnly);
        self::assertSame(1, $result->mediaAdded);
        self::assertSame('Edelstahl Trinkflasche 750 ml', $result->product->getName());
        self::assertCount(1, $result->product->getAssets());
        self::assertSame('flasche-front.png', $result->product->getAssets()->first()->getOriginalName());
    }

    public function testDeltaSyncUpdatesExistingVariantPriceAndStock(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $mediaRoot = sys_get_temp_dir().'/ctc-sync-media-'.bin2hex(random_bytes(4));
        mkdir($mediaRoot, 0777, true);

        $product = new Product('Lampe');
        $product->addSource(
            (new ProductSource(SourceType::CmsImport, '{"id":"55"}'))
                ->setCmsSystem('xentral')
                ->setExternalReference('55'),
        );
        $product->addVariant(
            (new ProductVariant('LD-USB-C'))
                ->setPriceGross('49.90')
                ->setCurrency('EUR')
                ->setStock(4),
        );

        $repository = $this->createStub(ProductRepository::class);
        $repository->method('findOneBySourceReference')->willReturn($product);

        $manager = new ExternalSystemProductSyncManager(
            $repository,
            new ProductIntakeManager(
                $entityManager,
                new ProductTextNormalizer(),
                new SectionCodeFileGrouper(),
                new ProductVariantParser(),
                new ProductMediaStorage($mediaRoot, new AsciiSlugger()),
                new AsciiSlugger(),
                'de',
            ),
            new ProductVariantParser(),
            new ProductMediaStorage($mediaRoot, new AsciiSlugger()),
            $entityManager,
            new AsciiSlugger(),
        );

        $result = $manager->sync([
            'cms_system' => 'xentral',
            'external_reference' => '55',
            'variants' => [[
                'sku' => 'LD-USB-C',
                'price' => '59.90',
                'stock' => 14,
                'currency' => 'EUR',
            ]],
        ], true);

        self::assertFalse($result->created);
        self::assertTrue($result->deltaOnly);
        self::assertSame(1, $result->variantsUpdated);
        self::assertSame(0, $result->variantsCreated);
        self::assertSame('59.90', $product->getVariants()->first()->getPriceGross());
        self::assertSame(14, $product->getVariants()->first()->getStock());
        self::assertCount(2, $product->getSources());
    }

    public function testDeltaSyncRequiresExistingProduct(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $mediaRoot = sys_get_temp_dir().'/ctc-sync-media-'.bin2hex(random_bytes(4));
        mkdir($mediaRoot, 0777, true);

        $repository = $this->createStub(ProductRepository::class);
        $repository->method('findOneBySourceReference')->willReturn(null);
        $repository->method('findOneByVariantSku')->willReturn(null);

        $manager = new ExternalSystemProductSyncManager(
            $repository,
            new ProductIntakeManager(
                $entityManager,
                new ProductTextNormalizer(),
                new SectionCodeFileGrouper(),
                new ProductVariantParser(),
                new ProductMediaStorage($mediaRoot, new AsciiSlugger()),
                new AsciiSlugger(),
                'de',
            ),
            new ProductVariantParser(),
            new ProductMediaStorage($mediaRoot, new AsciiSlugger()),
            $entityManager,
            new AsciiSlugger(),
        );

        $this->expectException(InvalidArgumentException::class);
        $manager->sync([
            'cms_system' => 'jtl',
            'external_reference' => 'missing',
            'variants' => [[
                'sku' => 'UNKNOWN-1',
                'price' => '19.90',
                'stock' => 2,
            ]],
        ], true);
    }
}
