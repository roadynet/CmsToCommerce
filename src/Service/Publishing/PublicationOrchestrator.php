<?php

declare(strict_types=1);

namespace App\Service\Publishing;

use App\Dto\SyncResult;
use App\Entity\ChannelListing;
use App\Entity\Product;
use App\Entity\PublicationRun;
use App\Enum\ChannelType;
use App\Enum\ListingStatus;
use App\Enum\SyncStatus;
use App\Integration\Amazon\AmazonSpApiConnector;
use App\Integration\Shopware\ShopwareAdminApiConnector;
use App\Service\Export\ListingDataTranslator;
use App\Service\Listing\ProductListingDraftBuilder;
use Doctrine\ORM\EntityManagerInterface;

final class PublicationOrchestrator
{
    public function __construct(
        private readonly ProductListingDraftBuilder $draftBuilder,
        private readonly AmazonSpApiConnector $amazonConnector,
        private readonly ShopwareAdminApiConnector $shopwareConnector,
        private readonly ListingDataTranslator $listingDataTranslator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function prepare(Product $product, ChannelType $channel): ChannelListing
    {
        $draft = $this->draftBuilder->build($product, $channel);
        $listing = $product->getChannelListingFor($channel) ?? new ChannelListing($channel, $draft->title);

        $listing
            ->setTitle($draft->title)
            ->setBulletPoints($draft->bulletPoints)
            ->setDescription($draft->description)
            ->setTechnicalAttributes($draft->technicalAttributes)
            ->setSearchTerms($draft->searchTerms)
            ->setQualityScore($draft->qualityScore)
            ->setQualityNotes(json_encode([
                'qualitaetsnote' => $draft->qualityGrade,
                'quellenpruefung' => $this->listingDataTranslator->sourceAudit($draft->sourceAudit),
                'qualitaetspruefung' => $this->listingDataTranslator->qualityReview($draft->qualityReview),
                'bildleitfaden' => $this->listingDataTranslator->imageGuidance($draft->imageGuidance),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->setStatus(ListingStatus::ReadyToPublish);

        if ($listing->getProduct() !== $product) {
            $product->addChannelListing($listing);
        }

        $this->entityManager->persist($product);
        $this->entityManager->persist($listing);
        $this->entityManager->flush();

        return $listing;
    }

    public function publish(Product $product, ChannelType $channel): SyncResult
    {
        $listing = $this->prepare($product, $channel);
        $draft = $this->draftBuilder->build($product, $channel);
        $run = new PublicationRun($channel, 'publish');
        $run->setProduct($product);
        $run->setStatus(SyncStatus::Running);

        $result = match ($channel) {
            ChannelType::Amazon => $this->amazonConnector->publish($product, $draft),
            ChannelType::Shopware => $this->shopwareConnector->publish($product, $draft),
        };

        $run
            ->setPayload($result->payload)
            ->finish($result->status, $result->message);

        if ($result->status === SyncStatus::Succeeded) {
            if (($result->payload['live_sync'] ?? true) === false) {
                $listing->markValidated($result->externalId);
            } else {
                $listing->markSynced($result->externalId);
            }
        } else {
            $listing->markSyncError($result->message);
        }

        $this->entityManager->persist($run);
        $this->entityManager->persist($listing);
        $this->entityManager->flush();

        return $result;
    }
}
