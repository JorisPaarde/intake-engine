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
 *
 * Never picks “the first” of several routes/rooms — only a unique candidate
 * may be rematched; otherwise area-level (null) or a refresh message.
 */
final class ContributionSubjectLinker
{
    public const MESSAGE_FOREIGN_INTAKE = 'Die koppeling hoort niet bij deze opname.';

    public const MESSAGE_STALE_AFTER_REBUILD = 'Dit voorstel is net bijgewerkt. Ververs de pagina en probeer het opnieuw.';

    /**
     * Resolve a dossier_subject_id for this intake + decision area.
     *
     * - Foreign intake subject → security message (unchanged).
     * - Missing subject (deleted after rebuild) → map only when exactly one
     *   live subject exists in the decision area; otherwise refresh message.
     * - Subject whose area no longer matches decision_area_key → link at area
     *   level (null) unless exactly one live subject exists in the new area.
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
                ? $this->soleSubjectIdForArea($intake, $areaKey)
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

        // Installer changed onderdeel: only keep a subject link when unambiguous.
        return $this->soleSubjectIdForArea($intake, $areaKey);
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

    /**
     * Live subject for an area only when there is exactly one candidate.
     * Zero or multiple → null (area-level / ambiguous).
     */
    public function soleSubjectIdForArea(Intake $intake, string $areaKey): ?int
    {
        $ids = $this->liveSubjectIdsForArea($intake, $areaKey);

        return count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * @return list<int>
     */
    public function liveSubjectIdsForArea(Intake $intake, string $areaKey): array
    {
        return match ($areaKey) {
            'power', 'condensate', 'refrigerant' => $this->connectionSubjectIds($intake, $areaKey),
            'capacity' => $this->intList(
                AircoRoom::query()
                    ->where('intake_id', $intake->id)
                    ->orderBy('id')
                    ->pluck('dossier_subject_id')
                    ->all(),
            ),
            'placement' => $this->intList(
                AircoPlacementOption::query()
                    ->where('intake_id', $intake->id)
                    ->orderBy('id')
                    ->pluck('dossier_subject_id')
                    ->all(),
            ),
            default => [],
        };
    }

    /**
     * @return list<int>
     */
    private function connectionSubjectIds(Intake $intake, string $areaKey): array
    {
        $type = AircoConnectionType::tryFrom($areaKey);
        if ($type === null) {
            return [];
        }

        return $this->intList(
            AircoConnection::query()
                ->where('intake_id', $intake->id)
                ->where('type', $type)
                ->orderBy('id')
                ->pluck('dossier_subject_id')
                ->all(),
        );
    }

    /**
     * @param  list<mixed>  $values
     * @return list<int>
     */
    private function intList(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }
}
