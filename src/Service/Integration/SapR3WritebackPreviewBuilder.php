<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Entity\Product;
use App\Entity\ProductSource;
use App\Enum\ChannelType;
use App\Enum\ExternalSystemType;

final class SapR3WritebackPreviewBuilder extends AbstractExternalSystemWritebackPreviewBuilder
{
    public function system(): ExternalSystemType
    {
        return ExternalSystemType::SapR3;
    }

    public function build(Product $product): array
    {
        $draft = $this->productListingDraftBuilder->build($product, ChannelType::Amazon);
        $materialNumber = $this->sapMaterialNumber($product);
        $language = strtoupper($this->languageCode($product));

        return $this->buildPreviewEnvelope($product, [
            'sap_hinweis' => 'SAP R/3 spricht typischerweise RFC/BAPI/IDoc. CTC sendet dieses Payload deshalb an einen SAP-Gateway-, PI/PO-, CPI- oder RFC-Proxy.',
            'transport_empfehlung' => 'MATMAS05-IDoc oder BAPI_MATERIAL_SAVEDATA plus kundeneigene Z-Felder für Amazon-/Shopware-Listingtexte.',
            'materialnummer' => $materialNumber,
            'idoc' => [
                'basic_type' => 'MATMAS05',
                'message_type' => 'MATMAS',
                'segments' => [
                    'E1MARAM' => [
                        'MATNR' => $materialNumber ?: 'wird_vor_live_writeback_aufgeloest',
                    ],
                    'E1MAKTM' => [
                        'SPRAS_ISO' => $language,
                        'MAKTX' => $this->limit($draft->title, 40),
                    ],
                    'ZCTC_LISTING_TEXT' => [
                        'TITLE' => $draft->title,
                        'SHORT_TEXT' => $this->shortText($draft->bulletPoints),
                        'LONG_TEXT' => $draft->description,
                        'BULLETS' => $draft->bulletPoints,
                        'KEYWORDS' => implode(', ', $draft->searchTerms),
                        'QUALITY_SCORE' => $draft->qualityScore,
                        'QUALITY_GRADE' => $draft->qualityGrade,
                    ],
                    'ZCTC_ATTRIBUTES' => $this->filteredTechnicalAttributes($draft->technicalAttributes),
                ],
            ],
            'bapi' => [
                'name' => 'BAPI_MATERIAL_SAVEDATA',
                'material' => $materialNumber ?: 'wird_vor_live_writeback_aufgeloest',
                'materialdescription' => [[
                    'LANGU_ISO' => $language,
                    'MATL_DESC' => $this->limit($draft->title, 40),
                ]],
                'extensionin' => [[
                    'STRUCTURE' => 'BAPI_TE_MARA',
                    'VALUEPART1' => 'CTC_LISTING_JSON',
                    'VALUEPART2' => 'siehe payload.ctc_listing',
                ]],
            ],
        ]);
    }

    private function sapMaterialNumber(Product $product): ?string
    {
        foreach ($product->getSources() as $source) {
            if (!$source instanceof ProductSource) {
                continue;
            }

            $cmsSystem = strtolower(trim((string) $source->getCmsSystem()));
            if (!in_array($cmsSystem, ['sap_r3', 'sap-r3', 'sap', 'r3'], true)) {
                continue;
            }

            $reference = trim((string) $source->getExternalReference());
            if ($reference !== '') {
                return $reference;
            }
        }

        foreach ($product->getVariants() as $variant) {
            $sku = trim($variant->getSku());
            if ($sku !== '') {
                return $sku;
            }
        }

        return null;
    }

    private function languageCode(Product $product): string
    {
        foreach ($product->getSources() as $source) {
            if (!$source instanceof ProductSource) {
                continue;
            }

            $language = trim($source->getLanguageCode());
            if ($language !== '') {
                return substr($language, 0, 2);
            }
        }

        return 'DE';
    }

    private function limit(string $value, int $limit): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_substr($value, 0, $limit);
    }
}
