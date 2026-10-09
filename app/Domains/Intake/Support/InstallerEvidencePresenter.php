<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\AI\Services\IntakeAttentionContextBuilder;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\DossierRecordStatus;
use Illuminate\Support\Collection;

/**
 * Presenteert bewijscitaties voor de installateur: leesbare NL-labels + links,
 * nooit ruwe interne sleutels. Markeert vervangen bewijs en prefereert actuele bronnen.
 */
final class InstallerEvidencePresenter
{
    /**
     * @param  list<array{source_type?: mixed, reference?: mixed}>|null  $evidence
     * @return list<array{
     *     label: string,
     *     url: string|null,
     *     superseded: bool,
     *     supersession_label: string|null,
     *     upload_id: int|null,
     *     testid: string
     * }>
     */
    public function presentAttentionEvidence(Intake $intake, ?array $evidence): array
    {
        if ($evidence === null || $evidence === []) {
            return [];
        }

        $intake->loadMissing([
            'uploads.followUpItem.round',
            'externalFacts',
            'answers',
            'templateVersion.sections.questions',
            'followUpRounds.items.uploads',
            'contributionTasks',
            'dossierSubjects.records',
        ]);

        $supersessions = app(UploadSupersessionResolver::class)->resolve($intake);
        $presented = [];

        foreach ($evidence as $item) {
            $sourceType = is_string($item['source_type'] ?? null) ? $item['source_type'] : '';
            $reference = is_string($item['reference'] ?? null) ? trim($item['reference']) : '';

            if ($reference === '') {
                $presented[] = $this->citation(
                    $this->genericSourceLabel($sourceType),
                    null,
                    false,
                    null,
                    null,
                );

                continue;
            }

            $presented[] = match ($sourceType) {
                'external_fact' => $this->presentExternalFactCitation($intake, $reference, $supersessions),
                'upload' => $this->presentUploadCitation($intake, $reference, $supersessions),
                'follow_up' => $this->presentFollowUpCitation($intake, $reference, $supersessions),
                'answer' => $this->presentAnswerCitation($intake, $reference),
                'pipe_route' => $this->citation('Leidingroute', null, false, null, null),
                'installer_review' => $this->citation('Installateursbeoordeling', null, false, null, null),
                'system_attention_point' => $this->citation('Systeemsignaal', null, false, null, null),
                default => $this->presentLooseReference($intake, $reference, $supersessions, $sourceType),
            };
        }

        return $presented;
    }

    /**
     * @param  list<string>|mixed  $references
     * @return list<array{
     *     label: string,
     *     url: string|null,
     *     superseded: bool,
     *     supersession_label: string|null,
     *     upload_id: int|null,
     *     testid: string
     * }>
     */
    public function presentSynthesisReferences(Intake $intake, mixed $references): array
    {
        if (! is_array($references) || $references === []) {
            return [];
        }

        $intake->loadMissing([
            'uploads.followUpItem.round',
            'templateVersion.sections.questions',
            'followUpRounds.items.uploads',
            'contributionTasks',
            'dossierSubjects.records',
        ]);

        $supersessions = app(UploadSupersessionResolver::class)->resolve($intake);
        $presented = [];

        foreach ($references as $reference) {
            if (! is_string($reference) || trim($reference) === '') {
                continue;
            }

            $presented[] = $this->presentLooseReference($intake, trim($reference), $supersessions, 'upload');
        }

        return $presented;
    }

    /**
     * @param  array<int, array{superseded: bool, supersession_label: string|null, replaced_by_upload_id: int|null}>  $supersessions
     * @return array{label: string, url: string|null, superseded: bool, supersession_label: string|null, upload_id: int|null, testid: string}
     */
    private function presentExternalFactCitation(Intake $intake, string $reference, array $supersessions): array
    {
        $factKey = $this->factKeyFromReference($reference);
        $fact = $this->resolveFact($intake, $reference, $factKey);

        if (! $fact instanceof IntakeExternalFact) {
            return $this->citation(
                $this->factKeyLabel($factKey) ?? 'Extern feit',
                null,
                false,
                null,
                null,
            );
        }

        /** @var list<mixed> $rawUploadIds */
        $rawUploadIds = is_array($fact->value['upload_ids'] ?? null)
            ? $fact->value['upload_ids']
            : [];
        $uploadIds = collect($rawUploadIds)
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (mixed $id): int => (int) $id)
            ->values();

        $preferredUpload = $this->preferCurrentUpload($intake, $uploadIds, $supersessions);
        $citedUpload = $uploadIds->isNotEmpty()
            ? $intake->uploads->firstWhere('id', (int) $uploadIds->first())
            : null;

        $superseded = false;
        $supersessionLabel = null;

        // Prefer linking to current evidence; only mark superseded when we cannot resolve a replacement.
        if ($preferredUpload instanceof IntakeUpload
            && array_key_exists($preferredUpload->id, $supersessions)
            && $supersessions[$preferredUpload->id]['superseded'] === true) {
            $replacementId = $supersessions[$preferredUpload->id]['replaced_by_upload_id'];
            if (is_int($replacementId)) {
                $replacement = $intake->uploads->firstWhere('id', $replacementId);
                if ($replacement instanceof IntakeUpload) {
                    $preferredUpload = $replacement;
                } else {
                    $superseded = true;
                    $supersessionLabel = $supersessions[$preferredUpload->id]['supersession_label']
                        ?? 'Vervangen door nieuwer bewijs';
                }
            } else {
                $superseded = true;
                $supersessionLabel = $supersessions[$preferredUpload->id]['supersession_label']
                    ?? 'Vervangen door nieuwer bewijs';
            }
        } elseif ($citedUpload instanceof IntakeUpload
            && $preferredUpload instanceof IntakeUpload
            && $preferredUpload->id !== $citedUpload->id
            && array_key_exists($citedUpload->id, $supersessions)
            && $supersessions[$citedUpload->id]['superseded'] === true) {
            // Citation resolved to newer upload — show current without stale marker.
            $superseded = false;
            $supersessionLabel = null;
        }

        $recordSuperseded = $this->dossierRecordSupersededForFact($intake, $fact);
        if ($recordSuperseded !== null && ! ($preferredUpload instanceof IntakeUpload)) {
            $superseded = true;
            $supersessionLabel = $recordSuperseded;
        }

        $label = $this->uploadLabel($intake, $preferredUpload)
            ?? $this->humanFactLabel($fact);

        return $this->citation(
            $label,
            $preferredUpload instanceof IntakeUpload
                ? route('installer.uploads.show', [$intake, $preferredUpload])
                : null,
            $superseded,
            $supersessionLabel,
            $preferredUpload?->id,
        );
    }

    /**
     * @param  array<int, array{superseded: bool, supersession_label: string|null, replaced_by_upload_id: int|null}>  $supersessions
     * @return array{label: string, url: string|null, superseded: bool, supersession_label: string|null, upload_id: int|null, testid: string}
     */
    private function presentUploadCitation(Intake $intake, string $reference, array $supersessions): array
    {
        $upload = $this->resolveUploadFromOpaqueReference($intake, $reference)
            ?? $this->resolveUploadFromLooseReference($intake, $reference);

        if (! $upload instanceof IntakeUpload) {
            $questionKey = $this->questionKeyFromUploadReference($reference);

            return $this->citation(
                $this->questionLabel($intake, $questionKey) ?? 'Foto',
                null,
                false,
                null,
                null,
            );
        }

        return $this->presentResolvedUpload($intake, $upload, $supersessions);
    }

    /**
     * @param  array<int, array{superseded: bool, supersession_label: string|null, replaced_by_upload_id: int|null}>  $supersessions
     * @return array{label: string, url: string|null, superseded: bool, supersession_label: string|null, upload_id: int|null, testid: string}
     */
    private function presentFollowUpCitation(Intake $intake, string $reference, array $supersessions): array
    {
        $item = $this->resolveFollowUpItem($intake, $reference);
        if ($item instanceof IntakeFollowUpItem) {
            $upload = $item->uploads->sortByDesc('id')->first();
            if ($upload instanceof IntakeUpload) {
                return $this->presentResolvedUpload($intake, $upload, $supersessions);
            }

            $round = $item->round;
            $label = 'Aanvulling (ronde '.(int) $round->round_number.')';

            return $this->citation($label, null, false, null, null);
        }

        $upload = $this->resolveUploadFromOpaqueReference($intake, $reference, 'follow_up_upload')
            ?? $this->resolveUploadFromLooseReference($intake, $reference);

        if ($upload instanceof IntakeUpload) {
            return $this->presentResolvedUpload($intake, $upload, $supersessions);
        }

        return $this->citation('Aanvulling', null, false, null, null);
    }

    /**
     * @return array{label: string, url: string|null, superseded: bool, supersession_label: string|null, upload_id: int|null, testid: string}
     */
    private function presentAnswerCitation(Intake $intake, string $reference): array
    {
        $answer = $this->resolveAnswer($intake, $reference);
        $questionKey = $answer instanceof IntakeAnswer
            ? $answer->question_key
            : $this->questionKeyFromAnswerReference($reference);
        $questionLabel = $this->questionLabel($intake, $questionKey);

        $label = $questionLabel !== null
            ? 'Antwoord: '.$questionLabel
            : 'Klantantwoord';

        return $this->citation($label, null, false, null, null);
    }

    /**
     * @param  array<int, array{superseded: bool, supersession_label: string|null, replaced_by_upload_id: int|null}>  $supersessions
     * @return array{label: string, url: string|null, superseded: bool, supersession_label: string|null, upload_id: int|null, testid: string}
     */
    private function presentLooseReference(
        Intake $intake,
        string $reference,
        array $supersessions,
        string $fallbackSourceType,
    ): array {
        if (preg_match('/^(?:dossier_image|intake_upload|route_image):(\d+)$/', $reference, $matches) === 1) {
            $upload = $intake->uploads->firstWhere('id', (int) $matches[1]);
            if ($upload instanceof IntakeUpload) {
                return $this->presentResolvedUpload($intake, $upload, $supersessions);
            }

            return $this->citation('Foto', null, false, null, null);
        }

        if (str_contains($reference, '@fact:') || str_starts_with($reference, 'fact:')) {
            return $this->presentExternalFactCitation($intake, $reference, $supersessions);
        }

        if (str_contains($reference, '@upload:') || str_contains($reference, 'upload:')) {
            return $this->presentUploadCitation($intake, $reference, $supersessions);
        }

        if (str_contains($reference, '@follow_up') || str_starts_with($reference, 'round_')) {
            return $this->presentFollowUpCitation($intake, $reference, $supersessions);
        }

        if (str_contains($reference, '@') || ! str_contains($reference, ':')) {
            return $this->presentAnswerCitation($intake, $reference);
        }

        return $this->citation($this->genericSourceLabel($fallbackSourceType), null, false, null, null);
    }

    /**
     * @param  array<int, array{superseded: bool, supersession_label: string|null, replaced_by_upload_id: int|null}>  $supersessions
     * @return array{label: string, url: string|null, superseded: bool, supersession_label: string|null, upload_id: int|null, testid: string}
     */
    private function presentResolvedUpload(Intake $intake, IntakeUpload $upload, array $supersessions): array
    {
        $info = $supersessions[$upload->id] ?? null;
        $displayUpload = $upload;
        $superseded = false;
        $supersessionLabel = null;

        if (is_array($info) && $info['superseded'] === true) {
            $replacementId = $info['replaced_by_upload_id'];
            if (is_int($replacementId)) {
                $replacement = $intake->uploads->firstWhere('id', $replacementId);
                if ($replacement instanceof IntakeUpload) {
                    // Prefer current evidence in citations.
                    $displayUpload = $replacement;
                } else {
                    $superseded = true;
                    $supersessionLabel = $info['supersession_label'] ?? 'Vervangen door nieuwer bewijs';
                }
            } else {
                $superseded = true;
                $supersessionLabel = $info['supersession_label'] ?? 'Vervangen door nieuwer bewijs';
            }
        }

        return $this->citation(
            $this->uploadLabel($intake, $displayUpload) ?? 'Foto',
            route('installer.uploads.show', [$intake, $displayUpload]),
            $superseded,
            $supersessionLabel,
            $displayUpload->id,
        );
    }

    /**
     * @param  Collection<int, int>  $uploadIds
     * @param  array<int, array{superseded: bool, supersession_label: string|null, replaced_by_upload_id: int|null}>  $supersessions
     */
    private function preferCurrentUpload(Intake $intake, Collection $uploadIds, array $supersessions): ?IntakeUpload
    {
        if ($uploadIds->isEmpty()) {
            return null;
        }

        foreach ($uploadIds->reverse() as $id) {
            $info = $supersessions[$id] ?? null;
            if (is_array($info) && $info['superseded'] === true) {
                $replacementId = $info['replaced_by_upload_id'];
                if (is_int($replacementId)) {
                    $replacement = $intake->uploads->firstWhere('id', $replacementId);
                    if ($replacement instanceof IntakeUpload) {
                        return $replacement;
                    }
                }

                continue;
            }

            $upload = $intake->uploads->firstWhere('id', $id);
            if ($upload instanceof IntakeUpload) {
                return $upload;
            }
        }

        $first = $intake->uploads->firstWhere('id', (int) $uploadIds->first());

        return $first instanceof IntakeUpload ? $first : null;
    }

    private function resolveFact(Intake $intake, string $reference, ?string $factKey): ?IntakeExternalFact
    {
        foreach ($intake->externalFacts as $fact) {
            $opaque = IntakeAttentionContextBuilder::opaqueReference('fact', $fact->id);
            if (hash_equals($opaque, $reference) || str_ends_with($reference, '@fact:'.$opaque)) {
                return $fact;
            }
        }

        if ($factKey === null || $factKey === '') {
            return null;
        }

        // Stale opaque hash after reassessment: prefer the current fact with the same key.
        return $intake->externalFacts->first(
            static fn (IntakeExternalFact $fact): bool => $fact->fact_key === $factKey,
        );
    }

    private function resolveUploadFromOpaqueReference(
        Intake $intake,
        string $reference,
        string $type = 'upload',
    ): ?IntakeUpload {
        foreach ($intake->uploads as $upload) {
            $opaque = IntakeAttentionContextBuilder::opaqueReference($type, $upload->id);
            if (str_ends_with($reference, $opaque) || str_contains($reference, $type.':'.$opaque)) {
                return $upload;
            }

            // Also match follow_up_upload opaque tokens.
            if (str_ends_with($reference, IntakeAttentionContextBuilder::opaqueReference('follow_up_upload', $upload->id))) {
                return $upload;
            }
        }

        return null;
    }

    private function resolveUploadFromLooseReference(Intake $intake, string $reference): ?IntakeUpload
    {
        if (preg_match('/(?:dossier_image|intake_upload|route_image|upload):(\d+)/', $reference, $matches) === 1) {
            $upload = $intake->uploads->firstWhere('id', (int) $matches[1]);

            return $upload instanceof IntakeUpload ? $upload : null;
        }

        $questionKey = $this->questionKeyFromUploadReference($reference);
        if ($questionKey === null) {
            return null;
        }

        $subject = PhotoSubject::expectedForPhotoQuestion($questionKey);
        $candidates = $intake->uploads
            ->filter(static function (IntakeUpload $upload) use ($questionKey, $subject): bool {
                if ($upload->question_key === $questionKey) {
                    return true;
                }

                if (! $subject instanceof PhotoSubject) {
                    return false;
                }

                $expected = PhotoSubject::expectedForPhotoQuestion($upload->question_key);

                return $expected === $subject;
            })
            ->sortByDesc('id');

        $current = $candidates->first(
            static fn (IntakeUpload $upload): bool => $upload->contentAssessment()?->status()
                === PhotoContentAssessment::STATUS_OK
                || $upload->usability_verdict?->isUsable(),
        );

        return $current ?? $candidates->first();
    }

    private function resolveFollowUpItem(Intake $intake, string $reference): ?IntakeFollowUpItem
    {
        foreach ($intake->followUpRounds as $round) {
            foreach ($round->items as $item) {
                if (str_ends_with($reference, IntakeAttentionContextBuilder::opaqueReference('item', $item->id))) {
                    return $item;
                }
            }
        }

        return null;
    }

    private function resolveAnswer(Intake $intake, string $reference): ?IntakeAnswer
    {
        foreach ($intake->answers as $answer) {
            $expected = $answer->section_instance_key === null
                ? $answer->question_key
                : $answer->question_key.'@section:'.$answer->section_instance_key;

            if (hash_equals($expected, $reference)
                || hash_equals($answer->question_key, $reference)) {
                return $answer;
            }
        }

        return null;
    }

    private function uploadLabel(Intake $intake, ?IntakeUpload $upload): ?string
    {
        if (! $upload instanceof IntakeUpload) {
            return null;
        }

        $base = $this->questionLabel($intake, $upload->question_key)
            ?? $this->subjectPhotoLabel($upload)
            ?? 'Foto';

        $round = $upload->followUpItem?->round;
        if ($round !== null) {
            return $base.' (ronde '.(int) $round->round_number.')';
        }

        return $base;
    }

    private function subjectPhotoLabel(IntakeUpload $upload): ?string
    {
        $subject = PhotoSubject::expectedForPhotoQuestion($upload->question_key)
            ?? $upload->contentAssessment()?->expectedSubject();

        if (! $subject instanceof PhotoSubject) {
            return null;
        }

        return match ($subject) {
            PhotoSubject::Fusebox => 'Meterkastfoto',
            PhotoSubject::Room => 'Ruimtefoto',
            PhotoSubject::OutdoorUnit => 'Buitenunitfoto',
            PhotoSubject::OutdoorLocation => 'Buitenplekfoto',
            PhotoSubject::PipeRoute => 'Leidingroutefoto',
            PhotoSubject::IndoorUnit => 'Binnenunitfoto',
            PhotoSubject::Other => 'Foto',
        };
    }

    private function humanFactLabel(IntakeExternalFact $fact): string
    {
        if ($fact->fact_key === 'fusebox_photo_assessment') {
            return 'Meterkastfoto';
        }

        $label = trim($fact->label);

        return $label !== '' ? $label : ($this->factKeyLabel($fact->fact_key) ?? 'Extern feit');
    }

    private function factKeyLabel(?string $factKey): ?string
    {
        if ($factKey === null || $factKey === '') {
            return null;
        }

        return match ($factKey) {
            'fusebox_photo_assessment' => 'Meterkastfoto',
            'building_year' => 'Bouwjaar',
            'energy_label' => 'Energielabel',
            'building_height_m' => 'Gebouwhoogte',
            'roof_type' => 'Daktype',
            'floor_count' => 'Aantal verdiepingen',
            default => str_ends_with($factKey, '_derivation') || str_contains($factKey, '_derivation::')
                ? 'Fotobeoordeling'
                : null,
        };
    }

    private function factKeyFromReference(string $reference): ?string
    {
        if (preg_match('/^([a-z0-9_.:-]+)@fact:/i', $reference, $matches) === 1) {
            return $matches[1];
        }

        // Older AI proposals sometimes stored only the fact_key as reference.
        if (preg_match('/^[a-z0-9_.:-]+$/i', $reference) === 1) {
            return $reference;
        }

        return null;
    }

    private function questionKeyFromUploadReference(string $reference): ?string
    {
        if (preg_match('/^([a-z0-9_]+)(?:@section:[^@]+)?@upload:/i', $reference, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^([a-z0-9_]+)$/i', $reference, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function questionKeyFromAnswerReference(string $reference): ?string
    {
        if (preg_match('/^([a-z0-9_]+)(?:@section:.+)?$/i', $reference, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function questionLabel(Intake $intake, ?string $questionKey): ?string
    {
        if ($questionKey === null || $questionKey === '') {
            return null;
        }

        $intake->loadMissing(['templateVersion.sections.questions']);

        $version = $intake->templateVersion;
        if ($version === null) {
            return null;
        }

        foreach ($version->sections as $section) {
            /** @var IntakeQuestion $question */
            foreach ($section->questions as $question) {
                if ($question->key === $questionKey) {
                    $label = trim((string) $question->label);

                    return $label !== '' ? $label : null;
                }
            }
        }

        return match ($questionKey) {
            'fusebox_photo', 'fusebox_photo_extra' => 'Meterkastfoto',
            'room_photos' => 'Ruimtefoto',
            'outdoor_location_photos', 'around_house_photos' => 'Buitenplekfoto',
            'pipe_route_photos' => 'Leidingroutefoto',
            'ceiling_height_m', 'room_height_m' => 'hoogte plafond',
            default => null,
        };
    }

    private function dossierRecordSupersededForFact(Intake $intake, IntakeExternalFact $fact): ?string
    {
        foreach ($intake->dossierSubjects as $subject) {
            foreach ($subject->records as $record) {
                if ($record->source_type !== 'intake_external_fact'
                    || (int) $record->source_id !== (int) $fact->id) {
                    continue;
                }

                if ($record->status === DossierRecordStatus::Superseded
                    || $record->superseded_by_id !== null) {
                    return 'Vervangen door nieuwer bewijs';
                }
            }
        }

        return null;
    }

    private function genericSourceLabel(string $sourceType): string
    {
        return match ($sourceType) {
            'answer' => 'Klantantwoord',
            'external_fact' => 'Extern feit',
            'upload' => 'Foto',
            'follow_up' => 'Aanvulling',
            'installer_review' => 'Installateursbeoordeling',
            'pipe_route' => 'Leidingroute',
            'system_attention_point' => 'Systeemsignaal',
            default => 'Dossierbron',
        };
    }

    /**
     * @return array{label: string, url: string|null, superseded: bool, supersession_label: string|null, upload_id: int|null, testid: string}
     */
    private function citation(
        string $label,
        ?string $url,
        bool $superseded,
        ?string $supersessionLabel,
        ?int $uploadId,
    ): array {
        // Never leak internal keys into the installer UI.
        if ($this->looksLikeInternalKey($label)) {
            $label = 'Dossierbron';
        }

        return [
            'label' => $label,
            'url' => $url,
            'superseded' => $superseded,
            'supersession_label' => $supersessionLabel,
            'upload_id' => $uploadId,
            'testid' => 'evidence-citation',
        ];
    }

    private function looksLikeInternalKey(string $label): bool
    {
        return str_contains($label, '@fact:')
            || str_contains($label, '@upload:')
            || str_contains($label, 'dossier_image:')
            || str_contains($label, 'intake_upload:')
            || preg_match('/^(fact|upload|follow_up_upload|item|route)_[a-f0-9]{12,}$/', $label) === 1
            || preg_match('/^[a-z0-9_]+@[a-z0-9_.:-]+$/i', $label) === 1;
    }
}
