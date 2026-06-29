<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\ChannelType;

final readonly class ListingDraft
{
    /**
     * @param list<string> $bulletPoints
     * @param array<string, scalar|array|null> $technicalAttributes
     * @param list<string> $searchTerms
     * @param array{
     *     observed_facts: list<string>,
     *     inferred_facts: list<string>,
     *     missing_or_unverified: list<string>,
     *     conflicts: list<string>
     * } $sourceAudit
     * @param array{
     *     sequence: list<array{position: int, label: string, original_name: string}>,
     *     improvement_notes: list<string>
     * } $imageGuidance
     * @param array{
     *     strengths: list<string>,
     *     blockers: list<string>,
     *     fixes_to_reach_a_level: list<string>,
     *     confidence_note: string
     * } $qualityReview
     */
    public function __construct(
        public ChannelType $channel,
        public string $title,
        public array $bulletPoints,
        public string $description,
        public array $technicalAttributes,
        public array $searchTerms,
        public int $qualityScore,
        public string $qualityGrade,
        public array $sourceAudit,
        public array $imageGuidance,
        public array $qualityReview,
    ) {
    }
}
