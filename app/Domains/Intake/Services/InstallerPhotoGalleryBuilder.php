<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeFollowUpRound;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeSection;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Support\PhotoContinueAnywayAttention;
use App\Domains\Intake\Support\UploadSupersessionResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds a labeled, section-grouped media gallery for the installer detail page (BL-024).
 *
 * Labels come from the intake's pinned template version — no hardcoded airco copy.
 * Superseded uploads (replaced by a later follow-up round) are marked for the UI.
 */
final class InstallerPhotoGalleryBuilder
{
    /**
     * @return list<array{
     *     heading: string,
     *     anchor: string|null,
     *     uploads: list<array{
     *         upload: IntakeUpload,
     *         caption: string,
     *         superseded: bool,
     *         supersession_label: string|null
     *     }>
     * }>
     */
    public function handle(Intake $intake): array
    {
        $intake->loadMissing([
            'uploads.followUpItem.round',
            'templateVersion.sections.questions',
            'dossierSubjects',
            'aircoRooms',
            'answers',
            'followUpRounds.items.uploads',
            'contributionTasks',
        ]);

        /** @var Collection<int, IntakeUpload> $uploads */
        $uploads = $intake->uploads->sortBy('sort_order')->values();

        if ($uploads->isEmpty()) {
            return [];
        }

        $supersessions = app(UploadSupersessionResolver::class)->resolve($intake);

        $version = $intake->templateVersion;

        if ($version === null) {
            return $this->ungroupedFallback($intake, $uploads, $supersessions);
        }

        /** @var array<string, array{question: IntakeQuestion, section: IntakeSection}> $byQuestionKey */
        $byQuestionKey = [];

        foreach ($version->sections as $section) {
            foreach ($section->questions as $question) {
                $byQuestionKey[$question->key] = [
                    'question' => $question,
                    'section' => $section,
                ];
            }
        }

        /** @var array<string, mixed> $groups */
        $groups = [];
        /** @var Collection<int, DossierSubject> $dossierSubjects */
        $dossierSubjects = $intake->dossierSubjects->keyBy('id');

        foreach ($uploads as $upload) {
            if ($upload->question_key === 'installer_evidence') {
                $subjectId = $this->dossierSubjectId($upload->section_instance_key);
                $subject = $subjectId !== null ? $dossierSubjects->get($subjectId) : null;
                $bucketKey = 'installer-evidence|'.($subjectId ?? 'unknown');

                if (! isset($groups[$bucketKey])) {
                    $groups[$bucketKey] = [
                        'heading' => $subject->label ?? 'Dossierbewijs',
                        'anchor' => null,
                        'sort' => [PHP_INT_MAX - 2, $subjectId ?? PHP_INT_MAX],
                        'uploads' => [],
                    ];
                }

                $groups[$bucketKey]['uploads'][] = [
                    'upload' => $upload,
                    'caption' => 'Dossierfoto',
                    'question_sort' => $upload->sort_order,
                ];

                continue;
            }

            if ($upload->followUpItem !== null) {
                $round = $upload->followUpItem->round;
                $bucketKey = 'follow-up|'.$round->round_number;

                if (! isset($groups[$bucketKey])) {
                    $groups[$bucketKey] = [
                        'heading' => 'Aanvulling ronde '.$round->round_number,
                        'anchor' => null,
                        'sort' => [PHP_INT_MAX - 1, $round->round_number],
                        'uploads' => [],
                    ];
                }

                $groups[$bucketKey]['uploads'][] = [
                    'upload' => $upload,
                    'caption' => $this->followUpCaption($intake, $upload->followUpItem, $round),
                    'question_sort' => $upload->followUpItem->id,
                ];

                continue;
            }

            $meta = $byQuestionKey[$upload->question_key] ?? null;
            $instanceKey = $upload->section_instance_key;

            if ($meta === null) {
                $bucketKey = 'unknown|'.$upload->question_key.'|'.($instanceKey ?? '');
                $heading = $this->captionForUnknown($intake, $upload);
                $anchor = PhotoContinueAnywayAttention::galleryAnchor(null, $instanceKey);
                $sectionSort = PHP_INT_MAX;
                $instanceSort = 0;
                $questionLabel = $upload->question_key;
                $questionSort = PHP_INT_MAX;
            } else {
                $section = $meta['section'];
                $question = $meta['question'];
                $bucketKey = $section->key.'|'.($instanceKey ?? '');
                $heading = $this->sectionHeading($intake, $section, $instanceKey);
                $anchor = PhotoContinueAnywayAttention::galleryAnchor($section->key, $instanceKey);
                $sectionSort = (int) $section->sort_order;
                $instanceSort = $this->instanceSortValue($instanceKey);
                $questionLabel = $question->label;
                $questionSort = (int) $question->sort_order;
            }

            if (! isset($groups[$bucketKey])) {
                $groups[$bucketKey] = [
                    'heading' => $heading,
                    'anchor' => $anchor,
                    'sort' => [$sectionSort, $instanceSort],
                    'uploads' => [],
                ];
            }

            $groups[$bucketKey]['uploads'][] = [
                'upload' => $upload,
                'caption' => $this->captionWithRoomPlace($intake, $questionLabel, $instanceKey),
                'question_sort' => $questionSort,
            ];
        }

        uasort($groups, static function (array $a, array $b): int {
            return $a['sort'] <=> $b['sort'];
        });

        $result = [];

        foreach ($groups as $group) {
            usort(
                $group['uploads'],
                static function (array $a, array $b): int {
                    $byQuestion = $a['question_sort'] <=> $b['question_sort'];

                    if ($byQuestion !== 0) {
                        return $byQuestion;
                    }

                    return $a['upload']->sort_order <=> $b['upload']->sort_order;
                },
            );

            $result[] = [
                'heading' => $group['heading'],
                'anchor' => $group['anchor'] ?? null,
                'uploads' => array_map(
                    static function (array $item) use ($supersessions): array {
                        $uploadId = (int) $item['upload']->id;
                        $info = $supersessions[$uploadId] ?? null;

                        return [
                            'upload' => $item['upload'],
                            'caption' => $item['caption'],
                            'superseded' => is_array($info) && $info['superseded'] === true,
                            'supersession_label' => is_array($info)
                                ? $info['supersession_label']
                                : null,
                        ];
                    },
                    $group['uploads'],
                ),
            ];
        }

        return $result;
    }

    /**
     * 1-based gallery order per upload within (question_key, section_instance_key).
     *
     * Counts every upload in that scope (including wrong-subject / replaced /
     * ineligible), sorted by sort_order then id — same ordering the gallery uses
     * within a question. Callers that filter for AI evidence still get the gallery
     * number of the surviving photo (e.g. rejected #1 + new #2 → new is 2).
     *
     * @return array<int, int> upload_id => order
     */
    public function photoOrdersByUploadId(Intake $intake): array
    {
        $intake->loadMissing(['uploads']);

        /** @var array<string, list<IntakeUpload>> $groups */
        $groups = [];

        $sorted = $intake->uploads
            ->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        foreach ($sorted as $upload) {
            $groupKey = $upload->question_key.'|'.(string) ($upload->section_instance_key ?? '');
            $groups[$groupKey][] = $upload;
        }

        /** @var array<int, int> $orders */
        $orders = [];
        foreach ($groups as $uploads) {
            $order = 1;
            foreach ($uploads as $upload) {
                $orders[(int) $upload->id] = $order;
                $order++;
            }
        }

        return $orders;
    }

    /**
     * Samenvatting onder "Foto’s en bestanden" (alleen niet-vervangen fotobestanden).
     */
    public function summaryLine(Intake $intake): string
    {
        $intake->loadMissing([
            'uploads.followUpItem.round',
            'followUpRounds.items.uploads',
            'contributionTasks',
            'dossierSubjects.records',
        ]);

        $supersessions = app(UploadSupersessionResolver::class)->resolve($intake);
        /** @var list<IntakeUpload> $currentPhotos */
        $currentPhotos = [];

        foreach ($intake->uploads as $upload) {
            if (! str_starts_with((string) $upload->mime_type, 'image/')) {
                continue;
            }

            $info = $supersessions[(int) $upload->id] ?? null;
            if (is_array($info) && $info['superseded'] === true) {
                continue;
            }

            $currentPhotos[] = $upload;
        }

        $counts = PhotoContinueAnywayAttention::countStatuses($currentPhotos);
        if ($counts['total'] === 0) {
            return 'Nog geen foto’s · tik om te openen';
        }

        $photoWord = $counts['total'] === 1 ? 'foto' : 'foto’s';
        if ($counts['rejected'] > 0) {
            return "{$counts['total']} {$photoWord} · {$counts['rejected']} afgekeurd door de AI · tik om te openen";
        }

        return "{$counts['total']} {$photoWord} · tik om te openen";
    }

    private function dossierSubjectId(?string $instanceKey): ?int
    {
        if (! is_string($instanceKey)
            || preg_match('/^subject-(\d+)$/', $instanceKey, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function sectionHeading(Intake $intake, IntakeSection $section, ?string $instanceKey): string
    {
        if ($instanceKey === null || $instanceKey === '') {
            return $section->title;
        }

        if (str_starts_with($instanceKey, 'room-')) {
            return PhotoContinueAnywayAttention::roomPlaceLabel($intake, $instanceKey);
        }

        // Niet-ruimte-instanties: sectietitel + volgnummer.
        return $section->title.' '.Str::afterLast($instanceKey, '-');
    }

    private function instanceSortValue(?string $instanceKey): int
    {
        if ($instanceKey === null || $instanceKey === '') {
            return 0;
        }

        $suffix = Str::afterLast($instanceKey, '-');

        return is_numeric($suffix) ? (int) $suffix : 0;
    }

    private function captionWithRoomPlace(Intake $intake, string $base, ?string $instanceKey): string
    {
        if (! is_string($instanceKey) || ! str_starts_with($instanceKey, 'room-')) {
            return $base;
        }

        $place = PhotoContinueAnywayAttention::roomPlaceLabel($intake, $instanceKey);
        if ($place === '' || str_contains($base, $place)) {
            return $base;
        }

        return $base.' · '.$place;
    }

    /**
     * N43: "Ronde N · Stroomtoevoer · Plek stroomkabel" i.p.v. alleen "Ronde N".
     */
    private function followUpCaption(
        Intake $intake,
        IntakeFollowUpItem $item,
        IntakeFollowUpRound $round,
    ): string {
        $parts = ['Ronde '.$round->round_number];

        $task = $intake->contributionTasks
            ->first(static fn (ContributionTask $candidate): bool => $candidate->intake_follow_up_item_id === $item->id);

        if ($task instanceof ContributionTask) {
            $areaKey = $task->decision_area_key;
            if (is_string($areaKey) && $areaKey !== '' && DecisionReadinessService::hasArea($areaKey)) {
                $parts[] = DecisionReadinessService::areaLabel($areaKey);
            }

            $prompt = trim($task->prompt);
            if ($prompt === '') {
                $prompt = trim($item->prompt);
            }
            if ($prompt !== '') {
                $parts[] = Str::limit($prompt, 48, '…');
            }
        } else {
            $prompt = trim($item->prompt);
            if ($prompt !== '') {
                $parts[] = Str::limit($prompt, 48, '…');
            }
        }

        return implode(' · ', $parts);
    }

    private function captionForUnknown(Intake $intake, IntakeUpload $upload): string
    {
        // drain_photo deelt expected OutdoorLocation met buitenplek; label via question key.
        if ($upload->question_key === 'drain_photo') {
            $base = 'Afvoerfoto';
        } else {
            $subject = PhotoSubject::expectedForPhotoQuestion($upload->question_key)
                ?? $upload->contentAssessment()?->expectedSubject();

            $base = match ($subject) {
                PhotoSubject::Fusebox => 'Meterkastfoto',
                PhotoSubject::Room => 'Ruimtefoto',
                PhotoSubject::OutdoorUnit, PhotoSubject::OutdoorLocation => 'Foto van de plek voor de buitenunit',
                PhotoSubject::PipeRoute => 'Leidingroutefoto',
                PhotoSubject::IndoorUnit => 'Binnenunitfoto',
                default => 'Foto',
            };
        }

        $base = $this->captionWithRoomPlace($intake, $base, $upload->section_instance_key);

        if ($upload->followUpItem?->round !== null) {
            return $base.' (ronde '.(int) $upload->followUpItem->round->round_number.')';
        }

        return $base;
    }

    /**
     * @param  Collection<int, IntakeUpload>  $uploads
     * @param  array<int, array{superseded: bool, supersession_label: string|null, replaced_by_upload_id: int|null}>  $supersessions
     * @return list<array{heading: string, anchor: string|null, uploads: list<array{upload: IntakeUpload, caption: string, superseded: bool, supersession_label: string|null}>}>
     */
    private function ungroupedFallback(Intake $intake, Collection $uploads, array $supersessions): array
    {
        return [[
            'heading' => 'Bestanden',
            'anchor' => null,
            'uploads' => $uploads->map(function (IntakeUpload $upload) use ($intake, $supersessions): array {
                $info = $supersessions[(int) $upload->id] ?? null;

                return [
                    'upload' => $upload,
                    'caption' => $this->captionForUnknown($intake, $upload),
                    'superseded' => is_array($info) && $info['superseded'] === true,
                    'supersession_label' => is_array($info)
                        ? $info['supersession_label']
                        : null,
                ];
            })->all(),
        ]];
    }
}
