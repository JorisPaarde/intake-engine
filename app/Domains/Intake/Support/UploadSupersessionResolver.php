<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\FollowUpItemType;

/**
 * Bepaalt welke uploads voor de installateur als vervangen moeten gelden.
 *
 * Bouwt voort op #150 (FollowUpEvidenceReview-scope) en markeert ook eerdere
 * wizardfoto’s wanneer een latere vervolgronde bruikbaar bewijs voor hetzelfde
 * onderwerp heeft geleverd (bijv. meterkastfoto 207 → ronde-1 upload 208).
 *
 * @phpstan-type SupersessionInfo array{
 *     superseded: bool,
 *     supersession_label: string|null,
 *     replaced_by_upload_id: int|null
 * }
 */
final class UploadSupersessionResolver
{
    /**
     * @return array<int, SupersessionInfo>
     */
    public function resolve(Intake $intake): array
    {
        $intake->loadMissing([
            'uploads.followUpItem.round',
            'followUpRounds.items.uploads',
            'contributionTasks',
            'dossierSubjects.records',
        ]);

        /** @var array<int, SupersessionInfo> $result */
        $result = [];

        foreach ($intake->uploads as $upload) {
            $result[(int) $upload->id] = [
                'superseded' => false,
                'supersession_label' => null,
                'replaced_by_upload_id' => null,
            ];
        }

        $solvingByScope = $this->latestSolvingUploadByScope($intake);

        foreach ($intake->uploads as $upload) {
            $solver = $this->solverForUpload($intake, $upload, $solvingByScope);
            if ($solver === null || (int) $solver['upload']->id === (int) $upload->id) {
                continue;
            }

            // Only mark earlier evidence in the same scope as replaced.
            if ((int) $upload->id >= (int) $solver['upload']->id) {
                continue;
            }

            $uploadRound = $upload->followUpItem?->round?->round_number;
            if (is_numeric($uploadRound) && (int) $uploadRound >= (int) $solver['round_number']) {
                continue;
            }

            $result[(int) $upload->id] = [
                'superseded' => true,
                'supersession_label' => 'Vervangen door ronde '.(int) $solver['round_number'],
                'replaced_by_upload_id' => (int) $solver['upload']->id,
            ];
        }

        // Also honour FollowUpEvidenceReview wrong_subject supersession when a
        // later solving round exists for the same evidence scope.
        $wrongSubjectReview = app(FollowUpEvidenceReview::class)
            ->present($intake, $intake->followUpRounds);

        foreach ($wrongSubjectReview['rounds'] as $presentedRound) {
            foreach ($presentedRound['items'] as $presentedItem) {
                foreach ($presentedItem['uploads'] as $presentedUpload) {
                    if (! $presentedUpload['superseded']) {
                        continue;
                    }

                    /** @var IntakeUpload $upload */
                    $upload = $presentedUpload['upload'];
                    $existing = $result[(int) $upload->id] ?? null;
                    if (is_array($existing) && $existing['superseded'] === true) {
                        continue;
                    }

                    $solver = $this->solverForUpload($intake, $upload, $solvingByScope);
                    $replacementId = $solver !== null ? (int) $solver['upload']->id : null;

                    $result[(int) $upload->id] = [
                        'superseded' => true,
                        'supersession_label' => is_string($presentedUpload['supersession_label'])
                            ? $presentedUpload['supersession_label']
                            : ($solver !== null
                                ? 'Vervangen door ronde '.(int) $solver['round_number']
                                : 'Vervangen door nieuwere ronde'),
                        'replaced_by_upload_id' => $replacementId,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, array{upload: IntakeUpload, round_number: int}>  $solvingByScope
     * @return array{upload: IntakeUpload, round_number: int}|null
     */
    private function solverForUpload(Intake $intake, IntakeUpload $upload, array $solvingByScope): ?array
    {
        $scopeKey = $this->scopeKeyForUpload($intake, $upload);
        if (is_string($scopeKey) && isset($solvingByScope[$scopeKey])) {
            return $solvingByScope[$scopeKey];
        }

        $needle = $this->scopePartsForUpload($intake, $upload);
        if ($needle === null) {
            return null;
        }

        $best = null;
        foreach ($solvingByScope as $key => $solver) {
            $parts = explode('|', $key);
            if (count($parts) < 3) {
                continue;
            }

            [$area, $subjectPart, $photoPart] = $parts;
            if ($area !== $needle['area']) {
                continue;
            }

            if ($photoPart !== 'photo:'.$needle['photo'] && $photoPart !== 'photo:any') {
                continue;
            }

            // Compatible subjects: exact match, or either side is unbounded.
            $compatibleSubject = $subjectPart === $needle['subject']
                || $subjectPart === 'subject:none'
                || $needle['subject'] === 'subject:none';

            if (! $compatibleSubject) {
                continue;
            }

            if ($best === null
                || (int) $solver['round_number'] > (int) $best['round_number']
                || ((int) $solver['round_number'] === (int) $best['round_number']
                    && (int) $solver['upload']->id > (int) $best['upload']->id)) {
                $best = $solver;
            }
        }

        return $best;
    }

    /**
     * @return array{area: string, subject: string, photo: string}|null
     */
    private function scopePartsForUpload(Intake $intake, IntakeUpload $upload): ?array
    {
        $scopeKey = $this->scopeKeyForUpload($intake, $upload);
        if (is_string($scopeKey)) {
            $parts = explode('|', $scopeKey);
            if (count($parts) >= 3) {
                return [
                    'area' => $parts[0],
                    'subject' => $parts[1],
                    'photo' => str_starts_with($parts[2], 'photo:')
                        ? substr($parts[2], strlen('photo:'))
                        : $parts[2],
                ];
            }
        }

        $subject = PhotoSubject::expectedForPhotoQuestion($upload->question_key)
            ?? $upload->contentAssessment()?->expectedSubject();

        if (! $subject instanceof PhotoSubject) {
            return null;
        }

        $areaKey = match ($subject) {
            PhotoSubject::Fusebox => 'power',
            PhotoSubject::Room => 'capacity',
            PhotoSubject::PipeRoute, PhotoSubject::IndoorUnit, PhotoSubject::OutdoorUnit => 'refrigerant',
            PhotoSubject::OutdoorLocation => 'placement',
            default => null,
        };

        if ($areaKey === null) {
            return null;
        }

        return [
            'area' => $areaKey,
            'subject' => 'subject:none',
            'photo' => $subject->value,
        ];
    }

    /**
     * @return array<string, array{upload: IntakeUpload, round_number: int}>
     */
    private function latestSolvingUploadByScope(Intake $intake): array
    {
        $latest = [];

        foreach ($intake->followUpRounds as $round) {
            foreach ($round->items as $item) {
                if ($item->type !== FollowUpItemType::Photo) {
                    continue;
                }

                $scopeKey = $this->evidenceScopeKey($intake, $item);
                if ($scopeKey === null) {
                    continue;
                }

                foreach ($item->uploads as $upload) {
                    if (! $this->isSolvingUpload($upload)) {
                        continue;
                    }

                    $roundNumber = (int) $round->round_number;
                    $existing = $latest[$scopeKey] ?? null;
                    if ($existing === null
                        || $roundNumber > (int) $existing['round_number']
                        || ($roundNumber === (int) $existing['round_number']
                            && (int) $upload->id > (int) $existing['upload']->id)) {
                        $latest[$scopeKey] = [
                            'upload' => $upload,
                            'round_number' => $roundNumber,
                        ];
                    }
                }
            }
        }

        return $latest;
    }

    private function isSolvingUpload(IntakeUpload $upload): bool
    {
        $assessment = $upload->contentAssessment();
        if ($assessment instanceof PhotoContentAssessment
            && $assessment->status() === PhotoContentAssessment::STATUS_OK) {
            return true;
        }

        // Soft: usable follow-up photo without hard mismatch still replaces older proof.
        if ($assessment instanceof PhotoContentAssessment
            && $assessment->status() === PhotoContentAssessment::STATUS_WRONG_SUBJECT) {
            return false;
        }

        return $upload->usability_verdict?->isUsable() === true;
    }

    private function scopeKeyForUpload(Intake $intake, IntakeUpload $upload): ?string
    {
        if ($upload->followUpItem instanceof IntakeFollowUpItem) {
            return $this->evidenceScopeKey($intake, $upload->followUpItem);
        }

        $subject = PhotoSubject::expectedForPhotoQuestion($upload->question_key)
            ?? $upload->contentAssessment()?->expectedSubject();

        if (! $subject instanceof PhotoSubject) {
            return null;
        }

        $areaKey = match ($subject) {
            PhotoSubject::Fusebox => 'power',
            PhotoSubject::Room => 'capacity',
            PhotoSubject::PipeRoute, PhotoSubject::IndoorUnit, PhotoSubject::OutdoorUnit => 'refrigerant',
            PhotoSubject::OutdoorLocation => 'placement',
            default => null,
        };

        if ($areaKey === null) {
            return null;
        }

        $dossierSubjectId = null;
        if (is_string($upload->section_instance_key)
            && preg_match('/^subject-(\d+)$/', $upload->section_instance_key, $matches) === 1) {
            $dossierSubjectId = (int) $matches[1];
        } elseif (is_string($upload->section_instance_key)
            && preg_match('/^room-(\d+)$/', $upload->section_instance_key) === 1) {
            // Room instance keys are not dossier subject ids; keep subject unset so
            // scope still matches area-level follow-ups for the same photo subject.
            $dossierSubjectId = null;
        }

        return implode('|', [
            $areaKey,
            $dossierSubjectId !== null ? 'subject:'.$dossierSubjectId : 'subject:none',
            'photo:'.$subject->value,
        ]);
    }

    private function evidenceScopeKey(Intake $intake, IntakeFollowUpItem $item): ?string
    {
        $task = $intake->contributionTasks
            ->first(static fn (ContributionTask $task): bool => $task->intake_follow_up_item_id === $item->id);

        $areaKey = is_string($task?->decision_area_key) && $task->decision_area_key !== ''
            ? $task->decision_area_key
            : null;

        if ($areaKey === null) {
            return null;
        }

        $subjectId = $task->dossier_subject_id;
        $expected = PhotoSubject::expectedFromDecisionArea($areaKey);

        if ($expected === null) {
            foreach ($item->uploads as $upload) {
                $assessment = $upload->contentAssessment();
                if ($assessment instanceof PhotoContentAssessment
                    && $assessment->expectedSubject() instanceof PhotoSubject) {
                    $expected = $assessment->expectedSubject();
                    break;
                }

                $fromQuestion = PhotoSubject::expectedForPhotoQuestion($upload->question_key);
                if ($fromQuestion instanceof PhotoSubject) {
                    $expected = $fromQuestion;
                    break;
                }
            }
        }

        return implode('|', [
            $areaKey,
            $subjectId !== null ? 'subject:'.$subjectId : 'subject:none',
            $expected instanceof PhotoSubject ? 'photo:'.$expected->value : 'photo:any',
        ]);
    }
}
