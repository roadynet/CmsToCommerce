<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Product;

final class ExternalSystemSyncResult
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public Product $product,
        public bool $created,
        public bool $deltaOnly,
        public int $mediaAdded,
        public int $variantsUpdated,
        public int $variantsCreated,
        public array $warnings = [],
    ) {
    }
}
