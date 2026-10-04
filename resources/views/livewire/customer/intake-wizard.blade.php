@php
    $company = $intake->company;
@endphp

<div class="mx-auto flex min-h-[100svh] max-w-lg flex-col px-4 pb-8 pt-4 sm:px-6">
    @if ($intake->is_demo && ! $completed)
        <x-demo-scope-notice variant="banner" :short-customer="$demoShortCustomer ?? false" />
    @endif

    <header class="mb-6">
        <span class="sr-only">Digitale Opname</span>
        <div class="flex items-center gap-3">
            @if ($company?->hasLogo())
                <img src="{{ route('customer.company-logo.show', ['token' => $token]) }}" alt="{{ $company->name }}" class="h-11 w-11 rounded-xl border border-[#dde2da] bg-white object-contain">
            @else
                <div class="flex h-11 w-11 items-center justify-center rounded-xl border border-[#dde2da] bg-white text-sm font-semibold text-[var(--tenant-primary)]">
                    {{ mb_strtoupper(mb_substr($company?->name ?? 'D', 0, 1)) }}
                </div>
            @endif
            <div class="min-w-0">
                <p class="truncate text-lg font-semibold text-[#18201d]">{{ $company?->name ?? 'Digitale Opname' }}</p>
                <p class="truncate text-sm text-[#5e6862]">{{ $intake->customer_name }} · {{ $intake->fullAddress() }}</p>
            </div>
        </div>

        <div class="mt-4">
            <p class="mb-4 rounded-xl bg-[color-mix(in_srgb,var(--tenant-primary)_8%,white)] px-4 py-3 text-sm font-medium leading-5 text-[#18201d]">
                Met jouw hulp kunnen we sneller je airco plaatsen. We gebruiken al bekende woninggegevens. Jij laat alleen zien wat we nog nodig hebben.
            </p>
            <div class="flex items-center justify-between text-sm text-[#5e6862]">
                <span>Voortgang</span>
                <span class="font-medium text-[#18201d]" data-testid="progress-percent">{{ $progressPercent }}%</span>
            </div>
            <div class="mt-2 h-1.5 overflow-hidden bg-[#dde2da]" role="progressbar" aria-valuenow="{{ $progressPercent }}" aria-valuemin="0" aria-valuemax="100" aria-label="Voortgang op basis van wizardstappen">
                <div class="h-full bg-[var(--tenant-primary)] transition-all duration-300" style="width: {{ $progressPercent }}%"></div>
            </div>
            @if (! $completed && ($progressTotal ?? 0) > 0)
                <p class="mt-1 text-xs text-[#5e6862]" data-testid="progress-step-count">
                    {{ $progressAnswered ?? 0 }} van {{ $progressTotal }} vragen
                </p>
            @endif
            @if (! empty($progressExtraNote))
                <p class="mt-2 rounded-lg border border-[#dde2da] bg-white px-3 py-2 text-sm text-[#414b45]" role="status" data-testid="progress-extra-note">
                    {{ $progressExtraNote }}
                </p>
            @endif
            @if ($completed)
                <p class="mt-2 text-xs text-[#5e6862]" data-testid="customer-complete-note">
                    Jouw deel is compleet. Open technische restpunten bekijkt je installateur apart.
                </p>
            @endif
        </div>

        @if ($saveMessage !== '')
            <p class="mt-3 text-sm font-medium text-[var(--tenant-primary)]" wire:key="save-{{ $saveMessage }}-{{ now()->timestamp }}" aria-live="polite">
                {{ $saveMessage }}
            </p>
        @endif
    </header>

    @if ($completed)
        <div class="flex flex-1 flex-col justify-center rounded-xl border border-[#dde2da] bg-white p-6 shadow-sm" data-testid="customer-thank-you">
            <h1 class="text-2xl font-extrabold tracking-tight text-[#18201d]">Bedankt</h1>
            <p class="mt-3 text-sm leading-relaxed text-[#5e6862]">
                Jouw deel is compleet. Open technische restpunten bekijkt je installateur apart.
            </p>
            @if ($intake->is_demo)
                <p class="mt-3 text-sm leading-relaxed text-[#5e6862]">
                    Dit was een demo. Er wordt geen echte offerte gemaakt en de gegevens verdwijnen automatisch.
                </p>
                <x-demo-scope-notice
                    variant="complete"
                    :short-customer="$demoShortCustomer ?? false"
                    :installer-return-url="$demoInstallerReturnUrl"
                />
            @else
                <p class="mt-3 text-sm leading-relaxed text-[#5e6862]">
                    Je kunt dit venster sluiten.
                </p>
            @endif
        </div>
    @elseif (($step['kind'] ?? 'question') === 'known_summary')
        <div class="mb-4">
            <p class="eyebrow">
                {{ $step['section_title'] }}
                <span class="mx-1.5 text-[#838c86]">·</span>
                Vraag {{ $stepDisplayNumber }} van {{ $stepDisplayTotal }}
            </p>
            <h1 class="mt-1 text-2xl font-extrabold tracking-tight text-[#18201d]">
                {{ $step['title'] }}
            </h1>
            @if ($step['description'])
                <p class="mt-2 text-sm leading-relaxed text-[#5e6862]">{{ $step['description'] }}</p>
            @endif
        </div>

        <div class="flex-1 rounded-xl border border-[#dde2da] bg-white p-4 shadow-sm">
            <ul class="divide-y divide-[#dde2da]">
                @foreach ($step['known_items'] ?? [] as $item)
                    <li class="flex items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-[#18201d]">{{ $item['label'] }}</p>
                            <p class="mt-0.5 text-sm text-[#414b45]">{{ $item['display_value'] }}</p>
                        </div>
                        <button
                            type="button"
                            wire:click="editKnownAnswer({{ \Illuminate\Support\Js::from($item['question_key']) }}, {{ \Illuminate\Support\Js::from($item['section_instance_key'] ?? null) }})"
                            class="shrink-0 text-sm font-medium text-[var(--tenant-primary)] underline decoration-[var(--tenant-primary)]/40 underline-offset-2"
                        >
                            Wijzigen
                        </button>
                    </li>
                @endforeach
            </ul>
            @if ($step['help_text'])
                <p class="mt-3 text-xs leading-relaxed text-[#5e6862]">{{ $step['help_text'] }}</p>
            @endif
        </div>
    @elseif (($step['kind'] ?? 'question') === 'closing_wishes')
        <div class="mb-4">
            <p class="eyebrow">
                {{ $step['section_title'] }}
                <span class="mx-1.5 text-[#838c86]">·</span>
                Vraag {{ $stepDisplayNumber }} van {{ $stepDisplayTotal }}
            </p>
            <h1 class="mt-1 text-2xl font-extrabold tracking-tight text-[#18201d]">
                {{ $step['title'] }}
            </h1>
            @if ($step['description'])
                <p class="mt-2 text-sm leading-relaxed text-[#5e6862]">{{ $step['description'] }}</p>
            @endif
        </div>

        <div class="flex-1 space-y-4">
            @foreach ($bundleQuestions ?? [] as $bundleQuestion)
                @php
                    $bundleComposite = \App\Domains\Intake\Services\VisibilityResolver::compositeKey($bundleQuestion->key, null);
                @endphp
                <div class="rounded-xl border border-[#dde2da] bg-white p-4 shadow-sm" wire:key="bundle-{{ $bundleQuestion->key }}">
                    <h2 class="text-sm font-semibold text-[#18201d]">{{ $bundleQuestion->label }}</h2>
                    @if ($bundleQuestion->help_text)
                        <p class="mt-1 text-xs leading-relaxed text-[#5e6862]">{{ $bundleQuestion->help_text }}</p>
                    @endif
                    <div class="mt-3">
                        @switch ($bundleQuestion->type->value)
                            @case('long_text')
                                <textarea
                                    id="field-{{ $bundleComposite }}"
                                    rows="3"
                                    wire:model.blur="form.{{ $bundleComposite }}.text"
                                    class="block w-full rounded-xl border-[#dde2da] shadow-sm focus:border-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]"
                                ></textarea>
                                @break
                            @case('multi_choice')
                                <div class="space-y-2">
                                    @foreach ($bundleQuestion->options as $option)
                                        <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border border-[#dde2da] px-3 py-2 has-[:checked]:border-[var(--tenant-primary)] has-[:checked]:bg-[#eef1ec]">
                                            <input
                                                type="checkbox"
                                                wire:model.live="form.{{ $bundleComposite }}.values"
                                                value="{{ $option->value }}"
                                                class="rounded border-[#dde2da] text-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]"
                                            >
                                            <span class="text-sm font-medium">{{ $option->label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @break
                            @case('single_choice')
                                <div class="space-y-2" role="radiogroup">
                                    @foreach ($bundleQuestion->options as $option)
                                        <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border border-[#dde2da] px-3 py-2 has-[:checked]:border-[var(--tenant-primary)] has-[:checked]:bg-[#eef1ec]">
                                            <input
                                                type="radio"
                                                wire:model.live="form.{{ $bundleComposite }}.value"
                                                value="{{ $option->value }}"
                                                class="border-[#dde2da] text-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]"
                                            >
                                            <span class="text-sm font-medium">{{ $option->label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @break
                        @endswitch
                    </div>
                </div>
            @endforeach
            @if ($step['help_text'])
                <p class="text-xs leading-relaxed text-[#5e6862]">{{ $step['help_text'] }}</p>
            @endif
        </div>
    @elseif ($step === null || $question === null)
        <p class="rounded-xl border border-[#dde2da] bg-white p-4 text-sm text-[#414b45] shadow-sm">
            Er zijn nog geen vragen. Vul eerst in hoeveel ruimtes je wilt koelen of verwarmen.
        </p>
    @else
        @php
            $composite = \App\Domains\Intake\Services\VisibilityResolver::compositeKey($question->key, $step['section_instance_key']);
            $state = $visibility[$composite] ?? ['visible' => false, 'required' => false];
        @endphp

        <div class="mb-4">
            <p class="eyebrow">
                {{ $step['section_title'] }}
                <span class="mx-1.5 text-[#838c86]">·</span>
                Vraag {{ $stepDisplayNumber }} van {{ $stepDisplayTotal }}
            </p>
            <h1 class="mt-1 text-2xl font-extrabold tracking-tight text-[#18201d]">
                {{ $step['title'] ?? $question->label }}
                @if ($state['required'] || (($step['kind'] ?? '') === 'question_group' && ($step['is_required'] ?? false)))
                    <span class="text-[#a84832]">*</span>
                @endif
            </h1>
            @if (! empty($step['help_text']))
                <p class="mt-2 text-sm leading-relaxed text-[#5e6862]">{{ $step['help_text'] }}</p>
            @elseif ($question->help_text)
                <p class="mt-2 text-sm leading-relaxed text-[#5e6862]">{{ $question->help_text }}</p>
            @elseif ($step['description'])
                <p class="mt-2 text-sm leading-relaxed text-[#5e6862]">{{ $step['description'] }}</p>
            @endif
        </div>

        @if ($showMissing)
            <div class="mb-4 rounded-xl border border-[#eac3b4] bg-white px-4 py-3 text-sm text-[#a84832]" role="alert" aria-live="assertive" data-testid="step-missing-alert">
                @if ($completionMissing !== [])
                    <p class="font-medium">Nog niet alles is ingevuld.</p>
                    <ul class="mt-2 list-none space-y-1.5 pl-0 text-[#414b45]">
                        @foreach ($completionMissing as $item)
                            <li>
                                <button
                                    type="button"
                                    wire:click="goToMissing({{ \Illuminate\Support\Js::from($item['question_key']) }}, {{ \Illuminate\Support\Js::from($item['section_instance_key'] ?? null) }})"
                                    class="text-left text-sm font-medium text-[var(--tenant-primary)] underline decoration-[var(--tenant-primary)]/40 underline-offset-2 hover:decoration-[var(--tenant-primary)]"
                                >
                                    {{ $item['label'] ?? $item['question_key'] }}
                                    @if (! empty($item['instance_label']))
                                        <span class="font-normal text-[#5e6862]">({{ $item['instance_label'] }})</span>
                                    @endif
                                    @if (($item['reason'] ?? '') === 'required_photo')
                                        <span class="font-normal text-[#5e6862]"> — foto verplicht</span>
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @elseif ($photoMismatchAssessment)
                    <p class="font-medium">Kies: foto vervangen of toch doorgaan</p>
                @else
                    Beantwoord eerst deze verplichte vraag.
                @endif
            </div>
        @endif

        @error('completeness')
            <div class="mb-4 rounded-xl border border-[#eac3b4] bg-white px-4 py-3 text-sm text-[#a84832]" role="alert">
                {{ $message }}
            </div>
        @enderror

        <div class="flex-1" wire:key="q-{{ $composite }}">
            @if ($state['visible'])
                @if (! empty($prefillNotice[$composite]))
                    <div class="mb-3 flex items-start gap-2 rounded-xl border border-[#dde2da] bg-white px-3 py-2 text-sm text-[#414b45]" role="status">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-[var(--tenant-primary)]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                        </svg>
                        <span>{{ $prefillNotice[$composite] }}</span>
                    </div>
                @endif
                <div class="rounded-xl border border-[#dde2da] bg-white p-4 shadow-sm">
                    <div>
                        @if (($step['kind'] ?? 'question') === 'question_group' && ($groupQuestions ?? []) !== [])
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2" data-testid="dimensions-group">
                                @foreach ($groupQuestions as $groupQuestion)
                                    @php
                                        $groupComposite = \App\Domains\Intake\Services\VisibilityResolver::compositeKey($groupQuestion->key, $step['section_instance_key']);
                                        $groupState = $visibility[$groupComposite] ?? ['visible' => false, 'required' => false];
                                    @endphp
                                    <div wire:key="group-field-{{ $groupComposite }}">
                                        <label for="field-{{ $groupComposite }}" class="mb-1 block text-sm font-medium text-[#18201d]">
                                            {{ $groupQuestion->label }}
                                            @if ($groupState['required'] || ($step['is_required'] ?? false))
                                                <span class="text-[#a84832]">*</span>
                                            @endif
                                        </label>
                                        <input
                                            id="field-{{ $groupComposite }}"
                                            type="number"
                                            inputmode="decimal"
                                            wire:model.blur="form.{{ $groupComposite }}.number"
                                            class="block min-h-11 w-full rounded-xl border-[#dde2da] shadow-sm focus:border-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]"
                                            @if ($groupState['required']) required @endif
                                        >
                                        @if ($groupQuestion->help_text)
                                            <p class="mt-1 text-xs text-[#5e6862]">{{ $groupQuestion->help_text }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @else
                        @switch ($question->type->value)
                            @case('short_text')
                                <input
                                    id="field-{{ $composite }}"
                                    type="text"
                                    wire:model.blur="form.{{ $composite }}.text"
                                    wire:keydown.enter.prevent="advanceFromEnter('{{ $composite }}', 'text', $event.target.value)"
                                    class="block min-h-11 w-full rounded-xl border-[#dde2da] shadow-sm focus:border-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]"
                                    @if ($state['required']) required @endif
                                >
                                @if (
                                    ($question->meta['allow_skip'] ?? false) === true
                                    && trim((string) data_get($this->form, $composite.'.text', '')) === ''
                                )
                                    <div class="mt-3">
                                        <button
                                            type="button"
                                            wire:click="skipOptionalPhoto"
                                            class="min-h-11 w-full rounded-xl border border-[#dde2da] bg-white px-4 text-sm font-semibold text-[#18201d]"
                                            data-testid="text-skip"
                                        >
                                            {{ $question->meta['skip_label'] ?? 'Weet ik niet / sla over' }}
                                        </button>
                                    </div>
                                @endif
                                @break

                            @case('long_text')
                                <textarea
                                    id="field-{{ $composite }}"
                                    rows="4"
                                    wire:model.blur="form.{{ $composite }}.text"
                                    class="block w-full rounded-xl border-[#dde2da] shadow-sm focus:border-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]"
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
                                    class="block min-h-11 w-full rounded-xl border-[#dde2da] shadow-sm focus:border-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]"
                                    @if ($state['required']) required @endif
                                >
                                @break

                            @case('single_choice')
                                <div class="space-y-2" role="radiogroup" aria-labelledby="field-{{ $composite }}">
                                    @foreach ($question->options as $option)
                                        <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border border-[#dde2da] px-3 py-2 has-[:checked]:border-[var(--tenant-primary)] has-[:checked]:bg-[#eef1ec]">
                                            <input
                                                type="radio"
                                                wire:model.live="form.{{ $composite }}.value"
                                                value="{{ $option->value }}"
                                                class="border-[#dde2da] text-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]"
                                            >
                                            <span class="text-sm font-medium">{{ $option->label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @break

                            @case('multi_choice')
                                <div class="space-y-2">
                                    @foreach ($question->options as $option)
                                        <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border border-[#dde2da] px-3 py-2 has-[:checked]:border-[var(--tenant-primary)] has-[:checked]:bg-[#eef1ec]">
                                            <input
                                                type="checkbox"
                                                wire:model.live="form.{{ $composite }}.values"
                                                value="{{ $option->value }}"
                                                class="rounded border-[#dde2da] text-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]"
                                            >
                                            <span class="text-sm font-medium">{{ $option->label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @break

                            @case('boolean')
                                <div class="grid grid-cols-2 gap-2">
                                    <label class="flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-xl border border-[#dde2da] px-3 py-2 has-[:checked]:border-[var(--tenant-primary)] has-[:checked]:bg-[#eef1ec]">
                                        <input type="radio" wire:model.live="form.{{ $composite }}.bool" value="1" class="border-[#dde2da] text-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]">
                                        <span class="text-sm font-semibold">Ja</span>
                                    </label>
                                    <label class="flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-xl border border-[#dde2da] px-3 py-2 has-[:checked]:border-[var(--tenant-primary)] has-[:checked]:bg-[#eef1ec]">
                                        <input type="radio" wire:model.live="form.{{ $composite }}.bool" value="0" class="border-[#dde2da] text-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]">
                                        <span class="text-sm font-semibold">Nee</span>
                                    </label>
                                </div>
                                @break

                            @case('photo')
                                <div class="space-y-3">
                                    @if ($question->photo_instructions)
                                        <p class="text-sm text-[#5e6862]">{{ $question->photo_instructions }}</p>
                                    @endif

                                    @php
                                        $existingUploads = $uploadsByQuestion[$question->key] ?? collect();
                                        $maxFiles = (int) ($question->meta['max_files'] ?? config('intake.uploads.max_files_per_question', 5));
                                        $remainingSlots = max(0, $maxFiles - $existingUploads->count());
                                    @endphp

                                    @if ($existingUploads->isNotEmpty())
                                        <ul class="grid grid-cols-2 gap-3">
                                            @foreach ($existingUploads as $upload)
                                                <li class="relative overflow-hidden rounded-xl border border-[#dde2da] bg-[#eef1ec]">
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
                                                </li>
                                            @endforeach
                                        </ul>

                                        @php($photoStatus = $existingUploads->every(fn ($uploadItem) => $uploadItem->assessment_status instanceof \App\Enums\PhotoAssessmentStatus && $uploadItem->assessment_status->isTerminal()) ? 'Beoordeeld' : 'Ontvangen')
                                        <p class="text-xs font-medium text-[#5e6862]" data-testid="photo-receipt-status">Status: {{ $photoStatus }}</p>

                                        {{-- Direct onder de foto, boven de sticky balk. --}}
                                        @if ($photoMismatchAssessment || ! empty($photoNeedsOverride))
                                            <div class="space-y-3 rounded-xl border border-[#eac3b4] bg-white px-3 py-3" role="alert" data-testid="photo-mismatch-panel" wire:key="mismatch-{{ $composite }}">
                                                <p class="text-sm text-[#414b45]">
                                                    @if ($photoMismatchAssessment)
                                                        {{ $photoMismatchAssessment->customerMessage() ?? "Deze foto lijkt niet bij de vraag te horen." }}
                                                    @elseif (! empty($displayPhotoHint[$composite]))
                                                        {{ $displayPhotoHint[$composite] }}
                                                    @else
                                                        Deze foto is nog niet goed genoeg. Vervang hem of kies expliciet “Toch doorgaan”.
                                                    @endif
                                                </p>
                                                @if ($showMissing)
                                                    <p class="text-sm font-medium text-[#a84832]" data-testid="mismatch-next-warning">
                                                        Kies: foto vervangen of toch doorgaan
                                                    </p>
                                                @endif
                                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                                    <button
                                                        type="button"
                                                        wire:click="replaceMismatchedPhoto"
                                                        class="min-h-11 rounded-xl bg-[var(--tenant-primary)] px-4 text-sm font-semibold text-[var(--tenant-on-primary)]"
                                                    >
                                                        Vervang foto
                                                    </button>
                                                    <button
                                                        type="button"
                                                        wire:click="acceptPhotoMismatch"
                                                        class="min-h-11 rounded-xl border border-[#dde2da] bg-[#eef1ec] px-4 text-sm font-semibold text-[#18201d]"
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
                                        <div
                                            @if (($uploadPhase ?? '') === 'assessing' && ($uploadPhaseComposite ?? '') === $composite)
                                                wire:poll.2s="pollPendingAssessments"
                                            @endif
                                            x-data="{
                                                timedOut: false,
                                                timer: null,
                                                uploadTimedOut: false,
                                                uploadTimer: null,
                                                uploadError: '',
                                                requestError: '',
                                                uploadProgress: null,
                                                serverBusy: false,
                                                autoRetrying: false,
                                                retryMessage: '',
                                                retryUntilMs: null,
                                                retryCountdown: '',
                                                countdownTimer: null,
                                                uploadProperty: @js('photoFiles.'.$composite),
                                                inactivityMs: 45000,
                                                serverWaitMs: 120000,
                                                clientUploading: false,
                                                prepBusy: false,
                                                arm() {
                                                    clearTimeout(this.timer);
                                                    this.timedOut = false;
                                                    if ($wire.uploadPhase === 'assessing' && $wire.uploadPhaseComposite === @js($composite)) {
                                                        // Afstemmen op BL-127 ui_soft_timeout (~90s); daarna soft-release of “Opnieuw beoordelen”.
                                                        this.timer = setTimeout(() => { this.timedOut = true }, 90000);
                                                    }
                                                },
                                                clearLivewireUpload() {
                                                    try {
                                                        if (typeof $wire.cancelUpload === 'function') {
                                                            $wire.cancelUpload(this.uploadProperty);
                                                        }
                                                    } catch (e) {
                                                        // Soft-fail: bag kan al leeg zijn.
                                                    }
                                                },
                                                clearCountdown() {
                                                    clearInterval(this.countdownTimer);
                                                    this.countdownTimer = null;
                                                    this.retryCountdown = '';
                                                    this.retryUntilMs = null;
                                                },
                                                tickCountdown() {
                                                    if (! this.retryUntilMs) {
                                                        this.retryCountdown = '';
                                                        return;
                                                    }
                                                    const left = Math.max(0, Math.ceil((this.retryUntilMs - Date.now()) / 1000));
                                                    this.retryCountdown = left > 0 ? ('Nog ' + left + 's…') : '';
                                                },
                                                startCountdown(waitMs) {
                                                    this.clearCountdown();
                                                    const wait = Math.max(0, Number(waitMs) || 0);
                                                    if (wait <= 0) {
                                                        return;
                                                    }
                                                    this.retryUntilMs = Date.now() + wait;
                                                    this.tickCountdown();
                                                    this.countdownTimer = setInterval(() => this.tickCountdown(), 250);
                                                },
                                                armInactivityTimer() {
                                                    clearTimeout(this.uploadTimer);
                                                    this.uploadTimer = setTimeout(() => {
                                                        // Geen timeout terwijl de server nog bezig is ná 100% (lege 200 / Livewire-finish).
                                                        if (this.serverBusy || this.autoRetrying) {
                                                            return;
                                                        }
                                                        this.clearLivewireUpload();
                                                        this.uploadTimedOut = true;
                                                        this.uploadError = 'De server is even druk. Probeer het zo opnieuw.';
                                                    }, this.inactivityMs);
                                                },
                                                armServerWaitTimer() {
                                                    clearTimeout(this.uploadTimer);
                                                    this.serverBusy = true;
                                                    this.uploadTimer = setTimeout(() => {
                                                        if (this.autoRetrying) {
                                                            return;
                                                        }
                                                        this.clearLivewireUpload();
                                                        this.uploadTimedOut = true;
                                                        this.serverBusy = false;
                                                        this.uploadError = 'De server is even druk. Probeer het zo opnieuw.';
                                                    }, this.serverWaitMs);
                                                },
                                                armUpload() {
                                                    clearTimeout(this.uploadTimer);
                                                    this.uploadTimedOut = false;
                                                    this.uploadError = '';
                                                    this.requestError = '';
                                                    this.uploadProgress = 0;
                                                    this.serverBusy = false;
                                                    this.clientUploading = true;
                                                    this.prepBusy = false;
                                                    this.autoRetrying = false;
                                                    this.retryMessage = '';
                                                    this.clearCountdown();
                                                    this.armInactivityTimer();
                                                },
                                                onPrepStart() {
                                                    this.prepBusy = true;
                                                    this.clientUploading = true;
                                                    this.uploadTimedOut = false;
                                                    this.uploadError = '';
                                                    this.uploadProgress = 0;
                                                    this.armInactivityTimer();
                                                },
                                                onPrepDone() {
                                                    this.prepBusy = false;
                                                },
                                                onPrepFailed(event) {
                                                    this.prepBusy = false;
                                                    this.failUpload(event?.detail?.message
                                                        || 'De server is even druk. Probeer het zo opnieuw.');
                                                },
                                                onUploadProgress(event) {
                                                    const detail = event?.detail;
                                                    const progress = typeof detail?.progress === 'number'
                                                        ? detail.progress
                                                        : (typeof detail === 'number' ? detail : null);
                                                    if (typeof progress === 'number') {
                                                        this.uploadProgress = progress;
                                                        if (progress >= 100) {
                                                            // Bytes zijn binnen; wacht op server-antwoord / Livewire-finish zonder inactiviteit-timeout.
                                                            this.armServerWaitTimer();
                                                            return;
                                                        }
                                                    }
                                                    this.serverBusy = false;
                                                    if (! this.autoRetrying) {
                                                        this.armInactivityTimer();
                                                    }
                                                },
                                                onServerBusy() {
                                                    this.armServerWaitTimer();
                                                },
                                                onUploadRetrying(event) {
                                                    this.autoRetrying = true;
                                                    this.uploadTimedOut = false;
                                                    this.uploadError = '';
                                                    this.requestError = '';
                                                    this.retryMessage = event?.detail?.message
                                                        || 'Even geduld, we proberen het opnieuw.';
                                                    this.startCountdown(event?.detail?.waitMs);
                                                    this.armServerWaitTimer();
                                                },
                                                finishUpload() {
                                                    clearTimeout(this.uploadTimer);
                                                    this.serverBusy = false;
                                                    this.clientUploading = false;
                                                    this.prepBusy = false;
                                                    this.autoRetrying = false;
                                                    this.retryMessage = '';
                                                    this.clearCountdown();
                                                    this.uploadProgress = 100;
                                                    if (! this.uploadTimedOut) {
                                                        this.uploadError = '';
                                                    }
                                                },
                                                failUpload(message) {
                                                    clearTimeout(this.uploadTimer);
                                                    this.clearLivewireUpload();
                                                    this.serverBusy = false;
                                                    this.clientUploading = false;
                                                    this.prepBusy = false;
                                                    this.autoRetrying = false;
                                                    this.retryMessage = '';
                                                    this.clearCountdown();
                                                    this.uploadTimedOut = true;
                                                    this.uploadProgress = null;
                                                    this.uploadError = message
                                                        || this.uploadError
                                                        || 'De server is even druk. Probeer het zo opnieuw.';
                                                },
                                                retryUpload() {
                                                    this.clearLivewireUpload();
                                                    this.uploadTimedOut = false;
                                                    this.uploadError = '';
                                                    this.requestError = '';
                                                    this.uploadProgress = null;
                                                    this.serverBusy = false;
                                                    this.clientUploading = false;
                                                    this.prepBusy = false;
                                                    this.autoRetrying = false;
                                                    this.retryMessage = '';
                                                    this.clearCountdown();
                                                    const input = document.getElementById(@js('photo-input-'.str_replace(['.', ' '], '-', $composite)));
                                                    if (input) {
                                                        input.disabled = false;
                                                        input.removeAttribute('disabled');
                                                        input.value = '';
                                                        input.click();
                                                    }
                                                },
                                                onRequestFailed(event) {
                                                    const message = event?.detail?.message
                                                        || 'De server is even druk. Probeer het zo opnieuw.';
                                                    // Tijdens auto-retry toont de rustige wachttekst; finale fout komt via intake:upload-failed.
                                                    if (this.autoRetrying && ! event?.detail?.exhausted) {
                                                        return;
                                                    }
                                                    this.requestError = message;
                                                    this.failUpload(message);
                                                },
                                                onUploadFailed(event) {
                                                    this.failUpload(event?.detail?.message
                                                        || 'De server is even druk. Probeer het zo opnieuw.');
                                                },
                                            }"
                                            x-init="
                                                arm();
                                                $watch(() => $wire.uploadPhase, () => arm());
                                                $watch(() => $wire.uploadPhaseComposite, () => arm());
                                                window.addEventListener('intake:livewire-request-failed', (e) => onRequestFailed(e));
                                                window.addEventListener('intake:upload-retrying', (e) => onUploadRetrying(e));
                                                window.addEventListener('intake:upload-empty-response', (e) => onUploadRetrying(e));
                                                window.addEventListener('intake:upload-retry-succeeded', () => finishUpload());
                                                window.addEventListener('intake:upload-failed', (e) => onUploadFailed(e));
                                                window.addEventListener('intake:photo-prep-start', () => onPrepStart());
                                                window.addEventListener('intake:photo-prep-done', () => onPrepDone());
                                                window.addEventListener('intake:photo-prep-failed', (e) => onPrepFailed(e));
                                            "
                                            x-on:livewire-upload-start="armUpload()"
                                            x-on:livewire-upload-progress="onUploadProgress($event)"
                                            x-on:livewire-upload-finish="finishUpload()"
                                            x-on:livewire-upload-error="failUpload()"
                                            x-on:livewire-upload-cancel="finishUpload()"
                                            data-upload-timing="1"
                                            data-client-downscale="1"
                                        >
                                            @php($uploadBusy = ($uploadPhase ?? '') === 'assessing' && ($uploadPhaseComposite ?? '') === $composite)
                                            <label
                                                class="flex min-h-12 cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-[#dde2da] bg-[#eef1ec] px-4 py-5 text-center"
                                                :class="{ 'pointer-events-none opacity-60': (@js($uploadBusy) && ! timedOut) || (clientUploading && ! uploadTimedOut) || prepBusy }"
                                                wire:target="photoFiles.{{ $composite }}"
                                            >
                                                <span class="text-sm font-semibold text-[#18201d]">Foto's maken of kiezen</span>
                                                <span class="text-xs text-[#5e6862]">
                                                    JPEG, PNG, WebP of HEIC · max {{ number_format($maxUploadKb / 1024, 0) }} MB
                                                    · tot {{ $remainingSlots }} {{ $remainingSlots === 1 ? 'foto' : "foto's" }}
                                                    · camera of galerij
                                                </span>
                                                <input
                                                    id="photo-input-{{ str_replace(['.', ' '], '-', $composite) }}"
                                                    type="file"
                                                    accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif,image/*"
                                                    multiple
                                                    class="sr-only"
                                                    wire:model="photoFiles.{{ $composite }}"
                                                    x-bind:disabled="(@js($uploadBusy) && ! timedOut) || (clientUploading && ! uploadTimedOut) || prepBusy"
                                                >
                                            </label>
                                            <div
                                                wire:loading
                                                wire:target="photoFiles.{{ $composite }}"
                                                class="mt-2 text-sm font-medium text-[var(--tenant-primary)]"
                                                data-uploading="1"
                                                data-testid="upload-progress"
                                                x-show="! uploadTimedOut"
                                            >
                                                <span x-text="
                                                    uploadTimedOut ? '' : (
                                                        autoRetrying
                                                            ? (retryMessage + (retryCountdown ? (' ' + retryCountdown) : ''))
                                                            : (
                                                                serverBusy
                                                                    ? (uploadProgress >= 100 ? 'Bezig op de server…' : 'Uploaden…')
                                                                    : (uploadProgress === null || uploadProgress >= 100 ? 'Uploaden…' : ('Uploaden… ' + uploadProgress + '%'))
                                                            )
                                                    )
                                                "></span>
                                            </div>
                                            <div
                                                x-show="autoRetrying && ! uploadTimedOut"
                                                x-cloak
                                                class="mt-2 text-sm text-[#5e6862]"
                                                role="status"
                                                data-testid="upload-retrying"
                                            >
                                                <p>
                                                    <span x-text="retryMessage || 'Even geduld, we proberen het opnieuw.'"></span>
                                                    <span class="ml-1 tabular-nums" x-text="retryCountdown"></span>
                                                </p>
                                            </div>
                                            <div
                                                x-show="uploadTimedOut && uploadError"
                                                x-cloak
                                                class="mt-2 space-y-1 text-sm font-medium text-[#a84832]"
                                                role="alert"
                                                data-testid="upload-timeout-error"
                                            >
                                                <p x-text="uploadError"></p>
                                                <button
                                                    type="button"
                                                    class="mt-1 text-sm font-semibold text-[var(--tenant-primary)] underline"
                                                    x-on:click="retryUpload()"
                                                    data-testid="upload-retry-button"
                                                >
                                                    Opnieuw proberen
                                                </button>
                                            </div>
                                            <div wire:loading.remove wire:target="photoFiles.{{ $composite }}">
                                                @if (($uploadPhase ?? '') === 'assessing' && ($uploadPhaseComposite ?? '') === $composite)
                                                    <div class="mt-2 space-y-1 text-sm font-medium text-[var(--tenant-primary)]" role="status" data-testid="upload-phase" wire:key="upload-phase-{{ $composite }}-assessing">
                                                        <p>{{ $uploadPhaseMessage }}</p>
                                                        <p class="text-xs font-normal text-[#5e6862]">Fase: Foto beoordelen</p>
                                                        <div x-show="timedOut" x-cloak class="mt-1">
                                                            <button
                                                                type="button"
                                                                wire:click="retryFailedUploadPhase"
                                                                wire:loading.attr="disabled"
                                                                wire:target="pollPendingAssessments,assessPendingUploads,retryFailedUploadPhase"
                                                                class="text-sm font-semibold text-[var(--tenant-primary)] underline disabled:opacity-60"
                                                            >
                                                                Opnieuw beoordelen
                                                            </button>
                                                        </div>
                                                    </div>
                                                @elseif (($uploadPhase ?? '') === 'failed' && ($uploadPhaseComposite ?? '') === $composite)
                                                    <div class="mt-2 space-y-1 text-sm font-medium text-[var(--tenant-primary)]" role="status" data-testid="upload-phase" wire:key="upload-phase-{{ $composite }}-failed">
                                                        <p>{{ $uploadPhaseMessage }}</p>
                                                        <button
                                                            type="button"
                                                            wire:click="retryFailedUploadPhase"
                                                            wire:loading.attr="disabled"
                                                            wire:target="pollPendingAssessments,assessPendingUploads,retryFailedUploadPhase"
                                                            class="mt-1 text-sm font-semibold text-[var(--tenant-primary)] underline disabled:opacity-60"
                                                        >
                                                            Opnieuw proberen
                                                        </button>
                                                    </div>
                                                @endif
                                            </div>
                                            @error('photoFiles.'.$composite)
                                                <p class="mt-2 text-sm text-[#a84832]">{{ $message }}</p>
                                            @enderror
                                            @error('photo')
                                                <p class="mt-2 text-sm text-[#a84832]">{{ $message }}</p>
                                            @enderror
                                        </div>
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
                                        && ! ($state['required'] ?? false)
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
                                @break
                        @endswitch
                        @endif
                    </div>

                    @error('value')
                        <p class="mt-2 text-sm text-[#a84832]">{{ $message }}</p>
                    @enderror
                </div>
            @endif
        </div>
    @endif

    @unless ($completed)
        <footer class="sticky bottom-0 -mx-4 mt-8 border-t border-[#dde2da] bg-[#eef1ec] px-4 py-4 sm:-mx-6 sm:px-6">
            <div class="flex gap-3">
                <button
                    type="button"
                    wire:click="previous"
                    @disabled($stepIndex === 0)
                    class="min-h-12 flex-1 rounded-xl border border-[#dde2da] bg-white px-4 text-sm font-semibold text-[#18201d] disabled:opacity-40"
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
                        {{ ! empty($isKnownSummary) ? 'Klopt, verder' : 'Volgende' }}
                    </button>
                @endif
            </div>
            @if ($showMissing && $photoMismatchAssessment)
                <p class="mt-2 text-center text-xs font-medium text-[#a84832]" data-testid="footer-mismatch-warning" role="alert" aria-live="assertive">
                    Kies: foto vervangen of toch doorgaan
                </p>
            @endif
            <p class="mt-3 text-center text-xs text-[#5e6862]">
                Je voortgang blijft bewaard via deze link tot je afrondt.
            </p>
        </footer>
    @endunless
</div>
