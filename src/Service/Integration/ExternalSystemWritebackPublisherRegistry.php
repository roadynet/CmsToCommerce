<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Dto\ExternalWritebackResult;
use App\Entity\Product;
use App\Enum\ExternalSystemType;
use App\Integration\Jtl\JtlErpApiConnector;
use App\Integration\Pimcore\PimcoreApiConnector;
use App\Integration\Plentymarkets\PlentymarketsRestApiConnector;
use App\Integration\SapR3\SapR3GatewayConnector;
use App\Integration\Shopify\ShopifyAdminApiConnector;

final class ExternalSystemWritebackPublisherRegistry
{
    /**
     * @var array<string, ExternalSystemWritebackPublisher>
     */
    private array $publishers = [];

    public function __construct(
        JtlErpApiConnector $jtlErpApiConnector,
        PlentymarketsRestApiConnector $plentymarketsRestApiConnector,
        SapR3GatewayConnector $sapR3GatewayConnector,
        PimcoreApiConnector $pimcoreApiConnector,
        ShopifyAdminApiConnector $shopifyAdminApiConnector,
    )
    {
        foreach ([
            $jtlErpApiConnector,
            $plentymarketsRestApiConnector,
            $sapR3GatewayConnector,
            $pimcoreApiConnector,
            $shopifyAdminApiConnector,
        ] as $publisher) {
            $this->publishers[$publisher->system()->value] = $publisher;
        }
    }

    public function supports(ExternalSystemType $system): bool
    {
        return isset($this->publishers[$system->value]);
    }

    public function publish(Product $product, ExternalSystemType $system): ExternalWritebackResult
    {
        $publisher = $this->publishers[$system->value] ?? null;
        if ($publisher === null) {
            throw new \InvalidArgumentException(sprintf('Für %s gibt es aktuell noch keinen Live-Write-back.', $system->label()));
        }

        return $publisher->publish($product);
    }
}
