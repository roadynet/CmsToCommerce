<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Entity\Product;
use App\Enum\ExternalSystemType;

interface ExternalSystemWritebackPreviewBuilder
{
    public function system(): ExternalSystemType;

    /**
     * @return array<string, mixed>
     */
    public function build(Product $product): array;
}
