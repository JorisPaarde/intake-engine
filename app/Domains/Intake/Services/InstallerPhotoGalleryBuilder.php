<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
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
            return $this->ungroupedFallback($uploads, $supersessions);
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
                    'caption' => 'Ronde '.$round->round_number,
                    'question_sort' => $upload->followUpItem->id,
                ];

                continue;
            }

            $meta = $byQuestionKey[$upload->question_key] ?? null;
            $instanceKey = $upload->section_instance_key;

            if ($meta === null) {
                $bucketKey = 'unknown|'.$upload->question_key.'|'.($instanceKey ?? '');
                $heading = $this->captionForUnknown($upload);
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
                'caption' => $questionLabel,
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

    private function captionForUnknown(IntakeUpload $upload): string
    {
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
    private function ungroupedFallback(Collection $uploads, array $supersessions): array
    {
        return [[
            'heading' => 'Bestanden',
            'anchor' => null,
            'uploads' => $uploads->map(function (IntakeUpload $upload) use ($supersessions): array {
                $info = $supersessions[(int) $upload->id] ?? null;

                return [
                    'upload' => $upload,
                    'caption' => $this->captionForUnknown($upload),
                    'superseded' => is_array($info) && $info['superseded'] === true,
                    'supersession_label' => is_array($info)
                        ? $info['supersession_label']
                        : null,
                ];
            })->all(),
        ]];
    }
}
