<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ChannelListing;
use App\Entity\Product;
use App\Entity\PublicationRun;
use App\Enum\ExternalSystemType;
use App\Enum\ChannelType;
use App\Enum\ListingStatus;
use App\Enum\ProductStatus;
use App\Enum\SyncStatus;
use App\Repository\ProductRepository;
use App\Service\Export\ProductChannelExportBuilder;
use App\Service\Integration\ExternalSystemWritebackPreviewRegistry;
use App\Service\Integration\ExternalSystemWritebackPublisherRegistry;
use App\Service\Product\ProductEditorManager;
use App\Service\Product\ProductIntakeManager;
use App\Service\Product\SectionCodeImportPreviewManager;
use App\Service\Publishing\PublicationOrchestrator;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route('/products')]
final class ProductController extends AbstractController
{
    #[Route('', name: 'app_product_index')]
    public function index(ProductRepository $productRepository, string $shopwareBaseUrl): Response
    {
        try {
            $products = $productRepository->findLatest(50);
            $databaseWarning = null;
        } catch (Throwable $exception) {
            $products = [];
            $databaseWarning = $exception->getMessage();
        }

        $channelOverviews = [];
        foreach ($products as $product) {
            $productKey = $product->getId() ?? spl_object_id($product);
            foreach (ChannelType::cases() as $channel) {
                $channelOverviews[$productKey][$channel->value] = $this->buildChannelPanel($product, $channel, $shopwareBaseUrl);
            }
        }

        return $this->render('product/index.html.twig', [
            'products' => $products,
            'database_warning' => $databaseWarning,
            'channel_overviews' => $channelOverviews,
        ]);
    }

    #[Route('/new', name: 'app_product_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        ProductIntakeManager $intakeManager,
        SectionCodeImportPreviewManager $sectionCodeImportPreviewManager,
        PublicationOrchestrator $publicationOrchestrator,
    ): Response {
        $manualFormData = [
            'name' => '',
            'brand' => '',
            'category_path' => '',
            'description' => '',
            'raw_text' => '',
            'language_code' => 'de',
            'cms_system' => '',
            'external_reference' => '',
            'variants_text' => '',
        ];
        $sectionFormData = [
            'cms_system' => 'Sections-Upload',
            'category_path' => '',
            'language_code' => 'de',
        ];
        $manualErrors = [];
        $sectionErrors = [];
        $sectionPreview = null;

        if ($request->isMethod('POST')) {
            $importMode = (string) $request->request->get('import_mode', 'manual');

            if ($importMode === 'sections') {
                if (!$this->isCsrfTokenValid('product_section_import', (string) $request->request->get('_token'))) {
                    throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
                }

                $sectionFormData = array_merge($sectionFormData, [
                    'cms_system' => (string) $request->request->get('section_cms_system', $sectionFormData['cms_system']),
                    'category_path' => (string) $request->request->get('section_category_path', $sectionFormData['category_path']),
                    'language_code' => (string) $request->request->get('section_language_code', $sectionFormData['language_code']),
                ]);
                $sectionAction = (string) $request->request->get('section_action', 'preview');
                $previewToken = trim((string) $request->request->get('preview_token', ''));

                try {
                    if ($sectionAction === 'import') {
                        if ($previewToken === '') {
                            throw new InvalidArgumentException('Bitte zuerst die Dateien prüfen, bevor du den Import startest.');
                        }

                        $sectionPreview = $sectionCodeImportPreviewManager->load($previewToken);
                        $result = $sectionCodeImportPreviewManager->importPrepared($previewToken);

                        foreach ($result['products'] as $product) {
                            foreach (ChannelType::cases() as $channel) {
                                $publicationOrchestrator->prepare($product, $channel);
                            }
                        }

                        foreach ($result['warnings'] as $warning) {
                            $this->addFlash('warning', $warning);
                        }

                        $importedCount = count($result['products']);
                        $this->addFlash('success', sprintf('%d Produkt(e) per Sectionscode importiert und Entwürfe erzeugt.', $importedCount));

                        if ($importedCount === 1) {
                            return $this->redirectToRoute('app_product_show', ['id' => $result['products'][0]->getId()]);
                        }

                        return $this->redirectToRoute('app_product_index');
                    }

                    if ($previewToken !== '') {
                        $sectionCodeImportPreviewManager->discard($previewToken);
                    }

                    $sectionTextFiles = array_values(array_filter(
                        $request->files->all('section_text_files') ?? [],
                        static fn (mixed $file): bool => $file instanceof UploadedFile,
                    ));
                    $sectionAssetFiles = array_values(array_filter(
                        $request->files->all('section_asset_files') ?? [],
                        static fn (mixed $file): bool => $file instanceof UploadedFile,
                    ));

                    $sectionPreview = $sectionCodeImportPreviewManager->prepare($sectionTextFiles, $sectionAssetFiles, $sectionFormData);
                } catch (InvalidArgumentException $exception) {
                    $sectionErrors[] = $exception->getMessage();
                } catch (Throwable $exception) {
                    if ($previewToken !== '') {
                        try {
                            $sectionPreview = $sectionCodeImportPreviewManager->load($previewToken);
                        } catch (Throwable) {
                        }
                    }

                    $sectionErrors[] = 'Listenimport fehlgeschlagen: '.$exception->getMessage();
                }
            } else {
                if (!$this->isCsrfTokenValid('product_intake', (string) $request->request->get('_token'))) {
                    throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
                }

                $manualFormData = array_merge($manualFormData, $request->request->all());

                try {
                    $uploadedFiles = array_values(array_filter(
                        $request->files->all('images') ?? [],
                        static fn (mixed $file): bool => $file instanceof UploadedFile,
                    ));

                    $product = $intakeManager->createFromInput($manualFormData, $uploadedFiles);

                    foreach (ChannelType::cases() as $channel) {
                        $publicationOrchestrator->prepare($product, $channel);
                    }

                    $this->addFlash('success', 'Produkt importiert, Bilder gespeichert und Kanal-Entwürfe erzeugt.');

                    return $this->redirectToRoute('app_product_show', ['id' => $product->getId()]);
                } catch (InvalidArgumentException $exception) {
                    $manualErrors[] = $exception->getMessage();
                } catch (Throwable $exception) {
                    $manualErrors[] = 'Import fehlgeschlagen: '.$exception->getMessage();
                }
            }
        }

        return $this->render('product/new.html.twig', [
            'manual_form_data' => $manualFormData,
            'section_form_data' => $sectionFormData,
            'manual_errors' => $manualErrors,
            'section_errors' => $sectionErrors,
            'section_preview' => $sectionPreview,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_product_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Product $product,
        Request $request,
        ProductEditorManager $productEditorManager,
        PublicationOrchestrator $publicationOrchestrator,
    ): Response {
        $formData = $this->buildEditFormData($product);
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('product_edit_'.$product->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
            }

            $formData = [
                'name' => (string) $request->request->get('name', $formData['name']),
                'brand' => (string) $request->request->get('brand', $formData['brand']),
                'category_path' => (string) $request->request->get('category_path', $formData['category_path']),
                'status' => (string) $request->request->get('status', $formData['status']),
                'description' => (string) $request->request->get('description', $formData['description']),
                'variants_text' => (string) $request->request->get('variants_text', $formData['variants_text']),
            ];

            try {
                $uploadedFiles = array_values(array_filter(
                    $request->files->all('images') ?? [],
                    static fn (mixed $file): bool => $file instanceof UploadedFile,
                ));

                $productEditorManager->update($product, $formData, $uploadedFiles);

                foreach (ChannelType::cases() as $channel) {
                    try {
                        $publicationOrchestrator->prepare($product, $channel);
                    } catch (Throwable $exception) {
                        $this->addFlash('warning', sprintf('Entwurf für %s konnte nicht aktualisiert werden: %s', $channel->label(), $exception->getMessage()));
                    }
                }

                $this->addFlash('success', 'Produkt wurde gespeichert.');

                return $this->redirectToRoute('app_product_show', ['id' => $product->getId()]);
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            } catch (Throwable $exception) {
                $errors[] = 'Produkt konnte nicht gespeichert werden: '.$exception->getMessage();
            }
        }

        return $this->render('product/edit.html.twig', [
            'product' => $product,
            'form_data' => $formData,
            'errors' => $errors,
            'status_options' => ProductStatus::cases(),
        ]);
    }

    #[Route('/{id}', name: 'app_product_show', requirements: ['id' => '\d+'])]
    public function show(Product $product, ProductChannelExportBuilder $exportBuilder, string $shopwareBaseUrl): Response
    {
        $exports = [];
        $channelPanels = [];
        foreach (ChannelType::cases() as $channel) {
            $exports[$channel->value] = $exportBuilder->build($product, $channel);
            $channelPanels[$channel->value] = $this->buildChannelPanel($product, $channel, $shopwareBaseUrl);
        }

        return $this->render('product/show.html.twig', [
            'product' => $product,
            'exports' => $exports,
            'channels' => ChannelType::cases(),
            'channel_panels' => $channelPanels,
            'publication_runs' => $this->sortedPublicationRuns($product),
        ]);
    }

    #[Route('/{id}/delete', name: 'app_product_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Product $product, Request $request, ProductEditorManager $productEditorManager): Response
    {
        if (!$this->isCsrfTokenValid('product_delete_'.$product->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        $productName = $product->getName();

        try {
            $productEditorManager->delete($product);
            $this->addFlash('success', sprintf('Produkt "%s" wurde gelöscht.', $productName));

            return $this->redirectToRoute('app_product_index');
        } catch (Throwable $exception) {
            $this->addFlash('warning', 'Produkt konnte nicht gelöscht werden: '.$exception->getMessage());

            return $this->redirectToRoute('app_product_show', ['id' => $product->getId()]);
        }
    }

    #[Route('/{id}/prepare/{channel}', name: 'app_product_prepare', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function prepare(Product $product, string $channel, Request $request, PublicationOrchestrator $publicationOrchestrator): Response
    {
        $resolvedChannel = $this->resolveChannel($channel);
        $this->guardActionToken($request, $product, $resolvedChannel, 'prepare');
        $publicationOrchestrator->prepare($product, $resolvedChannel);
        $this->addFlash('success', sprintf('%s-Entwurf wurde neu erzeugt.', $resolvedChannel->label()));

        return $this->redirectToRoute('app_product_show', ['id' => $product->getId()]);
    }

    #[Route('/{id}/publish/{channel}', name: 'app_product_publish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function publish(Product $product, string $channel, Request $request, PublicationOrchestrator $publicationOrchestrator): Response
    {
        $resolvedChannel = $this->resolveChannel($channel);
        $this->guardActionToken($request, $product, $resolvedChannel, 'publish');
        $result = $publicationOrchestrator->publish($product, $resolvedChannel);
        $flashType = $result->status === SyncStatus::Succeeded ? 'success' : 'warning';
        $this->addFlash($flashType, sprintf('%s: %s', $resolvedChannel->label(), $result->message));

        return $this->redirectToRoute('app_product_show', ['id' => $product->getId()]);
    }

    #[Route('/{id}/export/{channel}', name: 'app_product_export', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function export(Product $product, string $channel, ProductChannelExportBuilder $exportBuilder): JsonResponse
    {
        $resolvedChannel = $this->resolveChannel($channel);

        return $this->json($exportBuilder->build($product, $resolvedChannel));
    }

    #[Route('/{id}/writeback/{system}', name: 'app_product_writeback_preview', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function writebackPreview(Product $product, string $system, ExternalSystemWritebackPreviewRegistry $externalSystemWritebackPreviewRegistry): JsonResponse
    {
        try {
            $resolvedSystem = ExternalSystemType::from($system);
        } catch (\ValueError) {
            throw $this->createNotFoundException('Unbekanntes Zielsystem.');
        }

        if ($resolvedSystem === ExternalSystemType::Generic) {
            throw $this->createNotFoundException('Für generische Systeme gibt es keine feste Write-back-Preview.');
        }

        return $this->json($externalSystemWritebackPreviewRegistry->build($product, $resolvedSystem));
    }

    #[Route('/{id}/writeback/{system}/publish', name: 'app_product_writeback_publish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function writebackPublish(
        Product $product,
        string $system,
        Request $request,
        ExternalSystemWritebackPublisherRegistry $externalSystemWritebackPublisherRegistry,
    ): Response {
        if (!$this->isCsrfTokenValid('writeback_'.$product->getId().'_'.$system, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Write-back-Token.');
        }

        try {
            $resolvedSystem = ExternalSystemType::from($system);
        } catch (\ValueError) {
            throw $this->createNotFoundException('Unbekanntes Zielsystem.');
        }

        if ($resolvedSystem === ExternalSystemType::Generic) {
            throw $this->createNotFoundException('Für generische Systeme gibt es keinen festen Write-back.');
        }

        try {
            $result = $externalSystemWritebackPublisherRegistry->publish($product, $resolvedSystem);
            $flashType = $result->status === SyncStatus::Succeeded ? 'success' : 'warning';
            $this->addFlash($flashType, sprintf('%s: %s', $resolvedSystem->label(), $result->message));
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('warning', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->addFlash('warning', sprintf('%s-Write-back fehlgeschlagen: %s', $resolvedSystem->label(), $exception->getMessage()));
        }

        return $this->redirectToRoute('app_product_show', ['id' => $product->getId()]);
    }

    private function resolveChannel(string $channel): ChannelType
    {
        try {
            return ChannelType::from($channel);
        } catch (\ValueError) {
            throw $this->createNotFoundException('Unbekannter Kanal.');
        }
    }

    private function guardActionToken(Request $request, Product $product, ChannelType $channel, string $action): void
    {
        $tokenId = sprintf('%s_%d_%s', $action, $product->getId(), $channel->value);
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Aktions-Token.');
        }
    }

    /**
     * @return array{name: string, brand: string, category_path: string, status: string, description: string, variants_text: string}
     */
    private function buildEditFormData(Product $product): array
    {
        return [
            'name' => $product->getName(),
            'brand' => $product->getBrand() ?? '',
            'category_path' => $product->getCategoryPath() ?? '',
            'status' => $product->getStatus()->value,
            'description' => $product->getDescription() ?? '',
            'variants_text' => $this->buildVariantsText($product),
        ];
    }

    private function buildVariantsText(Product $product): string
    {
        $variants = [];

        foreach ($product->getVariants() as $variant) {
            $variants[] = [
                'sku' => $variant->getSku(),
                'optionen' => $variant->getOptionSummary(),
                'ean' => $variant->getEan(),
                'preis' => $variant->getPriceGross(),
                'waehrung' => $variant->getCurrency(),
                'bestand' => $variant->getStock(),
                'aktiv' => $variant->isEnabled(),
            ];
        }

        if ($variants === []) {
            return '';
        }

        return (string) (json_encode($variants, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * @return array{
     *     status_label: string,
     *     status_badge_class: string,
     *     external_id: ?string,
     *     product_number: string,
     *     last_synced_at: ?\DateTimeImmutable,
     *     admin_url: ?string,
     *     storefront_search_url: ?string
     * }
     */
    private function buildShopwareOverview(Product $product, string $shopwareBaseUrl): array
    {
        $listing = $product->getChannelListingFor(ChannelType::Shopware);
        $productNumber = $this->buildShopwareProductNumber($product);

        return [
            'status_label' => $listing?->getStatus()->label() ?? 'Entwurf',
            'status_badge_class' => $this->listingBadgeClass($listing),
            'external_id' => $listing?->getExternalId(),
            'product_number' => $productNumber,
            'last_synced_at' => $listing?->getLastSyncedAt(),
            'admin_url' => $this->buildShopwareAdminUrl($shopwareBaseUrl, $listing?->getExternalId()),
            'storefront_search_url' => $this->buildShopwareStorefrontSearchUrl($shopwareBaseUrl, $productNumber),
        ];
    }

    /**
     * @return array{
     *     listing: ?ChannelListing,
     *     listing_badge_class: string,
     *     latest_run: ?PublicationRun,
     *     latest_run_status_label: ?string,
     *     latest_run_badge_class: string,
     *     latest_run_summary: ?string,
     *     latest_run_fallback_label: string,
     *     last_sync_label: string,
     *     sync_mode_label: ?string,
     *     sync_mode_badge_class: string,
     *     product_number: ?string,
     *     external_id: ?string,
     *     admin_url: ?string,
     *     storefront_search_url: ?string,
     *     publish_button_label: string,
     *     amazon_product_type: ?string,
     *     amazon_validation_mode: ?string,
     *     amazon_validation_status: ?string,
     *     amazon_requirements: ?string,
     *     amazon_live_sync: ?bool,
     *     amazon_live_publish_active: ?bool,
     *     amazon_missing_attributes_amazon: list<string>,
     *     amazon_missing_attributes_local: list<string>,
     *     amazon_mapped_attribute_count: ?int,
     *     amazon_image_attribute_count: ?int
     * }
     */
    private function buildChannelPanel(Product $product, ChannelType $channel, string $shopwareBaseUrl): array
    {
        $listing = $product->getChannelListingFor($channel);
        $latestRun = $this->latestPublicationRunForChannel($product, $channel);
        $latestPayload = $latestRun?->getPayload() ?? [];
        $syncModeLabel = $this->buildSyncModeLabel($channel, $latestPayload);
        $amazonMeta = $channel === ChannelType::Amazon
            ? $this->buildAmazonPanelMeta($listing, $latestPayload)
            : $this->emptyAmazonPanelMeta();

        $productNumber = $channel === ChannelType::Shopware
            ? $this->buildShopwareProductNumber($product)
            : null;

        return [
            'listing' => $listing,
            'listing_badge_class' => $this->listingBadgeClass($listing),
            'latest_run' => $latestRun,
            'latest_run_status_label' => $latestRun?->getStatus()->label(),
            'latest_run_badge_class' => $this->syncRunBadgeClass($latestRun),
            'latest_run_summary' => $latestRun?->getSummary(),
            'latest_run_fallback_label' => $channel === ChannelType::Amazon ? 'noch keine Vorschau' : 'noch kein Live-Sync',
            'last_sync_label' => $channel === ChannelType::Amazon ? 'Letzte Amazon-Prüfung' : 'Letzter Live-Sync',
            'sync_mode_label' => $syncModeLabel,
            'sync_mode_badge_class' => $this->syncModeBadgeClass($syncModeLabel),
            'product_number' => $productNumber,
            'external_id' => $listing?->getExternalId() ?? $amazonMeta['external_id'],
            'admin_url' => $channel === ChannelType::Shopware
                ? $this->buildShopwareAdminUrl($shopwareBaseUrl, $listing?->getExternalId())
                : null,
            'storefront_search_url' => $channel === ChannelType::Shopware && $productNumber !== null
                ? $this->buildShopwareStorefrontSearchUrl($shopwareBaseUrl, $productNumber)
                : null,
            'publish_button_label' => $channel === ChannelType::Amazon ? 'Amazon-Vorschau prüfen' : 'Nach Shopware senden',
            'amazon_product_type' => $amazonMeta['product_type'],
            'amazon_validation_mode' => $amazonMeta['validation_mode'],
            'amazon_validation_status' => $amazonMeta['validation_status'],
            'amazon_requirements' => $amazonMeta['requirements'],
            'amazon_live_sync' => $amazonMeta['live_sync'],
            'amazon_live_publish_active' => $amazonMeta['live_publish_active'],
            'amazon_missing_attributes_amazon' => $amazonMeta['missing_attributes_amazon'],
            'amazon_missing_attributes_local' => $amazonMeta['missing_attributes_local'],
            'amazon_mapped_attribute_count' => $amazonMeta['mapped_attribute_count'],
            'amazon_image_attribute_count' => $amazonMeta['image_attribute_count'],
        ];
    }

    private function latestPublicationRunForChannel(Product $product, ChannelType $channel): ?PublicationRun
    {
        $latestRun = null;

        foreach ($product->getPublicationRuns() as $run) {
            if ($run->getChannel() !== $channel) {
                continue;
            }

            if ($latestRun === null || $run->getStartedAt() > $latestRun->getStartedAt()) {
                $latestRun = $run;
            }
        }

        return $latestRun;
    }

    /**
     * @return list<PublicationRun>
     */
    private function sortedPublicationRuns(Product $product): array
    {
        $runs = array_values($product->getPublicationRuns()->toArray());
        usort(
            $runs,
            static fn (PublicationRun $left, PublicationRun $right): int => $right->getStartedAt() <=> $left->getStartedAt(),
        );

        return $runs;
    }

    private function listingBadgeClass(?ChannelListing $listing): string
    {
        return match ($listing?->getStatus()) {
            ListingStatus::Published => 'badge-success',
            ListingStatus::Validated => 'badge-success',
            ListingStatus::SyncError => 'badge-warning',
            default => 'badge',
        };
    }

    private function syncRunBadgeClass(?PublicationRun $run): string
    {
        return match ($run?->getStatus()) {
            SyncStatus::Succeeded => 'badge-success',
            SyncStatus::Failed => 'badge-warning',
            default => 'badge',
        };
    }

    private function syncModeBadgeClass(?string $syncModeLabel): string
    {
        return match ($syncModeLabel) {
            'Neu angelegt' => 'badge-success',
            'Live gesendet' => 'badge-success',
            'Aktualisiert' => 'badge',
            'Vorschau gesendet' => 'badge',
            default => 'badge-warning',
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildSyncModeLabel(ChannelType $channel, array $payload): ?string
    {
        if ($channel === ChannelType::Amazon) {
            if (($payload['live_sync'] ?? false) === true) {
                return 'Live gesendet';
            }

            $validationMode = strtoupper((string) ($payload['validierungsmodus'] ?? $payload['listings_item_validierungsmodus'] ?? ''));

            return $validationMode === 'VALIDATION_PREVIEW' ? 'Vorschau gesendet' : null;
        }

        $apiMethod = strtoupper((string) ($payload['api_methode'] ?? ''));

        return match ($apiMethod) {
            'POST' => 'Neu angelegt',
            'PATCH' => 'Aktualisiert',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{
     *     external_id: ?string,
     *     product_type: ?string,
     *     validation_mode: ?string,
     *     validation_status: ?string,
     *     requirements: ?string,
     *     live_sync: ?bool,
     *     live_publish_active: ?bool,
     *     missing_attributes_amazon: list<string>,
     *     missing_attributes_local: list<string>,
     *     mapped_attribute_count: ?int,
     *     image_attribute_count: ?int
     * }
     */
    private function buildAmazonPanelMeta(?ChannelListing $listing, array $payload): array
    {
        $mappedAttributes = is_array($payload['listings_item_payload']['gemappte_attribute'] ?? null)
            ? $payload['listings_item_payload']['gemappte_attribute']
            : [];
        $externalId = trim((string) ($listing?->getExternalId() ?? $payload['erkannte_amazon_asin'] ?? ''));
        $productType = trim((string) (
            $payload['produkttyp_definition']['product_type']
            ?? $payload['produkt_typ_mapping']['amazon_kandidaten']['ausgewaehlter_product_type']['name']
            ?? ''
        ));
        $validationMode = trim((string) ($payload['validierungsmodus'] ?? $payload['listings_item_validierungsmodus'] ?? ''));
        $validationStatus = strtoupper(trim((string) ($payload['validierung']['status'] ?? '')));
        $requirements = trim((string) ($payload['listings_item_requirements'] ?? ''));

        return [
            'external_id' => $externalId !== '' ? $externalId : null,
            'product_type' => $productType !== '' ? $productType : null,
            'validation_mode' => $validationMode !== '' ? $validationMode : null,
            'validation_status' => $validationStatus !== '' ? $validationStatus : null,
            'requirements' => $requirements !== '' ? $requirements : null,
            'live_sync' => array_key_exists('live_sync', $payload) ? (bool) $payload['live_sync'] : null,
            'live_publish_active' => array_key_exists('live_publish_aktiv', $payload) ? (bool) $payload['live_publish_aktiv'] : null,
            'missing_attributes_amazon' => $this->normalizeStringList($payload['validierung']['fehlende_pflichtattribute_laut_amazon'] ?? []),
            'missing_attributes_local' => $this->normalizeStringList($payload['listings_item_payload']['lokal_fehlende_pflichtattribute'] ?? []),
            'mapped_attribute_count' => $mappedAttributes !== [] ? count($mappedAttributes) : null,
            'image_attribute_count' => $mappedAttributes !== [] ? $this->countAmazonImageAttributes($mappedAttributes) : null,
        ];
    }

    /**
     * @return array{
     *     external_id: ?string,
     *     product_type: ?string,
     *     validation_mode: ?string,
     *     validation_status: ?string,
     *     requirements: ?string,
     *     live_sync: ?bool,
     *     live_publish_active: ?bool,
     *     missing_attributes_amazon: list<string>,
     *     missing_attributes_local: list<string>,
     *     mapped_attribute_count: ?int,
     *     image_attribute_count: ?int
     * }
     */
    private function emptyAmazonPanelMeta(): array
    {
        return [
            'external_id' => null,
            'product_type' => null,
            'validation_mode' => null,
            'validation_status' => null,
            'requirements' => null,
            'live_sync' => null,
            'live_publish_active' => null,
            'missing_attributes_amazon' => [],
            'missing_attributes_local' => [],
            'mapped_attribute_count' => null,
            'image_attribute_count' => null,
        ];
    }

    /**
     * @param array<string, array{quelle: string, typ: string, elemente: int}> $mappedAttributes
     */
    private function countAmazonImageAttributes(array $mappedAttributes): int
    {
        $count = 0;

        foreach ($mappedAttributes as $attributeName => $meta) {
            if (($meta['typ'] ?? null) === 'image_locator' || str_contains($attributeName, 'image_locator')) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== '',
        ));
    }

    private function buildShopwareProductNumber(Product $product): string
    {
        return sprintf('CTC-%s', strtoupper((string) $product->getPublicId()));
    }

    private function buildShopwareAdminUrl(string $shopwareBaseUrl, ?string $externalId): ?string
    {
        $baseUrl = rtrim($shopwareBaseUrl, '/');
        $externalId = trim((string) $externalId);

        if ($baseUrl === '' || $externalId === '') {
            return null;
        }

        return $baseUrl.'/admin#/sw/product/detail/'.$externalId;
    }

    private function buildShopwareStorefrontSearchUrl(string $shopwareBaseUrl, string $productNumber): ?string
    {
        $baseUrl = rtrim($shopwareBaseUrl, '/');
        if ($baseUrl === '') {
            return null;
        }

        return $baseUrl.'/search?search='.rawurlencode($productNumber);
    }
}
