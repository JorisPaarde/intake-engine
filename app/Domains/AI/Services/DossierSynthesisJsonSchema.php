<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Enums\AircoConfigurationType;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoPlacementType;
use App\Enums\FollowUpItemType;

/**
 * JSON Schema for OpenAI/OpenRouter strict structured output (dossier synthesis).
 * Encodes enums, required fields and reference formats. Cardinality and
 * cross-references remain server-validated (partial acceptance).
 */
final class DossierSynthesisJsonSchema
{
    private const string PLACEMENT_REF = '^(placement:[0-9]+|proposal:[a-z0-9_]+)$';

    private const string PROPOSAL_KEY = '^proposal:[a-z0-9_]+$';

    private const string ROOM_REF = '^room:[0-9]+$';

    private const string SUBJECT_REF = '^subject:[0-9]+$';

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
                'summary' => ['type' => 'string', 'maxLength' => 800],
                'placement_proposals' => [
                    'type' => 'array',
                    'maxItems' => 20,
                    'items' => $this->placementProposal(),
                ],
                'option_proposals' => [
                    'type' => 'array',
                    'maxItems' => 3,
                    'items' => $this->optionProposal(),
                ],
                'exceptions' => [
                    'type' => 'array',
                    'maxItems' => 20,
                    'items' => $this->exceptionItem(),
                ],
                'customer_tasks' => [
                    'type' => 'array',
                    'maxItems' => 3,
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
                'key' => ['type' => 'string', 'pattern' => self::PROPOSAL_KEY],
                'type' => ['type' => 'string', 'enum' => array_map(
                    static fn (AircoPlacementType $case): string => $case->value,
                    AircoPlacementType::cases(),
                )],
                'label' => ['type' => 'string', 'maxLength' => 160],
                'description' => ['type' => 'string', 'maxLength' => 1500],
                'room_reference' => [
                    'type' => ['string', 'null'],
                    'pattern' => self::ROOM_REF,
                ],
                'subject_reference' => ['type' => 'string', 'pattern' => self::SUBJECT_REF],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'evidence_references' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 20,
                    'items' => ['type' => 'string', 'maxLength' => 160],
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
                'label' => ['type' => 'string', 'maxLength' => 160],
                'configuration_type' => ['type' => 'string', 'enum' => array_map(
                    static fn (AircoConfigurationType $case): string => $case->value,
                    AircoConfigurationType::cases(),
                )],
                'summary' => ['type' => 'string', 'maxLength' => 2000],
                'cost_impact' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'unknown']],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'placement_references' => [
                    'type' => 'array',
                    'minItems' => 2,
                    'maxItems' => 20,
                    'items' => ['type' => 'string', 'pattern' => self::PLACEMENT_REF],
                ],
                'connections' => [
                    'type' => 'array',
                    'minItems' => 3,
                    'maxItems' => 40,
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
                'label' => ['type' => 'string', 'maxLength' => 180],
                'from_placement_reference' => [
                    'type' => ['string', 'null'],
                    'pattern' => self::PLACEMENT_REF,
                ],
                'to_placement_reference' => [
                    'type' => ['string', 'null'],
                    'pattern' => self::PLACEMENT_REF,
                ],
                'status' => ['type' => 'string', 'enum' => [
                    AircoConnectionStatus::Proposed->value,
                    AircoConnectionStatus::NeedsEvidence->value,
                    AircoConnectionStatus::NotRemotelyResolvable->value,
                ]],
                'length_class' => ['type' => 'string', 'enum' => ['short', 'medium', 'long', 'unknown']],
                'segments' => [
                    'type' => 'array',
                    'maxItems' => 20,
                    'items' => ['type' => 'string', 'maxLength' => 200],
                ],
                'obstacles' => [
                    'type' => 'array',
                    'maxItems' => 20,
                    'items' => ['type' => 'string', 'maxLength' => 200],
                ],
                'uncertainties' => [
                    'type' => 'array',
                    'maxItems' => 20,
                    'items' => ['type' => 'string', 'maxLength' => 200],
                ],
                'cost_impact' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'unknown']],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'evidence_references' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 20,
                    'items' => ['type' => 'string', 'maxLength' => 160],
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
                'code' => ['type' => 'string', 'maxLength' => 100, 'pattern' => '^[a-z0-9_]+$'],
                'label' => ['type' => 'string', 'maxLength' => 500],
                'decision_area_key' => ['type' => 'string', 'enum' => [
                    'request', 'capacity', 'placement', 'refrigerant', 'condensate', 'power', 'cost_risks',
                ]],
                'confidence' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                'evidence_references' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 20,
                    'items' => ['type' => 'string', 'maxLength' => 160],
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
                'prompt' => ['type' => 'string', 'maxLength' => 500],
                'decision_area_key' => ['type' => 'string', 'enum' => [
                    'request', 'capacity', 'placement', 'refrigerant', 'condensate', 'power', 'cost_risks',
                ]],
                'subject_reference' => [
                    'type' => ['string', 'null'],
                    'pattern' => self::SUBJECT_REF,
                ],
                'reason' => ['type' => 'string', 'maxLength' => 500],
                'evidence_references' => [
                    'type' => 'array',
                    'maxItems' => 20,
                    'items' => ['type' => 'string', 'maxLength' => 160],
                ],
            ],
        ];
    }
}
