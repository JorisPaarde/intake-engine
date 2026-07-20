<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeSection;
use App\Domains\Intake\Models\IntakeUpload;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds a labeled, section-grouped media gallery for the installer detail page (BL-024).
 *
 * Labels come from the intake's pinned template version — no hardcoded airco copy.
 */
final class InstallerPhotoGalleryBuilder
{
    /**
     * @return list<array{
     *     heading: string,
     *     uploads: list<array{upload: IntakeUpload, caption: string}>
     * }>
     */
    public function handle(Intake $intake): array
    {
        $intake->loadMissing(['uploads.followUpItem.round', 'templateVersion.sections.questions']);

        /** @var Collection<int, IntakeUpload> $uploads */
        $uploads = $intake->uploads->sortBy('sort_order')->values();

        if ($uploads->isEmpty()) {
            return [];
        }

        $version = $intake->templateVersion;

        if ($version === null) {
            return $this->ungroupedFallback($uploads);
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

        foreach ($uploads as $upload) {
            if ($upload->followUpItem !== null) {
                $round = $upload->followUpItem->round;
                $bucketKey = 'follow-up|'.$round->round_number;

                if (! isset($groups[$bucketKey])) {
                    $groups[$bucketKey] = [
                        'heading' => 'Aanvulling ronde '.$round->round_number,
                        'sort' => [PHP_INT_MAX - 1, $round->round_number],
                        'uploads' => [],
                    ];
                }

                $groups[$bucketKey]['uploads'][] = [
                    'upload' => $upload,
                    'caption' => $upload->followUpItem->prompt,
                    'question_sort' => $upload->followUpItem->id,
                ];

                continue;
            }

            $meta = $byQuestionKey[$upload->question_key] ?? null;
            $instanceKey = $upload->section_instance_key;

            if ($meta === null) {
                $bucketKey = 'unknown|'.$upload->question_key.'|'.($instanceKey ?? '');
                $heading = $this->captionForUnknown($upload);
                $sectionSort = PHP_INT_MAX;
                $instanceSort = 0;
                $questionLabel = $upload->question_key;
                $questionSort = PHP_INT_MAX;
            } else {
                $section = $meta['section'];
                $question = $meta['question'];
                $bucketKey = $section->key.'|'.($instanceKey ?? '');
                $heading = $this->sectionHeading($section, $instanceKey);
                $sectionSort = (int) $section->sort_order;
                $instanceSort = $this->instanceSortValue($instanceKey);
                $questionLabel = $question->label;
                $questionSort = (int) $question->sort_order;
            }

            if (! isset($groups[$bucketKey])) {
                $groups[$bucketKey] = [
                    'heading' => $heading,
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
                'uploads' => array_map(
                    static fn (array $item): array => [
                        'upload' => $item['upload'],
                        'caption' => $item['caption'],
                    ],
                    $group['uploads'],
                ),
            ];
        }

        return $result;
    }

    private function sectionHeading(IntakeSection $section, ?string $instanceKey): string
    {
        if ($instanceKey === null || $instanceKey === '') {
            return $section->title;
        }

        // Same pattern as IntakeStepBuilder / IntakePrefillResolver: "Ruimtes 2".
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
        if ($upload->section_instance_key) {
            return $upload->question_key.' · '.$upload->section_instance_key;
        }

        return $upload->question_key;
    }

    /**
     * @param  Collection<int, IntakeUpload>  $uploads
     * @return list<array{heading: string, uploads: list<array{upload: IntakeUpload, caption: string}>}>
     */
    private function ungroupedFallback(Collection $uploads): array
    {
        return [[
            'heading' => 'Bestanden',
            'uploads' => $uploads->map(fn (IntakeUpload $upload): array => [
                'upload' => $upload,
                'caption' => $this->captionForUnknown($upload),
            ])->all(),
        ]];
    }
}
