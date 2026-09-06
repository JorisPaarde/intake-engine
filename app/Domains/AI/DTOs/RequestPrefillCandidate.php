<?php

declare(strict_types=1);

namespace App\Domains\AI\DTOs;

/**
 * Één geclassificeerde prefillkandidaat (lokaal of catalogus-AI).
 *
 * Disposition is diagnostiek: productie past alleen fill/suggestion toe.
 */
final readonly class RequestPrefillCandidate
{
    public const DISPOSITION_FILL = 'fill';

    public const DISPOSITION_SUGGESTION = 'suggestion';

    public const DISPOSITION_REJECTED = 'rejected';

    public const SOURCE_LOCAL = 'local';

    public const SOURCE_CATALOG_AI = 'catalog_ai';

    /**
     * @param  array<string, mixed>|null  $value
     */
    public function __construct(
        public string $questionKey,
        public ?string $sectionInstanceKey,
        public string $label,
        public ?array $value,
        public ?string $confidence,
        public ?string $evidence,
        public string $disposition,
        public string $source,
        public ?string $reason = null,
    ) {}

    public function compositeKey(): string
    {
        return $this->sectionInstanceKey === null
            ? $this->questionKey
            : $this->questionKey.'@'.$this->sectionInstanceKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'question_key' => $this->questionKey,
            'section_instance_key' => $this->sectionInstanceKey,
            'label' => $this->label,
            'value' => $this->value,
            'confidence' => $this->confidence,
            'evidence' => $this->evidence,
            'disposition' => $this->disposition,
            'source' => $this->source,
            'reason' => $this->reason,
        ];
    }
}
