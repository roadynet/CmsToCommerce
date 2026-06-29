<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Enum\ExternalSystemType;

interface ExternalSystemPayloadNormalizer
{
    public function system(): ExternalSystemType;

    /**
     * @param array<string, mixed> $payload
     */
    public function supports(?string $systemHint, array $payload): bool;

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function normalize(array $payload): array;

    /**
     * @return array{
     *     code: string,
     *     label: string,
     *     status: string,
     *     summary: string,
     *     next_step: string,
     *     example_keys: list<string>,
     *     intake_ready: bool
     * }
     */
    public function overview(): array;
}
