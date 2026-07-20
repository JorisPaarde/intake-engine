<div class="mx-auto flex min-h-[calc(100dvh-7rem)] w-full max-w-2xl flex-col px-4 py-5 sm:px-6">
    <header class="mb-5">
        <p class="text-sm font-medium text-brand-ink/60">Aanvulling voor {{ $intake->customer_name }}</p>
        @if (! $completed && $items->isNotEmpty())
            @php($progress = (int) round((($followUpStepIndex + 1) / $items->count()) * 100))
            <div class="mt-3 flex items-center justify-between text-xs text-brand-ink/55">
                <span>Onderdeel {{ $followUpStepIndex + 1 }} van {{ $items->count() }}</span>
                <span class="font-medium text-brand-ink">{{ $progress }}%</span>
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-brand-fog/60" role="progressbar" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full rounded-full bg-brand-sea transition-all duration-300" style="width: {{ $progress }}%"></div>
            </div>
        @endif

        @if ($saveMessage !== '')
            <p class="mt-3 text-sm font-medium text-brand-sea" aria-live="polite">{{ $saveMessage }}</p>
        @endif
    </header>

    @if ($completed)
        <div class="flex flex-1 flex-col justify-center rounded-lg bg-white p-6 shadow-sm">
            <h1 class="font-display text-2xl font-semibold tracking-tight text-brand-ink">Bedankt</h1>
            <p class="mt-3 text-sm leading-relaxed text-brand-ink/70">
                Je aanvulling is toegevoegd aan het dossier. De installateur krijgt bericht en beoordeelt de aanvraag opnieuw.
            </p>
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
            <h1 class="mt-1 break-words font-display text-2xl font-semibold tracking-tight text-brand-ink">{{ $item->prompt }}</h1>
        </div>

        @error('follow_up')
            <div class="mb-4 rounded-md border border-brand-ember/30 bg-white px-4 py-3 text-sm text-brand-ember" role="alert">
                {{ $message }}
            </div>
        @enderror

        <div class="flex-1 rounded-lg bg-white p-4 shadow-sm">
            @if ($item->type === \App\Enums\FollowUpItemType::Text)
                <textarea
                    rows="6"
                    wire:model.blur="followUpResponses.{{ $item->id }}"
                    class="block w-full rounded-md border-brand-fog shadow-sm focus:border-brand-sea focus:ring-brand-sea"
                    required
                ></textarea>
            @elseif ($item->type === \App\Enums\FollowUpItemType::Photo)
                @php($remainingSlots = max(0, $maxPhotos - $item->uploads->count()))

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
                    <label class="mt-3 flex min-h-12 cursor-pointer flex-col items-center justify-center gap-1 rounded-md border border-dashed border-brand-fog bg-brand-mist/40 px-4 py-5 text-center">
                        <span class="text-sm font-semibold text-brand-ink">Foto's maken of kiezen</span>
                        <span class="text-xs text-brand-ink/55">Max {{ number_format($maxUploadKb / 1024, 0) }} MB · nog {{ $remainingSlots }}</span>
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif,image/*"
                            multiple
                            class="sr-only"
                            wire:model="followUpPhotoFiles.{{ $item->id }}"
                        >
                    </label>
                    <div wire:loading wire:target="followUpPhotoFiles.{{ $item->id }}" class="mt-2 text-sm font-medium text-brand-sea">
                        Bezig met uploaden…
                    </div>
                    @error('followUpPhotoFiles.'.$item->id)
                        <p class="mt-2 text-sm text-brand-ember">{{ $message }}</p>
                    @enderror
                @endif

                @if (! empty($followUpPhotoHint))
                    <div class="mt-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="status">
                        {{ $followUpPhotoHint }}
                    </div>
                @endif
            @else
                @php($remainingSlots = max(0, $maxDocuments - $item->uploads->count()))

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
