<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\ExternalSystemType;
use App\Enum\SyncStatus;

final readonly class ExternalWritebackResult
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public ExternalSystemType $system,
        public SyncStatus $status,
        public string $message,
        public array $payload = [],
        public ?string $externalId = null,
    ) {
    }
}
