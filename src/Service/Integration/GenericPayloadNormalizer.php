<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Enum\ExternalSystemType;

final class GenericPayloadNormalizer extends AbstractExternalSystemPayloadNormalizer
{
    public function system(): ExternalSystemType
    {
        return ExternalSystemType::Generic;
    }

    public function supports(?string $systemHint, array $payload): bool
    {
        return true;
    }

    public function normalize(array $payload): array
    {
        $cmsSystem = $this->stringValue(
            $payload['cms_system'] ?? null,
            $payload['source_system'] ?? null,
            $payload['quelle_system'] ?? null,
            $payload['system'] ?? null,
        ) ?? 'generic-api';

        return [
            ...$payload,
            'cms_system' => $cmsSystem,
            'asset_urls' => $this->assetDescriptors(
                $payload['asset_urls']
                    ?? $payload['image_urls']
                    ?? $payload['bilder']
                    ?? $payload['images']
                    ?? []
            ),
            'external_reference' => $this->stringValue(
                $payload['external_reference'] ?? null,
                $payload['externe_referenz'] ?? null,
                $payload['product_id'] ?? null,
                $payload['produkt_id'] ?? null,
                $payload['id'] ?? null,
            ),
            'source_payload' => is_array($payload['source_payload'] ?? null)
                ? $payload['source_payload']
                : $this->preservedPayload($payload, ['system' => $cmsSystem]),
        ];
    }

    public function overview(): array
    {
        return [
            'code' => $this->system()->value,
            'label' => $this->system()->label(),
            'status' => 'intake aktiv',
            'summary' => 'Generische JSON-Payloads können bereits direkt an die CTC-Intake-API gesendet werden.',
            'next_step' => 'Feld-Mapping und optionale Remote-Bilder pro Quellsystem schärfen.',
            'example_keys' => ['produkt_name', 'marke', 'kategorie_pfad', 'rohtext', 'variants', 'asset_urls'],
            'intake_ready' => true,
        ];
    }
}
