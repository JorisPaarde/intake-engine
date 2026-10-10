{{--
  Gedeelde fotovraag-UI voor standalone @case('photo') én question_group.
  Ná rebase op stroom 1: assessment-poll blijft zichtbaar bij max foto's (ook in drain-groep).
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
@endphp
<div class="space-y-3" @if ($wrapperTestId) data-testid="{{ $wrapperTestId }}" @endif>
    @if ($showQuestionLabel)
        <p class="text-sm font-medium text-[#18201d]">{{ $question->label }}</p>
    @endif

    @if ($question->photo_instructions)
        <p class="text-sm text-[#5e6862]">{{ $question->photo_instructions }}</p>
    @endif

    {{-- Eén poll per fotovraag; blijft draaien bij max foto's (drain-groep) zolang assessing/pending. --}}
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

    {{-- Eén live-regio: “Foto verwijderd.” + Ongedaan maken, 8 s (BL-147). --}}
    <div role="status" aria-live="polite" data-testid="wizard-undo-region">
        @if (($pendingWizardRemoval['composite'] ?? null) === $composite)
            <div
                wire:key="wizard-undo-{{ $pendingWizardRemoval['upload_id'] }}"
                x-data
                x-init="setTimeout(() => $wire.finalizePendingWizardRemoval({{ (int) $pendingWizardRemoval['upload_id'] }}), 8000)"
                class="flex items-center justify-between gap-3 rounded-xl border border-[#dde2da] bg-[#eef1ec] py-1 pl-3 pr-1 text-sm text-[#18201d]"
                data-testid="wizard-undo-toast"
            >
                <span>Foto verwijderd.</span>
                <button
                    type="button"
                    wire:click="undoWizardUploadRemoval"
                    class="min-h-11 shrink-0 rounded-xl px-3 text-sm font-semibold text-[var(--tenant-primary)] underline decoration-[var(--tenant-primary)]/30 underline-offset-2"
                >
                    Ongedaan maken
                </button>
            </div>
        @endif
    </div>

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
                @endphp
                <li class="overflow-hidden rounded-xl border border-[#dde2da] bg-[#eef1ec]" data-testid="photo-thumb-status" data-upload-id="{{ $upload->id }}">
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
                            class="absolute right-0 top-0 flex h-11 w-11 items-center justify-center"
                            data-testid="wizard-remove-photo"
                        >
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-[#18201d]/75 text-white shadow-sm">
                                <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.52.149.023a.75.75 0 0 0 .23-1.482A41.03 41.03 0 0 0 14 4.193V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4ZM8.58 7.72a.75.75 0 0 0-1.5.06l.3 7.5a.75.75 0 1 0 1.5-.06l-.3-7.5Zm4.34.06a.75.75 0 1 0-1.5-.06l-.3 7.5a.75.75 0 1 0 1.5.06l.3-7.5Z" clip-rule="evenodd" />
                                </svg>
                            </span>
                            <span class="sr-only">Foto verwijderen</span>
                        </button>
                    </div>
                    <p class="px-2 py-1.5 text-xs font-medium text-[#414b45]" data-photo-status="1">
                        {{ $photoStatusLabel }}
                    </p>
                    @if (\App\Domains\Intake\Support\PhotoOverridePolicy::needsOverride($upload))
                        <button
                            type="button"
                            wire:click="replaceSinglePhoto({{ $upload->id }})"
                            wire:loading.attr="disabled"
                            class="w-full border-t border-[#dde2da] bg-white px-2 py-1.5 text-xs font-semibold text-[var(--tenant-primary)]"
                            data-testid="photo-replace-one"
                        >
                            Vervang foto
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>

        @php
            $allTerminal = $existingUploads->every(
                fn ($uploadItem) => $uploadItem->assessment_status instanceof \App\Enums\PhotoAssessmentStatus
                    && $uploadItem->assessment_status->isTerminal()
            );
            $showReceipt = $allTerminal
                || (! $assessmentPollPending && ! $assessmentPollActive && ! $assessmentQuietPoll);
            $photoReceiptStatus = $allTerminal ? 'Beoordeeld' : 'Ontvangen';
        @endphp
        @if ($showReceipt)
            <p class="text-xs font-medium text-[#5e6862]" data-testid="photo-receipt-status">Status: {{ $photoReceiptStatus }}</p>
        @endif

        {{-- Direct onder de foto, boven de sticky balk. (BL-147 / #168: één OVERRIDE_MESSAGE). --}}
        @if ($photoMismatchAssessment || ! empty($photoNeedsOverride))
            <div class="space-y-3 rounded-xl border border-[#eac3b4] bg-white px-3 py-3" role="alert" data-testid="photo-mismatch-panel" wire:key="mismatch-{{ $composite }}">
                <p class="text-sm text-[#414b45]">
                    Deze foto is nog niet goed genoeg. Vervang de foto of ga toch door.
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
                        class="min-h-11 rounded-xl border border-[#dde2da] bg-[#eef1ec] px-4 text-sm font-semibold text-[#18201d]"
                        data-testid="photo-accept-mismatch"
                    >
                        Toch doorgaan
                    </button>
                </div>
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
            tone="intake"
        />
        @error('photo')
            <p class="mt-2 text-sm text-[#a84832]">{{ $message }}</p>
        @enderror
    @else
        <p class="text-sm text-[#5e6862]">Maximum van {{ $maxFiles }} foto's bereikt.</p>
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
