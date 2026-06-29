<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Dto\ExternalWritebackResult;
use App\Entity\Product;
use App\Enum\ExternalSystemType;

interface ExternalSystemWritebackPublisher
{
    public function system(): ExternalSystemType;

    public function publish(Product $product): ExternalWritebackResult;
}
