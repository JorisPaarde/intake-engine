@php
    $company = $intake->company;
    $theme = $company?->themeTokens() ?? [
        'primary' => \App\Models\Company::DEFAULT_PRIMARY,
        'accent' => \App\Models\Company::DEFAULT_ACCENT,
        'on_primary' => \App\Models\Company::DEFAULT_ON_PRIMARY,
    ];
@endphp

<div class="mx-auto flex min-h-[100svh] max-w-lg flex-col px-4 pb-8 pt-4 sm:px-6" style="--tenant-primary: {{ $theme['primary'] }}; --tenant-accent: {{ $theme['accent'] }}; --tenant-on-primary: {{ $theme['on_primary'] }};">
    @if ($intake->is_demo && ! $completed)
        <x-demo-scope-notice variant="banner" />
    @endif

    <header class="mb-6">
        <span class="sr-only">Digitale Opname</span>
        <div class="flex items-center gap-3">
            @if ($company?->hasLogo())
                <img src="{{ route('customer.company-logo.show', ['token' => $token]) }}" alt="{{ $company->name }}" class="h-11 w-11 rounded-xl border border-[#D2D2D7] bg-white object-contain">
            @else
                <div class="flex h-11 w-11 items-center justify-center rounded-xl border border-[#D2D2D7] bg-white text-sm font-semibold text-[var(--tenant-primary)]">
                    {{ mb_strtoupper(mb_substr($company?->name ?? 'D', 0, 1)) }}
                </div>
            @endif
            <div class="min-w-0">
                <p class="truncate text-lg font-semibold text-[#1D1D1F]">{{ $company?->name ?? 'Digitale Opname' }}</p>
                <p class="truncate text-sm text-[#6E6E73]">{{ $intake->customer_name }} · {{ $intake->fullAddress() }}</p>
            </div>
        </div>

        <div class="mt-4">
            <div class="flex items-center justify-between text-sm text-[#6E6E73]">
                <span>Voortgang</span>
                <span class="font-medium text-[#1D1D1F]">{{ $progressPercent }}%</span>
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-[#D2D2D7]" role="progressbar" aria-valuenow="{{ $progressPercent }}" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full rounded-full bg-[var(--tenant-primary)] transition-all duration-300" style="width: {{ $progressPercent }}%"></div>
            </div>
        </div>

        @if ($saveMessage !== '')
            <p class="mt-3 text-sm font-medium text-[var(--tenant-primary)]" wire:key="save-{{ $saveMessage }}-{{ now()->timestamp }}" aria-live="polite">
                {{ $saveMessage }}
            </p>
        @endif
    </header>

    @if ($completed)
        <div class="flex flex-1 flex-col justify-center rounded-xl border border-[#D2D2D7] bg-white p-6 shadow-sm">
            <h1 class="text-2xl font-semibold tracking-normal text-[#1D1D1F]">Bedankt</h1>
            <p class="mt-3 text-sm leading-relaxed text-[#6E6E73]">
                @if ($intake->is_demo)
                    Dit was een demo. Er wordt geen echte offerte gemaakt en de gegevens verdwijnen automatisch.
                @else
                    Je opname is volledig ingevuld en doorgestuurd. De installateur neemt de gegevens verder in behandeling.
                    Je kunt dit venster sluiten.
                @endif
            </p>
            @if ($intake->is_demo)
                <x-demo-scope-notice
                    variant="complete"
                    :demo-ai-summary="$demoAiSummary"
                    :demo-attention-points="$demoAttentionPoints"
                />
            @endif
        </div>
    @elseif ($step === null || $question === null)
        <p class="rounded-xl border border-[#D2D2D7] bg-white p-4 text-sm text-[#424245] shadow-sm">
            Er zijn nog geen vragen beschikbaar. Vul eerst het aantal binnenunits in bij Aanvraag.
        </p>
    @else
        @php
            $composite = \App\Domains\Intake\Services\VisibilityResolver::compositeKey($question->key, $step['section_instance_key']);
            $state = $visibility[$composite] ?? ['visible' => false, 'required' => false];
        @endphp

        <div class="mb-4">
            <p class="text-xs font-medium uppercase text-[#6E6E73]">
                {{ $step['section_title'] }}
                <span class="mx-1.5 text-[#86868B]">·</span>
                Vraag {{ $stepIndex + 1 }} van {{ count($steps) }}
            </p>
            <h1 class="mt-1 text-2xl font-semibold tracking-normal text-[#1D1D1F]">
                {{ $question->label }}
                @if ($state['required'])
                    <span class="text-[#B42318]">*</span>
                @endif
            </h1>
            @if ($question->help_text)
                <p class="mt-2 text-sm leading-relaxed text-[#6E6E73]">{{ $question->help_text }}</p>
            @elseif ($step['description'])
                <p class="mt-2 text-sm leading-relaxed text-[#6E6E73]">{{ $step['description'] }}</p>
            @endif
        </div>

        @if ($showMissing)
            <div class="mb-4 rounded-xl border border-[#FECACA] bg-white px-4 py-3 text-sm text-[#B42318]" role="alert">
                @if ($completionMissing !== [])
                    <p class="font-medium">Nog niet alles is ingevuld.</p>
                    <ul class="mt-2 list-none space-y-1.5 pl-0 text-[#424245]">
                        @foreach ($completionMissing as $item)
                            <li>
                                <button
                                    type="button"
                                    wire:click="goToMissing({{ \Illuminate\Support\Js::from($item['question_key']) }}, {{ \Illuminate\Support\Js::from($item['section_instance_key'] ?? null) }})"
                                    class="text-left text-sm font-medium text-[var(--tenant-primary)] underline decoration-[var(--tenant-primary)]/40 underline-offset-2 hover:decoration-[var(--tenant-primary)]"
                                >
                                    {{ $item['label'] ?? $item['question_key'] }}
                                    @if (! empty($item['instance_label']))
                                        <span class="font-normal text-[#6E6E73]">({{ $item['instance_label'] }})</span>
                                    @endif
                                    @if (($item['reason'] ?? '') === 'required_photo')
                                        <span class="font-normal text-[#6E6E73]"> — foto verplicht</span>
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @else
                    Beantwoord eerst deze verplichte vraag.
                @endif
            </div>
        @endif

        @error('completeness')
            <div class="mb-4 rounded-xl border border-[#FECACA] bg-white px-4 py-3 text-sm text-[#B42318]" role="alert">
                {{ $message }}
            </div>
        @enderror

        <div class="flex-1" wire:key="q-{{ $composite }}">
            @if ($state['visible'])
                @if (! empty($prefillNotice[$composite]))
                    <div class="mb-3 flex items-start gap-2 rounded-xl border border-[#D2D2D7] bg-white px-3 py-2 text-sm text-[#424245]" role="status">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-[var(--tenant-primary)]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                        </svg>
                        <span>{{ $prefillNotice[$composite] }}</span>
                    </div>
                @endif
                <div class="rounded-xl border border-[#D2D2D7] bg-white p-4 shadow-sm">
                    <div>
                        @switch ($question->type->value)
                            @case('short_text')
                                <input
                                    id="field-{{ $composite }}"
                                    type="text"
                                    wire:model.blur="form.{{ $composite }}.text"
                                    wire:keydown.enter.prevent="advanceFromEnter('{{ $composite }}', 'text', $event.target.value)"
                                    class="block min-h-11 w-full rounded-xl border-[#D2D2D7] shadow-sm focus:border-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]"
                                    @if ($state['required']) required @endif
                                >
                                @break

                            @case('long_text')
                                <textarea
                                    id="field-{{ $composite }}"
                                    rows="4"
                                    wire:model.blur="form.{{ $composite }}.text"
                                    class="block w-full rounded-xl border-[#D2D2D7] shadow-sm focus:border-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]"
                                    @if ($state['required']) required @endif
                                ></textarea>
                                @break

                            @case('number')
                                <input
                                    id="field-{{ $composite }}"
                                    type="number"
                                    inputmode="decimal"
                                    wire:model.blur="form.{{ $composite }}.number"
                                    wire:keydown.enter.prevent="advanceFromEnter('{{ $composite }}', 'number', $event.target.value)"
                                    class="block min-h-11 w-full rounded-xl border-[#D2D2D7] shadow-sm focus:border-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]"
                                    @if ($state['required']) required @endif
                                >
                                @break

                            @case('single_choice')
                                <div class="space-y-2" role="radiogroup" aria-labelledby="field-{{ $composite }}">
                                    @foreach ($question->options as $option)
                                        <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border border-[#D2D2D7] px-3 py-2 has-[:checked]:border-[var(--tenant-primary)] has-[:checked]:bg-[#F5F5F7]">
                                            <input
                                                type="radio"
                                                wire:model.live="form.{{ $composite }}.value"
                                                value="{{ $option->value }}"
                                                class="border-[#D2D2D7] text-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]"
                                            >
                                            <span class="text-sm font-medium">{{ $option->label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @break

                            @case('multi_choice')
                                <div class="space-y-2">
                                    @foreach ($question->options as $option)
                                        <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border border-[#D2D2D7] px-3 py-2 has-[:checked]:border-[var(--tenant-primary)] has-[:checked]:bg-[#F5F5F7]">
                                            <input
                                                type="checkbox"
                                                wire:model.live="form.{{ $composite }}.values"
                                                value="{{ $option->value }}"
                                                class="rounded border-[#D2D2D7] text-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]"
                                            >
                                            <span class="text-sm font-medium">{{ $option->label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @break

                            @case('boolean')
                                <div class="grid grid-cols-2 gap-2">
                                    <label class="flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-xl border border-[#D2D2D7] px-3 py-2 has-[:checked]:border-[var(--tenant-primary)] has-[:checked]:bg-[#F5F5F7]">
                                        <input type="radio" wire:model.live="form.{{ $composite }}.bool" value="1" class="border-[#D2D2D7] text-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]">
                                        <span class="text-sm font-semibold">Ja</span>
                                    </label>
                                    <label class="flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-xl border border-[#D2D2D7] px-3 py-2 has-[:checked]:border-[var(--tenant-primary)] has-[:checked]:bg-[#F5F5F7]">
                                        <input type="radio" wire:model.live="form.{{ $composite }}.bool" value="0" class="border-[#D2D2D7] text-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]">
                                        <span class="text-sm font-semibold">Nee</span>
                                    </label>
                                </div>
                                @break

                            @case('photo')
                                <div class="space-y-3">
                                    @if ($question->photo_instructions)
                                        <p class="text-sm text-[#6E6E73]">{{ $question->photo_instructions }}</p>
                                    @endif

                                    @php
                                        $existingUploads = $uploadsByQuestion[$question->key] ?? collect();
                                        $maxFiles = (int) ($question->meta['max_files'] ?? config('intake.uploads.max_files_per_question', 5));
                                        $remainingSlots = max(0, $maxFiles - $existingUploads->count());
                                    @endphp

                                    @if ($existingUploads->isNotEmpty())
                                        <ul class="grid grid-cols-2 gap-3">
                                            @foreach ($existingUploads as $upload)
                                                <li class="relative overflow-hidden rounded-xl border border-[#D2D2D7] bg-[#F5F5F7]">
                                                    <img
                                                        src="{{ route('customer.uploads.show', ['token' => $token, 'upload' => $upload]) }}"
                                                        alt="{{ $upload->original_filename }}"
                                                        class="aspect-square w-full object-cover"
                                                    >
                                                    <button
                                                        type="button"
                                                        wire:click="removePhoto({{ $upload->id }})"
                                                        wire:loading.attr="disabled"
                                                        class="absolute inset-x-0 bottom-0 bg-[#1D1D1F] px-2 py-1.5 text-xs font-semibold text-white"
                                                    >
                                                        Verwijderen
                                                    </button>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    @if ($remainingSlots > 0)
                                        <div>
                                            <label class="flex min-h-12 cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-[#D2D2D7] bg-[#F5F5F7] px-4 py-5 text-center">
                                                <span class="text-sm font-semibold text-[#1D1D1F]">Foto's maken of kiezen</span>
                                                <span class="text-xs text-[#6E6E73]">
                                                    JPEG, PNG, WebP of HEIC · max {{ number_format($maxUploadKb / 1024, 0) }} MB
                                                    · tot {{ $remainingSlots }} {{ $remainingSlots === 1 ? 'foto' : "foto's" }}
                                                    · camera of galerij
                                                </span>
                                                <input
                                                    type="file"
                                                    accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif,image/*"
                                                    multiple
                                                    class="sr-only"
                                                    wire:model="photoFiles.{{ $composite }}"
                                                >
                                            </label>
                                            <div wire:loading wire:target="photoFiles.{{ $composite }}" class="mt-2 text-sm font-medium text-[var(--tenant-primary)]">
                                                Bezig met uploaden…
                                            </div>
                                            @error('photoFiles.'.$composite)
                                                <p class="mt-2 text-sm text-[#B42318]">{{ $message }}</p>
                                            @enderror
                                            @error('photo')
                                                <p class="mt-2 text-sm text-[#B42318]">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    @else
                                        <p class="text-sm text-[#6E6E73]">Maximum van {{ $maxFiles }} foto's bereikt.</p>
                                        @error('photoFiles.'.$composite)
                                            <p class="mt-2 text-sm text-[#B42318]">{{ $message }}</p>
                                        @enderror
                                        @error('photo')
                                            <p class="mt-2 text-sm text-[#B42318]">{{ $message }}</p>
                                        @enderror
                                    @endif

                                    @if (! empty($displayPhotoHint[$composite]))
                                        <p class="mt-3 flex items-start gap-2 rounded-xl border border-[#FECACA] bg-white px-3 py-2 text-sm text-[#424245]" role="status" wire:key="hint-{{ $composite }}">
                                            <span aria-hidden="true">💡</span>
                                            <span>{{ $displayPhotoHint[$composite] }}</span>
                                        </p>
                                    @endif
                                </div>
                                @break
                        @endswitch
                    </div>

                    @error('value')
                        <p class="mt-2 text-sm text-[#B42318]">{{ $message }}</p>
                    @enderror
                </div>
            @endif
        </div>
    @endif

    @unless ($completed)
        <footer class="sticky bottom-0 -mx-4 mt-8 border-t border-[#D2D2D7] bg-[#F5F5F7] px-4 py-4 sm:-mx-6 sm:px-6">
            <div class="flex gap-3">
                <button
                    type="button"
                    wire:click="previous"
                    @disabled($stepIndex === 0)
                    class="min-h-12 flex-1 rounded-xl border border-[#D2D2D7] bg-white px-4 text-sm font-semibold text-[#1D1D1F] disabled:opacity-40"
                >
                    Vorige
                </button>

                @if ($isLastStep)
                    <button
                        type="button"
                        wire:click="complete"
                        wire:loading.attr="disabled"
                        class="min-h-12 flex-[1.4] rounded-xl bg-[var(--tenant-primary)] px-4 text-sm font-semibold text-[var(--tenant-on-primary)] disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="complete">Afronden</span>
                        <span wire:loading wire:target="complete">Bezig…</span>
                    </button>
                @else
                    <button
                        type="button"
                        wire:click="next"
                        class="min-h-12 flex-[1.4] rounded-xl bg-[var(--tenant-primary)] px-4 text-sm font-semibold text-[var(--tenant-on-primary)]"
                    >
                        Volgende
                    </button>
                @endif
            </div>
            <p class="mt-3 text-center text-xs text-[#6E6E73]">
                Je voortgang blijft bewaard via deze link tot je afrondt.
            </p>
        </footer>
    @endunless
</div>
