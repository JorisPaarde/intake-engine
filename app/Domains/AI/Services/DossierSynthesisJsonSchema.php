<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoPlacementType;
use App\Enums\FollowUpItemType;

/**
 * JSON Schema for structured dossier-synthesis output.
 *
 * Wire format is intentionally a Gemini/OpenRouter-safe subset: type, enum,
 * properties, required, additionalProperties, items, minimum/maximum.
 * Cardinality (minItems), patterns and maxLength stay server-validated via
 * {@see DossierSynthesisPartialAcceptor} — those keywords 400 on several
 * Google endpoints when sent under response_format.json_schema.
 */
final class DossierSynthesisJsonSchema
{
    /**
     * Keywords rejected by Gemini structured-output endpoints (via OpenRouter).
     * Kept as documentation for the payload contract tests.
     *
     * @var list<string>
     */
    public const UNSUPPORTED_WIRE_KEYWORDS = [
        'pattern',
        'maxLength',
        'minLength',
        'minItems',
        'maxItems',
        'format',
        'oneOf',
        'anyOf',
        'allOf',
        '$ref',
        '$defs',
        'definitions',
        'unevaluatedProperties',
        'propertyNames',
        'patternProperties',
    ];

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'summary',
                'placement_proposals',
                'option_proposals',
                'exceptions',
                'customer_tasks',
            ],
            'properties' => [
                'summary' => ['type' => 'string'],
                'placement_proposals' => [
                    'type' => 'array',
                    'items' => $this->placementProposal(),
                ],
                'option_proposals' => [
                    'type' => 'array',
                    'items' => $this->optionProposal(),
                ],
                'exceptions' => [
                    'type' => 'array',
                    'items' => $this->exceptionItem(),
                ],
                'customer_tasks' => [
                    'type' => 'array',
                    'items' => $this->customerTask(),
                ],
            ],
        ];
    }

    /**
     * @return array{type: string, json_schema: array{name: string, strict: bool, schema: array<string, mixed>}}
     */
    public function responseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'dossier_synthesis',
                'strict' => true,
                'schema' => $this->schema(),
            ],
        ];
    }

    /**
     * Recursively assert the schema contains no Gemini-unsupported keywords.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    public function unsupportedKeywordsIn(array $node): array
    {
        $found = [];
        $stack = [$node];

        while ($stack !== []) {
            $current = array_pop($stack);
            foreach ($current as $key => $value) {
                if (is_string($key) && in_array($key, self::UNSUPPORTED_WIRE_KEYWORDS, true)) {
                    $found[] = $key;
                }
                if (is_array($value)) {
                    $stack[] = $value;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** @return array<string, mixed> */
    private function placementProposal(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'key',
                'type',
                'label',
                'description',
                'room_reference',
                'subject_reference',
                'confidence',
                'evidence_references',
            ],
            'properties' => [
                'key' => ['type' => 'string'],
                'type' => ['type' => 'string', 'enum' => array_map(
                    static fn (AircoPlacementType $case): string => $case->value,
                    AircoPlacementType::cases(),
                )],
                'label' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                // Nullable as type union (Gemini-supported); pattern enforced server-side.
                'room_reference' => ['type' => ['string', 'null']],
                'subject_reference' => ['type' => 'string'],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'evidence_references' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function optionProposal(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'label',
                'configuration_type',
                'summary',
                'cost_impact',
                'confidence',
                'placement_references',
                'connections',
            ],
            'properties' => [
                'label' => ['type' => 'string'],
                'configuration_type' => ['type' => 'string', 'enum' => array_map(
                    static fn (AircoConfigurationType $case): string => $case->value,
                    AircoConfigurationType::cases(),
                )],
                'summary' => ['type' => 'string'],
                'cost_impact' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'unknown']],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'placement_references' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'connections' => [
                    'type' => 'array',
                    'items' => $this->connection(),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function connection(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'type',
                'label',
                'from_placement_reference',
                'to_placement_reference',
                'status',
                'length_class',
                'segments',
                'obstacles',
                'uncertainties',
                'cost_impact',
                'confidence',
                'evidence_references',
            ],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => array_map(
                    static fn (AircoConnectionType $case): string => $case->value,
                    AircoConnectionType::cases(),
                )],
                'label' => ['type' => 'string'],
                'from_placement_reference' => ['type' => ['string', 'null']],
                'to_placement_reference' => ['type' => ['string', 'null']],
                'status' => ['type' => 'string', 'enum' => [
                    AircoConnectionStatus::Proposed->value,
                    AircoConnectionStatus::NeedsEvidence->value,
                    AircoConnectionStatus::NotRemotelyResolvable->value,
                ]],
                'length_class' => ['type' => 'string', 'enum' => ['short', 'medium', 'long', 'unknown']],
                'segments' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'obstacles' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'uncertainties' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'cost_impact' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'unknown']],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'evidence_references' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function exceptionItem(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'code',
                'label',
                'decision_area_key',
                'confidence',
                'evidence_references',
            ],
            'properties' => [
                'code' => ['type' => 'string'],
                'label' => ['type' => 'string'],
                'decision_area_key' => ['type' => 'string', 'enum' => [
                    'request', 'capacity', 'placement', 'refrigerant', 'condensate', 'power', 'cost_risks',
                ]],
                'confidence' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                'evidence_references' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function customerTask(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'type',
                'prompt',
                'decision_area_key',
                'subject_reference',
                'reason',
                'evidence_references',
            ],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => array_map(
                    static fn (FollowUpItemType $case): string => $case->value,
                    FollowUpItemType::cases(),
                )],
                'prompt' => ['type' => 'string'],
                'decision_area_key' => ['type' => 'string', 'enum' => [
                    'request', 'capacity', 'placement', 'refrigerant', 'condensate', 'power', 'cost_risks',
                ]],
                'subject_reference' => ['type' => ['string', 'null']],
                'reason' => ['type' => 'string'],
                'evidence_references' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }
}
