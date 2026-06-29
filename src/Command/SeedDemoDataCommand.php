<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Product;
use App\Entity\ProductAsset;
use App\Entity\ProductSource;
use App\Entity\ProductVariant;
use App\Enum\AssetType;
use App\Enum\ChannelType;
use App\Enum\ProductStatus;
use App\Enum\SourceType;
use App\Service\Publishing\PublicationOrchestrator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-demo-data',
    description: 'Seed a demo product with Amazon and Shopware draft listings.',
)]
final class SeedDemoDataCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PublicationOrchestrator $publicationOrchestrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('reset', null, InputOption::VALUE_NONE, 'Delete existing demo data before seeding.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('reset')) {
            foreach ([
                'App\Entity\PublicationRun',
                'App\Entity\ChannelListing',
                'App\Entity\ProductAsset',
                'App\Entity\ProductSource',
                'App\Entity\ProductVariant',
                'App\Entity\Product',
            ] as $className) {
                foreach ($this->entityManager->getRepository($className)->findAll() as $entity) {
                    $this->entityManager->remove($entity);
                }
            }

            $this->entityManager->flush();
            $io->note('Existing demo data removed.');
        }

        $existing = $this->entityManager->getRepository(Product::class)->findOneBy(['name' => 'Thermo Travel Bottle 750ml']);
        if ($existing instanceof Product) {
            $io->success('Demo product already exists. Use --reset to recreate it.');

            return Command::SUCCESS;
        }

        $product = (new Product('Thermo Travel Bottle 750ml'))
            ->setBrand('North Trail')
            ->setSlug('thermo-travel-bottle-750ml')
            ->setCategoryPath('Outdoor/Drinkware/Thermo Bottles')
            ->setStatus(ProductStatus::Approved)
            ->setDescription('Double-wall stainless steel bottle for commuting, gym bags, and day trips. Keeps drinks cool or hot and is intended as a premium reusable everyday bottle.');

        $source = (new ProductSource(
            SourceType::CmsImport,
            "Title: Thermo Travel Bottle 750ml\nBrand: North Trail\nMaterial: Stainless steel\nColor: Matte black\nFeature: Double wall insulated\nFeature: Leak-resistant lid\nFeature: Fits backpack side pockets"
        ))
            ->setCmsSystem('legacy-cms')
            ->setExternalReference('cms-article-1001');

        $product->addSource($source);

        $product->addAsset(
            (new ProductAsset(
                AssetType::Image,
                'north-trail-bottle-front.jpg',
                'bottle-front.jpg',
                'image/jpeg',
                'demo/north-trail-bottle-front.jpg',
            ))->setPosition(1)->setAltText('North Trail thermo bottle front view')
        );

        $product->addAsset(
            (new ProductAsset(
                AssetType::Image,
                'north-trail-bottle-lid.jpg',
                'bottle-lid.jpg',
                'image/jpeg',
                'demo/north-trail-bottle-lid.jpg',
            ))->setPosition(2)->setAltText('North Trail leak-resistant lid detail')
        );

        $product->addVariant(
            (new ProductVariant('NT-BOTTLE-750-BLK'))
                ->setOptionSummary(['color' => 'Black', 'size' => '750ml'])
                ->setEan('1234567890123')
                ->setPriceGross('24.90')
                ->setStock(120)
        );

        $this->entityManager->persist($product);
        $this->entityManager->flush();

        $this->publicationOrchestrator->publish($product, ChannelType::Shopware);
        $this->publicationOrchestrator->publish($product, ChannelType::Amazon);

        $io->success('Demo product, listings, and publication runs created.');

        return Command::SUCCESS;
    }
}
