<div class="mx-auto flex min-h-[calc(100dvh-7rem)] w-full max-w-2xl flex-col px-4 py-5 sm:px-6">
    @if ($intake->is_demo && ! $completed)
        <x-demo-scope-notice variant="banner" />
    @endif

    <header class="mb-5">
        <p class="text-sm font-medium text-brand-ink/60">Aanvulling voor {{ $intake->customer_name }}</p>
        <p class="mt-2 text-sm leading-5 text-brand-ink/75">Met jouw hulp kunnen we sneller je airco plaatsen. Hieronder staat alleen wat nog echt nodig is.</p>
        @if (! $completed && $items->isNotEmpty())
            <div class="mt-3 flex items-center justify-between text-xs text-brand-ink/55">
                <span>Onderdeel {{ $followUpStepIndex + 1 }} van {{ $items->count() }}</span>
                <span class="font-medium text-brand-ink" data-testid="follow-up-progress-percent">{{ $progressPercent }}%</span>
            </div>
            <div class="mt-2 h-1.5 overflow-hidden bg-brand-fog/60" role="progressbar" aria-valuenow="{{ $progressPercent }}" aria-valuemin="0" aria-valuemax="100" aria-label="Voortgang op basis van afgeronde onderdelen">
                <div class="h-full bg-brand-sea transition-all duration-300" style="width: {{ $progressPercent }}%"></div>
            </div>
            <p class="mt-1 text-xs text-brand-ink/55">{{ $progressCompleted }} van {{ $progressTotal }} onderdelen afgerond</p>
            @if (! empty($currentItemStatus))
                <p class="mt-1 text-xs font-medium text-brand-ink/70" data-testid="follow-up-item-status">Status: {{ $currentItemStatus['label'] }}</p>
            @endif
        @endif

        @if ($saveMessage !== '')
            <p class="mt-3 text-sm font-medium text-brand-sea" aria-live="polite">{{ $saveMessage }}</p>
        @endif
    </header>

    @if ($completed)
        <div class="flex flex-1 flex-col justify-center rounded-lg bg-white p-6 shadow-sm">
            <h1 class="font-display text-2xl font-semibold tracking-tight text-brand-ink">Bedankt</h1>
            <p class="mt-3 text-sm leading-relaxed text-brand-ink/70" data-testid="follow-up-thank-you">
                {{ $followUpThankYouMessage ?? 'Bedankt. Je installateur kijkt nu of er nog iets openstaat.' }}
            </p>
            @if (! empty($followUpNeedsInstallerReview))
                <p class="mt-2 text-sm font-medium text-amber-800" data-testid="follow-up-needs-review">
                    De installateur moet de foto’s nog beoordelen — dit is nog geen afronding van het dossier.
                </p>
            @endif
            @if ($intake->is_demo)
                <x-demo-scope-notice
                    variant="complete"
                    :needs-installer-review="! empty($followUpNeedsInstallerReview)"
                />
            @endif
        </div>
    @elseif (! $item)
        <div class="rounded-lg bg-white p-6 text-sm text-brand-ink/70 shadow-sm">
            Er staan geen aanvullende vragen open.
        </div>
    @else
        <div class="mb-4">
            <p class="text-xs font-medium uppercase tracking-wide text-brand-ink/50">
                Ronde {{ $round->round_number }}
            </p>
            <h1 id="follow-up-prompt-{{ $item->id }}" class="mt-1 break-words font-display text-2xl font-semibold tracking-tight text-brand-ink">{{ $item->prompt }}</h1>
        </div>

        @error('follow_up')
            @if (empty($followUpMismatchAssessment) && empty($followUpNeedsOverride))
                <div class="mb-4 rounded-md border border-brand-ember/30 bg-white px-4 py-3 text-sm text-brand-ember" role="alert">
                    {{ $message }}
                </div>
            @endif
        @enderror

        <div class="flex-1 rounded-lg bg-white p-4 shadow-sm">
            @if ($item->type === \App\Enums\FollowUpItemType::Choice)
                <fieldset class="space-y-3">
                    <legend class="sr-only">Kies één optie</legend>
                    @foreach ($choiceOptions as $choice)
                        <label class="flex min-h-12 cursor-pointer items-start gap-3 rounded-md border border-brand-fog px-3 py-3 has-[:checked]:border-brand-sea has-[:checked]:bg-brand-mist/40">
                            <input
                                type="radio"
                                class="mt-1 border-brand-fog text-brand-sea focus:ring-brand-sea"
                                wire:model.live="followUpResponses.{{ $item->id }}"
                                value="{{ $choice['value'] }}"
                                name="follow-up-choice-{{ $item->id }}"
                            >
                            <span class="text-sm leading-relaxed text-brand-ink">{{ $choice['label'] }}</span>
                        </label>
                    @endforeach
                </fieldset>
            @elseif ($item->type === \App\Enums\FollowUpItemType::Text)
                <textarea
                    id="follow-up-response-{{ $item->id }}"
                    aria-labelledby="follow-up-prompt-{{ $item->id }}"
                    rows="6"
                    wire:model.blur="followUpResponses.{{ $item->id }}"
                    class="block w-full rounded-md border-brand-fog shadow-sm focus:border-brand-sea focus:ring-brand-sea"
                    required
                ></textarea>
            @elseif ($item->type === \App\Enums\FollowUpItemType::Photo)
                @php
                    $remainingSlots = max(0, $maxPhotos - $item->uploads->count());
                @endphp

                @if ($item->uploads->isNotEmpty())
                    <ul class="grid grid-cols-2 gap-3">
                        @foreach ($item->uploads as $upload)
                            <li class="relative overflow-hidden rounded-md border border-brand-fog bg-brand-mist/30">
                                <img
                                    src="{{ route('customer.uploads.show', ['token' => $token, 'upload' => $upload]) }}"
                                    alt="Aanvullende foto"
                                    class="aspect-square w-full object-cover"
                                >
                                <button
                                    type="button"
                                    wire:click="removeFollowUpUpload({{ $item->id }}, {{ $upload->id }})"
                                    wire:loading.attr="disabled"
                                    class="absolute inset-x-0 bottom-0 bg-brand-ink/75 px-2 py-1.5 text-xs font-semibold text-white"
                                >
                                    Verwijderen
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($remainingSlots > 0)
                    <div class="mt-3">
                        <x-customer.photo-upload-control
                            :composite="(string) $item->id"
                            wire-model="followUpPhotoFiles.{{ $item->id }}"
                            :input-id="'follow-up-photo-input-'.$item->id"
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
                            tone="followup"
                            :help-extra="'Max '.number_format($maxUploadKb / 1024, 0).' MB · nog '.$remainingSlots"
                        />
                    </div>
                @elseif ($item->uploads->isNotEmpty())
                    {{-- Keep a hidden input so “Vervang foto” can reopen the picker after delete. --}}
                    <input
                        id="follow-up-photo-input-{{ $item->id }}"
                        type="file"
                        accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif,image/*"
                        multiple
                        class="sr-only"
                        wire:model="followUpPhotoFiles.{{ $item->id }}"
                    >
                @endif

                @if ($followUpMismatchAssessment || ! empty($followUpNeedsOverride))
                    <div class="mt-3 space-y-3 rounded-md border border-brand-ember/30 bg-white px-3 py-3" role="alert" data-testid="follow-up-mismatch">
                        <p class="text-sm text-brand-ink">
                            @if ($followUpMismatchAssessment)
                                {{ $followUpMismatchAssessment->customerMessage() ?? 'Deze foto lijkt niet bij de vraag te horen.' }}
                            @elseif (! empty($followUpPhotoHint))
                                {{ $followUpPhotoHint }}
                            @else
                                Deze foto is nog niet goed genoeg. Vervang hem of kies expliciet “Toch versturen”.
                            @endif
                        </p>
                        @error('follow_up')
                            <p class="text-sm font-medium text-brand-ember" data-testid="follow-up-mismatch-warning">
                                {{ $message }}
                            </p>
                        @enderror
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                            <button
                                type="button"
                                wire:click="replaceFollowUpMismatchedPhoto"
                                class="min-h-11 rounded-md bg-brand-sea px-4 text-sm font-semibold text-white"
                            >
                                Vervang foto
                            </button>
                            <button
                                type="button"
                                wire:click="acceptFollowUpPhotoMismatch"
                                class="min-h-11 rounded-md border border-brand-fog bg-brand-mist/40 px-4 text-sm font-semibold text-brand-ink"
                                data-testid="follow-up-accept-mismatch"
                            >
                                Toch versturen
                            </button>
                        </div>
                    </div>
                @endif
            @else
                @php
                    $remainingSlots = max(0, $maxDocuments - $item->uploads->count());
                @endphp

                @if ($item->uploads->isNotEmpty())
                    <ul class="divide-y divide-brand-fog overflow-hidden rounded-md border border-brand-fog">
                        @foreach ($item->uploads as $upload)
                            <li class="flex min-w-0 items-center gap-3 px-3 py-3">
                                <a
                                    href="{{ route('customer.uploads.show', ['token' => $token, 'upload' => $upload]) }}"
                                    target="_blank"
                                    rel="noopener"
                                    class="min-w-0 flex-1 text-sm font-semibold text-brand-sea underline decoration-brand-sea/30 underline-offset-2"
                                >
                                    <span class="block truncate">{{ $upload->original_filename }}</span>
                                    <span class="mt-0.5 block text-xs font-normal text-brand-ink/55">PDF · {{ number_format($upload->size_bytes / 1024, 0, ',', '.') }} KB</span>
                                </a>
                                <button
                                    type="button"
                                    wire:click="removeFollowUpUpload({{ $item->id }}, {{ $upload->id }})"
                                    wire:loading.attr="disabled"
                                    class="shrink-0 text-sm font-semibold text-brand-ember"
                                >
                                    Verwijderen
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($remainingSlots > 0)
                    <label class="mt-3 flex min-h-12 cursor-pointer flex-col items-center justify-center gap-1 rounded-md border border-dashed border-brand-fog bg-brand-mist/40 px-4 py-5 text-center">
                        <span class="text-sm font-semibold text-brand-ink">PDF-document kiezen</span>
                        <span class="text-xs text-brand-ink/55">Max {{ number_format($maxUploadKb / 1024, 0) }} MB · nog {{ $remainingSlots }}</span>
                        <input
                            type="file"
                            accept="application/pdf,.pdf"
                            multiple
                            class="sr-only"
                            wire:model="followUpDocumentFiles.{{ $item->id }}"
                        >
                    </label>
                    <div wire:loading wire:target="followUpDocumentFiles.{{ $item->id }}" class="mt-2 text-sm font-medium text-brand-sea">
                        Bezig met uploaden…
                    </div>
                    @error('followUpDocumentFiles.'.$item->id)
                        <p class="mt-2 text-sm text-brand-ember">{{ $message }}</p>
                    @enderror
                @endif
            @endif
        </div>

        <div class="mt-5 flex items-center justify-between gap-3">
            <button
                type="button"
                wire:key="follow-up-previous-{{ $item->id }}"
                wire:click="previousFollowUp"
                @disabled($followUpStepIndex === 0)
                class="min-h-11 rounded-md border border-brand-fog bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm disabled:cursor-not-allowed disabled:opacity-40"
            >
                Vorige
            </button>

            @if ($isLastStep)
                <button
                    type="button"
                    wire:key="follow-up-complete-{{ $item->id }}"
                    wire:click="completeFollowUp"
                    wire:loading.attr="disabled"
                    class="min-h-11 rounded-md bg-brand-sea px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-sea/90 disabled:opacity-60"
                >
                    Aanvulling versturen
                </button>
            @else
                <button
                    type="button"
                    wire:key="follow-up-next-{{ $item->id }}"
                    wire:click="nextFollowUp"
                    wire:loading.attr="disabled"
                    class="min-h-11 rounded-md bg-brand-sea px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-sea/90 disabled:opacity-60"
                >
                    Volgende
                </button>
            @endif
        </div>
    @endif
</div>
