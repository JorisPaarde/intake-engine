<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\AircoPlacementOption;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Enums\AircoConnectionType;
use Illuminate\Validation\ValidationException;

/**
 * Keeps contribution task subject links aligned with decision_area_key.
 *
 * Structural only: no prompt/AI meaning. Used when the installer changes
 * “onderdeel” on a concept, or when a subject disappeared after AI rebuild.
 */
final class ContributionSubjectLinker
{
    public const MESSAGE_FOREIGN_INTAKE = 'Die koppeling hoort niet bij deze opname.';

    public const MESSAGE_STALE_AFTER_REBUILD = 'Dit voorstel is net bijgewerkt. Ververs de pagina en probeer het opnieuw.';

    /**
     * Resolve a dossier_subject_id for this intake + decision area.
     *
     * - Foreign intake subject → security message (unchanged).
     * - Missing subject (deleted after rebuild) → map to a live successor in the
     *   same area when possible, otherwise the refresh message.
     * - Subject whose area no longer matches decision_area_key → rematch to a
     *   subject in the new area, or null (area-level).
     *
     * @throws ValidationException
     */
    public function resolve(Intake $intake, mixed $subjectId, mixed $decisionAreaKey, string $errorKey = 'dossier_subject_id'): ?int
    {
        $areaKey = is_string($decisionAreaKey) && $decisionAreaKey !== ''
            ? $decisionAreaKey
            : null;

        if ($subjectId === null || $subjectId === '') {
            return null;
        }

        if (! is_numeric($subjectId)) {
            throw ValidationException::withMessages([
                $errorKey => self::MESSAGE_FOREIGN_INTAKE,
            ]);
        }

        $id = (int) $subjectId;
        $subject = DossierSubject::query()->find($id);

        if ($subject instanceof DossierSubject && (int) $subject->intake_id !== (int) $intake->id) {
            throw ValidationException::withMessages([
                $errorKey => self::MESSAGE_FOREIGN_INTAKE,
            ]);
        }

        if (! $subject instanceof DossierSubject) {
            $mapped = $areaKey !== null
                ? $this->firstSubjectIdForArea($intake, $areaKey)
                : null;

            if ($mapped !== null) {
                return $mapped;
            }

            throw ValidationException::withMessages([
                $errorKey => self::MESSAGE_STALE_AFTER_REBUILD,
            ]);
        }

        if ($areaKey === null) {
            return $id;
        }

        $subjectArea = $this->areaForSubject($subject);
        if ($subjectArea === null || $subjectArea === $areaKey) {
            return $id;
        }

        // Installer changed onderdeel: keep evidence on the new area, not the old route.
        return $this->firstSubjectIdForArea($intake, $areaKey);
    }

    /**
     * @param  array{
     *     decision_area_key?: mixed,
     *     dossier_subject_id?: mixed,
     *     ...
     * }  $item
     * @return array<string, mixed>
     */
    public function alignItem(Intake $intake, array $item, string $errorKey = 'dossier_subject_id'): array
    {
        $item['dossier_subject_id'] = $this->resolve(
            $intake,
            $item['dossier_subject_id'] ?? null,
            $item['decision_area_key'] ?? null,
            $errorKey,
        );

        return $item;
    }

    public function areaForSubject(DossierSubject $subject): ?string
    {
        return match ($subject->type) {
            'airco_room' => 'capacity',
            'airco_placement' => 'placement',
            'airco_connection' => match ($subject->meta['connection_type'] ?? null) {
                AircoConnectionType::Refrigerant->value => 'refrigerant',
                AircoConnectionType::Condensate->value => 'condensate',
                AircoConnectionType::Power->value => 'power',
                default => null,
            },
            default => null,
        };
    }

    public function firstSubjectIdForArea(Intake $intake, string $areaKey): ?int
    {
        return match ($areaKey) {
            'power', 'condensate', 'refrigerant' => $this->firstConnectionSubjectId($intake, $areaKey),
            'capacity' => $this->nullableInt(
                AircoRoom::query()
                    ->where('intake_id', $intake->id)
                    ->orderBy('id')
                    ->value('dossier_subject_id'),
            ),
            'placement' => $this->nullableInt(
                AircoPlacementOption::query()
                    ->where('intake_id', $intake->id)
                    ->orderBy('id')
                    ->value('dossier_subject_id'),
            ),
            default => null,
        };
    }

    private function firstConnectionSubjectId(Intake $intake, string $areaKey): ?int
    {
        $type = AircoConnectionType::tryFrom($areaKey);
        if ($type === null) {
            return null;
        }

        return $this->nullableInt(
            AircoConnection::query()
                ->where('intake_id', $intake->id)
                ->where('type', $type)
                ->orderBy('id')
                ->value('dossier_subject_id'),
        );
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
