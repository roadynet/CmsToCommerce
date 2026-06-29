<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Enum\ExternalSystemType;

final class ExternalSystemIntakeRegistry
{
    /**
     * @var list<ExternalSystemPayloadNormalizer>
     */
    private array $normalizers;

    public function __construct(
        JtlPayloadNormalizer $jtlPayloadNormalizer,
        PlentymarketsPayloadNormalizer $plentymarketsPayloadNormalizer,
        XentralPayloadNormalizer $xentralPayloadNormalizer,
        GenericPayloadNormalizer $genericPayloadNormalizer,
    ) {
        $this->normalizers = [
            $jtlPayloadNormalizer,
            $plentymarketsPayloadNormalizer,
            $xentralPayloadNormalizer,
            $genericPayloadNormalizer,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function normalize(array $payload, ?string $systemHint = null): array
    {
        $normalizer = $this->resolveNormalizer($payload, $systemHint);
        $normalized = $normalizer->normalize($payload);
        $normalized['_ctc_system_code'] = $normalizer->system()->value;
        $normalized['_ctc_system_label'] = $normalizer->system()->label();

        return $normalized;
    }

    /**
     * @return list<array{
     *     code: string,
     *     label: string,
     *     status: string,
     *     summary: string,
     *     next_step: string,
     *     example_keys: list<string>,
     *     intake_ready: bool
     * }>
     */
    public function supportedSystems(): array
    {
        return array_values(array_map(
            static fn (ExternalSystemPayloadNormalizer $normalizer): array => $normalizer->overview(),
            array_filter(
                $this->normalizers,
                static fn (ExternalSystemPayloadNormalizer $normalizer): bool => $normalizer->system() !== ExternalSystemType::Generic,
            ),
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveNormalizer(array $payload, ?string $systemHint): ExternalSystemPayloadNormalizer
    {
        foreach ($this->normalizers as $normalizer) {
            if ($normalizer->supports($systemHint, $payload)) {
                return $normalizer;
            }
        }

        return $this->normalizers[array_key_last($this->normalizers)];
    }
}
