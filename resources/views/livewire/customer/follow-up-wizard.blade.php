<div class="mx-auto flex min-h-[calc(100dvh-7rem)] w-full max-w-2xl flex-col px-4 py-5 sm:px-6">
    @if ($intake->is_demo && ! $completed)
        <x-demo-scope-notice variant="banner" />
    @endif

    <header class="mb-5">
        <p class="text-sm font-medium text-brand-ink/60">Aanvulling voor {{ $intake->customer_name }}</p>
        {{-- Bedankscherm: één kop + één zin (BL-147, UX #16.1); de intro herhaalt zich anders. --}}
        @unless ($completed)
            <p class="mt-2 text-sm leading-5 text-brand-ink/75">Met jouw hulp kunnen we sneller je airco plaatsen. Hieronder staat alleen wat nog echt nodig is.</p>
        @endunless
        @if (! $completed && $items->isNotEmpty())
            <div class="mt-3 flex items-center justify-between text-xs text-brand-ink/55">
                @if ($items->count() > 1)
                    <span data-testid="follow-up-step-label">Opdracht {{ $followUpStepIndex + 1 }} van {{ $items->count() }}</span>
                @else
                    <span></span>
                @endif
                <span class="font-medium text-brand-ink" data-testid="follow-up-progress-percent">{{ $progressPercent }}%</span>
            </div>
            <div class="mt-2 h-1.5 overflow-hidden bg-brand-fog/60" role="progressbar" aria-valuenow="{{ $progressPercent }}" aria-valuemin="0" aria-valuemax="100" aria-label="Voortgang op basis van afgeronde onderdelen">
                <div class="h-full bg-brand-sea transition-all duration-300" style="width: {{ $progressPercent }}%"></div>
            </div>
            <p class="mt-1 text-xs text-brand-ink/55">{{ $progressCompleted }} van {{ $progressTotal }} onderdelen afgerond</p>
            @if (! empty($currentItemStatus) && ($currentItemStatus['status'] ?? '') !== 'received')
                <p class="mt-1 text-xs font-medium text-brand-ink/70" data-testid="follow-up-item-status">Status: {{ $currentItemStatus['label'] }}</p>
            @endif
        @endif

        @if ($saveMessage !== '')
            <p class="mt-3 text-sm font-medium text-brand-sea" aria-live="polite">{{ $saveMessage }}</p>
        @endif
    </header>

    @if ($completed)
        <div class="flex flex-1 flex-col justify-center rounded-lg bg-white p-6 shadow-sm">
            <h1 class="font-display text-2xl font-semibold tracking-tight text-brand-ink">{{ \App\Domains\Intake\Support\PhotoOverridePolicy::THANK_YOU_HEADING }}</h1>
            <p class="mt-3 text-sm leading-relaxed text-brand-ink/70" data-testid="follow-up-thank-you">
                {{ $followUpThankYouMessage ?? \App\Domains\Intake\Support\PhotoOverridePolicy::THANK_YOU_COPY }}
            </p>
            @if ($intake->is_demo)
                <x-demo-scope-notice
                    variant="complete"
                    :installer-return-url="$followUpDemoReturnUrl ?? null"
                />
            @else
                @php
                    $thankYouWebsiteUrl = $intake->company?->publicWebsiteUrl();
                @endphp
                @if (is_string($thankYouWebsiteUrl))
                    <a
                        href="{{ $thankYouWebsiteUrl }}"
                        rel="noopener noreferrer"
                        class="mt-5 inline-flex min-h-11 items-center justify-center rounded-md bg-brand-sea px-4 text-sm font-semibold text-white hover:bg-brand-sea/90"
                        data-testid="customer-company-website"
                    >
                        Naar de website van {{ $intake->company->name }}
                    </a>
                @endif
                <p class="mt-3 text-sm leading-relaxed text-brand-ink/70" data-testid="follow-up-close-hint">Je kunt dit venster nu sluiten.</p>
            @endif
        </div>
    @elseif (! $item)
        <div class="rounded-lg bg-white p-6 text-sm text-brand-ink/70 shadow-sm">
            Er staan geen aanvullende vragen open.
        </div>
    @else
        <div class="mb-4">
            <h1 id="follow-up-prompt-{{ $item->id }}" class="break-words font-display text-2xl font-semibold tracking-tight text-brand-ink">{{ $item->prompt }}</h1>
        </div>

        @error('follow_up')
            @if ($item->type !== \App\Enums\FollowUpItemType::Text && empty($followUpMismatchAssessment) && empty($followUpNeedsOverride))
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
                @php
                    $textErrorId = 'follow-up-response-error-'.$item->id;
                    $textDescribedBy = 'follow-up-prompt-'.$item->id;
                    if ($errors->has('follow_up')) {
                        $textDescribedBy .= ' '.$textErrorId;
                    }
                @endphp
                <label for="follow-up-response-{{ $item->id }}" class="block text-sm font-medium text-brand-ink">Je antwoord</label>
                <textarea
                    id="follow-up-response-{{ $item->id }}"
                    aria-describedby="{{ $textDescribedBy }}"
                    rows="6"
                    wire:model.blur="followUpResponses.{{ $item->id }}"
                    class="mt-1 block w-full rounded-md border-brand-fog shadow-sm focus:border-brand-sea focus:ring-brand-sea"
                    placeholder="Typ hier je antwoord"
                    required
                ></textarea>
                @error('follow_up')
                    <p id="{{ $textErrorId }}" class="mt-2 text-sm text-brand-ember" role="alert">{{ $message }}</p>
                @enderror
            @elseif ($item->type === \App\Enums\FollowUpItemType::Photo)
                @php
                    $remainingSlots = max(0, $maxPhotos - $item->uploads->count());
                @endphp

                {{-- Eén live-regio: “Foto verwijderd.” + Ongedaan maken, 8 s (BL-147, UX #16.4). --}}
                <div role="status" aria-live="polite" data-testid="follow-up-undo-region">
                    @if (($pendingFollowUpRemoval['item_id'] ?? null) === $item->id)
                        <div
                            wire:key="follow-up-undo-{{ $pendingFollowUpRemoval['upload_id'] }}"
                            x-data
                            x-init="setTimeout(() => $wire.finalizePendingFollowUpRemoval({{ (int) $pendingFollowUpRemoval['upload_id'] }}), 8000)"
                            class="mb-3 flex items-center justify-between gap-3 rounded-md border border-brand-fog bg-brand-mist/40 py-1 pl-3 pr-1 text-sm text-brand-ink"
                            data-testid="follow-up-undo-toast"
                        >
                            <span>Foto verwijderd.</span>
                            <button
                                type="button"
                                wire:click="undoFollowUpUploadRemoval"
                                class="min-h-11 shrink-0 rounded-md px-3 text-sm font-semibold text-brand-sea underline decoration-brand-sea/30 underline-offset-2"
                            >
                                Ongedaan maken
                            </button>
                        </div>
                    @endif
                </div>

                @php
                    $followUpComposite = (string) $item->id;
                    $assessmentPollPending = ! empty($pendingAssessUploadIds[$followUpComposite] ?? []);
                    $assessmentPollActive = (string) ($uploadPhase ?? '') === 'assessing'
                        && (string) ($uploadPhaseComposite ?? '') === $followUpComposite;
                    $assessmentQuietPoll = in_array($followUpComposite, $assessmentUiReleased ?? [], true);
                    $assessmentPollInterval = $assessmentQuietPoll ? '5s' : '2s';
                @endphp
                @if ($assessmentPollPending || $assessmentPollActive)
                    <div
                        wire:key="assessment-poll-{{ $followUpComposite }}-{{ $assessmentPollInterval }}"
                        wire:poll.{{ $assessmentPollInterval }}='pollPendingAssessments(@json($followUpComposite))'
                        class="hidden"
                        data-testid="assessment-poll"
                        data-poll-composite="{{ $followUpComposite }}"
                        aria-hidden="true"
                    ></div>
                @endif

                @if ($item->uploads->isNotEmpty())
                    <ul class="grid grid-cols-2 gap-3">
                        @foreach ($item->uploads as $upload)
                            @php
                                $photoStatusLabel = \App\Domains\Intake\Support\PhotoCustomerStatus::forUpload(
                                    $upload,
                                    (string) $item->id,
                                    (string) ($uploadPhase ?? ''),
                                    (string) ($uploadPhaseComposite ?? ''),
                                    $pendingAssessUploadIds[(string) $item->id] ?? [],
                                    $assessmentUiReleased ?? [],
                                );
                            @endphp
                            <li class="overflow-hidden rounded-md border border-brand-fog bg-brand-mist/30" data-testid="photo-thumb-status" data-upload-id="{{ $upload->id }}" wire:key="follow-up-upload-{{ $upload->id }}">
                                <div class="relative">
                                    <img
                                        src="{{ route('customer.uploads.show', ['token' => $token, 'upload' => $upload]) }}"
                                        alt="Aanvullende foto"
                                        class="aspect-square w-full object-cover"
                                    >
                                    <button
                                        type="button"
                                        wire:click="removeFollowUpUpload({{ $item->id }}, {{ $upload->id }})"
                                        wire:loading.attr="disabled"
                                        class="absolute right-0 top-0 flex h-11 w-11 items-center justify-center"
                                        data-testid="follow-up-remove-photo"
                                    >
                                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-ink/75 text-white shadow-sm">
                                            <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.52.149.023a.75.75 0 0 0 .23-1.482A41.03 41.03 0 0 0 14 4.193V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4ZM8.58 7.72a.75.75 0 0 0-1.5.06l.3 7.5a.75.75 0 1 0 1.5-.06l-.3-7.5Zm4.34.06a.75.75 0 1 0-1.5-.06l-.3 7.5a.75.75 0 1 0 1.5.06l.3-7.5Z" clip-rule="evenodd" />
                                            </svg>
                                        </span>
                                        <span class="sr-only">Foto verwijderen</span>
                                    </button>
                                </div>
                                <p class="px-2 py-1.5 text-xs font-medium text-brand-ink/80" data-photo-status="1">
                                    {{ $photoStatusLabel }}
                                </p>
                                @if (\App\Domains\Intake\Support\PhotoOverridePolicy::needsOverride($upload))
                                    <button
                                        type="button"
                                        wire:click="replaceFollowUpSinglePhoto({{ $item->id }}, {{ $upload->id }})"
                                        wire:loading.attr="disabled"
                                        class="w-full border-t border-brand-fog bg-white px-2 py-1.5 text-xs font-semibold text-brand-sea"
                                        data-testid="photo-replace-one"
                                    >
                                        Vervang foto
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($remainingSlots > 0)
                    <div class="mt-3" wire:key="follow-up-photo-control-{{ $item->id }}">
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
                            Deze foto is nog niet goed genoeg. Vervang de foto of ga toch door.
                        </p>
                        @error('follow_up')
                            <p class="text-sm font-medium text-brand-ember" data-testid="follow-up-mismatch-warning">
                                {{ $message }}
                            </p>
                        @enderror
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                            <button
                                type="button"
                                wire:click="acceptFollowUpPhotoMismatch"
                                class="min-h-11 rounded-md border border-brand-fog bg-brand-mist/40 px-4 text-sm font-semibold text-brand-ink"
                                data-testid="follow-up-accept-mismatch"
                            >
                                Toch doorgaan
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
