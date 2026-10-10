{{--
  Gedeelde fotovraag-UI voor standalone @case('photo') én question_group.
  Eén status per tegel + één melding per vraag (UX scout brief 10 okt 2026).
  Verwacht uit de parent: uploadsByQuestion, token, uploadPhase*, pendingAssessUploadIds,
  assessmentUiReleased, photoMismatchAssessment, photoNeedsOverride, displayPhotoHint,
  showMissing, photoNeedsQualityHint, maxUploadKb, uploadHardMax*.
--}}
@php
    /** @var \App\Domains\Intake\Models\IntakeQuestion $question */
    /** @var string $composite */
    $showQuestionLabel = $showQuestionLabel ?? false;
    $fieldRequired = (bool) ($fieldRequired ?? false);
    $wrapperTestId = $wrapperTestId ?? null;
    $existingUploads = $uploadsByQuestion[$question->key] ?? collect();
    $maxFiles = (int) ($question->meta['max_files'] ?? config('intake.uploads.max_files_per_question', 5));
    $remainingSlots = max(0, $maxFiles - $existingUploads->count());

    $assessmentPollPending = ! empty($pendingAssessUploadIds[$composite] ?? []);
    $assessmentPollActive = (string) ($uploadPhase ?? '') === 'assessing'
        && (string) ($uploadPhaseComposite ?? '') === $composite;
    $assessmentQuietPoll = in_array($composite, $assessmentUiReleased ?? [], true);
    $assessmentPollInterval = $assessmentQuietPoll ? '5s' : '2s';

    $unresolvedOverrideCount = \App\Domains\Intake\Support\PhotoOverridePolicy::unresolvedOverrideCount($existingUploads);
    $panelAdvice = \App\Domains\Intake\Support\PhotoOverridePolicy::uniqueCustomerFeedback($existingUploads);
    $showSoftTimeoutLine = $assessmentQuietPoll
        && $assessmentPollPending
        && $unresolvedOverrideCount === 0;
@endphp
<div class="space-y-3" @if ($wrapperTestId) data-testid="{{ $wrapperTestId }}" @endif>
    @if ($showQuestionLabel)
        <p class="text-sm font-medium text-[#18201d]">{{ $question->label }}</p>
    @endif

    @if ($question->photo_instructions)
        <p class="text-sm text-[#5e6862]">{{ $question->photo_instructions }}</p>
    @endif

    {{-- Eén poll per fotovraag; blijft draaien bij max foto’s (drain-groep) zolang assessing/pending. --}}
    @if ($assessmentPollPending || $assessmentPollActive)
        <div
            wire:key="assessment-poll-{{ $composite }}-{{ $assessmentPollInterval }}"
            wire:poll.{{ $assessmentPollInterval }}='pollPendingAssessments(@json($composite))'
            class="hidden"
            data-testid="assessment-poll"
            data-poll-composite="{{ $composite }}"
            aria-hidden="true"
        ></div>
    @endif

    @if ($existingUploads->isNotEmpty())
        <ul class="grid grid-cols-2 gap-3">
            @foreach ($existingUploads as $upload)
                @php
                    $photoStatusLabel = \App\Domains\Intake\Support\PhotoCustomerStatus::forUpload(
                        $upload,
                        $composite,
                        (string) ($uploadPhase ?? ''),
                        (string) ($uploadPhaseComposite ?? ''),
                        $pendingAssessUploadIds[$composite] ?? [],
                        $assessmentUiReleased ?? [],
                    );
                    $isLooking = $photoStatusLabel === \App\Domains\Intake\Support\PhotoCustomerStatus::LOOKING;
                    $needsReplace = \App\Domains\Intake\Support\PhotoOverridePolicy::needsOverride($upload);
                @endphp
                <li
                    class="overflow-hidden rounded-xl border border-[#dde2da] bg-[#eef1ec]"
                    data-testid="photo-thumb-status"
                    data-upload-id="{{ $upload->id }}"
                    @if ($isLooking) data-photo-looking="1" @endif
                >
                    <div class="relative">
                        <img
                            src="{{ route('customer.uploads.show', ['token' => $token, 'upload' => $upload]) }}"
                            alt="{{ $upload->original_filename }}"
                            class="aspect-square w-full object-cover"
                        >
                        <button
                            type="button"
                            wire:click="removePhoto({{ $upload->id }})"
                            wire:loading.attr="disabled"
                            class="absolute inset-x-0 bottom-0 bg-[#18201d] px-2 py-1.5 text-xs font-semibold text-white"
                        >
                            Verwijderen
                        </button>
                    </div>
                    <p class="px-2 py-1.5 text-xs font-medium text-[#414b45]" data-photo-status="1">
                        {{ $photoStatusLabel }}
                    </p>
                    @if ($needsReplace)
                        <button
                            type="button"
                            wire:click="replaceSinglePhoto({{ $upload->id }})"
                            wire:loading.attr="disabled"
                            class="flex min-h-11 w-full items-center justify-center border-t border-[#dde2da] bg-white px-2 py-1.5 text-xs font-semibold text-[var(--tenant-primary)]"
                            data-testid="photo-replace-one"
                        >
                            Vervang foto
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>

        {{-- Eén melding per fotovraag: soft-timeout óf override-keuze. --}}
        @if ($photoMismatchAssessment || $unresolvedOverrideCount > 0)
            <div
                class="space-y-3 rounded-xl border border-[#eac3b4] bg-white px-3 py-3"
                role="alert"
                data-testid="photo-mismatch-panel"
                id="photo-mismatch-panel-{{ str_replace(['.', ' '], '-', $composite) }}"
                wire:key="mismatch-{{ $composite }}"
            >
                <p class="text-sm font-semibold text-[#18201d]" data-testid="photo-mismatch-heading">
                    {{ \App\Domains\Intake\Support\PhotoOverridePolicy::panelHeading($unresolvedOverrideCount) }}
                </p>
                @foreach ($panelAdvice as $advice)
                    <p class="text-sm text-[#414b45]" data-testid="photo-mismatch-advice">{{ $advice }}</p>
                @endforeach
                <p class="text-sm text-[#414b45]" data-testid="photo-mismatch-explain">
                    {{ \App\Domains\Intake\Support\PhotoOverridePolicy::panelExplanation($unresolvedOverrideCount) }}
                </p>
                @if ($showMissing)
                    <p class="text-sm font-medium text-[#a84832]" data-testid="mismatch-next-warning">
                        {{ \App\Domains\Intake\Support\PhotoOverridePolicy::OVERRIDE_MESSAGE }}
                    </p>
                @endif
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <button
                        type="button"
                        wire:click="acceptPhotoMismatch"
                        class="flex min-h-11 items-center justify-center rounded-xl border border-[#dde2da] bg-[#eef1ec] px-4 text-sm font-semibold text-[#18201d]"
                        data-testid="photo-accept-mismatch"
                    >
                        Toch doorgaan
                    </button>
                </div>
            </div>
        @elseif ($showSoftTimeoutLine)
            <div
                class="rounded-xl border border-[#dde2da] bg-white px-3 py-3"
                role="status"
                data-testid="photo-soft-timeout-panel"
                wire:key="soft-timeout-{{ $composite }}"
            >
                <p class="text-sm text-[#414b45]">
                    {{ \App\Domains\Intake\Support\PhotoCustomerStatus::SOFT_TIMEOUT }}
                </p>
            </div>
        @elseif (! empty($displayPhotoHint[$composite]) && ! empty($photoNeedsQualityHint))
            <p class="flex items-start gap-2 rounded-xl border border-[#dde2da] bg-[#eef1ec] px-3 py-2 text-sm text-[#414b45]" role="status" data-testid="photo-quality-hint" wire:key="hint-{{ $composite }}">
                <span>{{ $displayPhotoHint[$composite] }}</span>
            </p>
        @endif
    @endif

    @if ($remainingSlots > 0)
        <x-customer.photo-upload-control
            :composite="$composite"
            wire-model="photoFiles.{{ $composite }}"
            :input-id="'photo-input-'.str_replace(['.', ' '], '-', $composite)"
            :remaining-slots="$remainingSlots"
            :max-upload-kb="$maxUploadKb"
            :upload-hard-max-bytes="$uploadHardMaxBytes ?? 15728640"
            :upload-hard-max-megapixels="$uploadHardMaxMegapixels ?? 24"
            :upload-too-large-message="$uploadTooLargeMessage ?? 'Deze foto is te groot. Probeer een andere foto of maak een nieuwe.'"
            :upload-phase="$uploadPhase"
            :upload-phase-message="$uploadPhaseMessage"
            :upload-phase-composite="$uploadPhaseComposite"
            :pending-assess-upload-ids="$pendingAssessUploadIds"
            :assessment-ui-released="$assessmentUiReleased"
            :hide-assessing-phase="true"
            tone="intake"
        />
        @error('photo')
            <p class="mt-2 text-sm text-[#a84832]">{{ $message }}</p>
        @enderror
    @else
        <p class="text-sm text-[#5e6862]">Maximum van {{ $maxFiles }} foto’s bereikt.</p>
        {{-- Verborgen input zodat "Vervang foto" de picker kan openen na verwijderen. --}}
        <input
            id="photo-input-{{ str_replace(['.', ' '], '-', $composite) }}"
            type="file"
            accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif,image/*"
            multiple
            class="sr-only"
            wire:model="photoFiles.{{ $composite }}"
        >
        @error('photoFiles.'.$composite)
            <p class="mt-2 text-sm text-[#a84832]">{{ $message }}</p>
        @enderror
        @error('photo')
            <p class="mt-2 text-sm text-[#a84832]">{{ $message }}</p>
        @enderror
    @endif

    @if (
        ($question->meta['allow_skip'] ?? false) === true
        && $existingUploads->isEmpty()
        && ! $fieldRequired
    )
        <div class="mt-3">
            <button
                type="button"
                wire:click="skipOptionalPhoto"
                class="min-h-11 w-full rounded-xl border border-[#dde2da] bg-white px-4 text-sm font-semibold text-[#18201d]"
                data-testid="photo-skip"
            >
                {{ $question->meta['skip_label'] ?? 'Weet ik niet / sla over' }}
            </button>
        </div>
    @endif
</div>
