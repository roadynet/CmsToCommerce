<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\ChannelType;
use App\Service\Export\ProductChannelExportBuilder;
use App\Service\Integration\ExternalSystemIntakeRegistry;
use App\Service\Integration\ExternalSystemProductSyncManager;
use App\Service\Publishing\PublicationOrchestrator;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
final class ProductApiController extends AbstractController
{
    public function __construct(
        private readonly string $importApiToken = '',
    ) {
    }

    #[Route('/intake', name: 'app_api_product_intake', methods: ['POST'])]
    public function intake(
        Request $request,
        ExternalSystemIntakeRegistry $externalSystemIntakeRegistry,
        ExternalSystemProductSyncManager $externalSystemProductSyncManager,
        PublicationOrchestrator $publicationOrchestrator,
        ProductChannelExportBuilder $exportBuilder,
    ): JsonResponse {
        return $this->handleSyncRequest($request, $externalSystemIntakeRegistry, $externalSystemProductSyncManager, $publicationOrchestrator, $exportBuilder, false);
    }

    #[Route('/intake/delta', name: 'app_api_product_delta', methods: ['POST'])]
    public function delta(
        Request $request,
        ExternalSystemIntakeRegistry $externalSystemIntakeRegistry,
        ExternalSystemProductSyncManager $externalSystemProductSyncManager,
        PublicationOrchestrator $publicationOrchestrator,
        ProductChannelExportBuilder $exportBuilder,
    ): JsonResponse {
        return $this->handleSyncRequest($request, $externalSystemIntakeRegistry, $externalSystemProductSyncManager, $publicationOrchestrator, $exportBuilder, true);
    }

    private function handleSyncRequest(
        Request $request,
        ExternalSystemIntakeRegistry $externalSystemIntakeRegistry,
        ExternalSystemProductSyncManager $externalSystemProductSyncManager,
        PublicationOrchestrator $publicationOrchestrator,
        ProductChannelExportBuilder $exportBuilder,
        bool $deltaOnly,
    ): JsonResponse {
        $providedToken = $request->headers->get('X-CTC-Token') ?? $request->headers->get('Authorization');
        $token = str_starts_with((string) $providedToken, 'Bearer ') ? substr((string) $providedToken, 7) : (string) $providedToken;

        if ($this->importApiToken === '' || !hash_equals($this->importApiToken, $token)) {
            return $this->json([
                'erfolg' => false,
                'meldung' => 'API-Token fehlt oder ist ungültig.',
            ], 401);
        }

        try {
            $payload = $request->toArray();
            $systemHint = $request->headers->get('X-CTC-Source-System')
                ?? (is_string($payload['source_system'] ?? null) ? $payload['source_system'] : null)
                ?? (is_string($payload['quelle_system'] ?? null) ? $payload['quelle_system'] : null)
                ?? (is_string($payload['system'] ?? null) ? $payload['system'] : null);
            $normalizedPayload = $externalSystemIntakeRegistry->normalize($payload, $systemHint);
            $syncModeHeader = strtolower(trim((string) $request->headers->get('X-CTC-Sync-Mode', '')));
            $syncModePayload = strtolower(trim((string) ($payload['sync_mode'] ?? '')));
            $resolvedDeltaOnly = $deltaOnly || $syncModeHeader === 'delta' || $syncModePayload === 'delta';
            $syncResult = $externalSystemProductSyncManager->sync($normalizedPayload, $resolvedDeltaOnly);
            $product = $syncResult->product;
            foreach (ChannelType::cases() as $channel) {
                $publicationOrchestrator->prepare($product, $channel);
            }

            return $this->json([
                'erfolg' => true,
                'produkt_id' => $product->getId(),
                'produkt_name' => $product->getName(),
                'eingangssystem' => $normalizedPayload['_ctc_system_label'] ?? $normalizedPayload['cms_system'] ?? 'API',
                'system_code' => $normalizedPayload['_ctc_system_code'] ?? null,
                'normalisierte_varianten' => is_array($normalizedPayload['variants'] ?? null) ? count($normalizedPayload['variants']) : 0,
                'modus' => $syncResult->deltaOnly ? 'delta' : 'upsert',
                'produkt_erstellt' => $syncResult->created,
                'medien_ergänzt' => $syncResult->mediaAdded,
                'varianten_aktualisiert' => $syncResult->variantsUpdated,
                'varianten_neu' => $syncResult->variantsCreated,
                'warnungen' => $syncResult->warnings,
                'exporte' => [
                    'amazon' => $exportBuilder->build($product, ChannelType::Amazon),
                    'shopware' => $exportBuilder->build($product, ChannelType::Shopware),
                ],
            ], 201);
        } catch (InvalidArgumentException $exception) {
            return $this->json([
                'erfolg' => false,
                'meldung' => $exception->getMessage(),
            ], 422);
        }
    }
}
