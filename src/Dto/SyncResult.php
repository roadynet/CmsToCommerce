<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\ChannelType;
use App\Enum\SyncStatus;

final readonly class SyncResult
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public ChannelType $channel,
        public SyncStatus $status,
        public string $message,
        public array $payload = [],
        public ?string $externalId = null,
    ) {
    }
}
