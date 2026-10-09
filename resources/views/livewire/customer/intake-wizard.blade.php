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

    @if ($waitingForPrefill ?? false)
        <div
            class="flex flex-1 flex-col justify-center rounded-xl border border-[#dde2da] bg-white p-6 shadow-sm"
            data-testid="prefill-wait"
            wire:poll.2s="pollPrefillWait"
        >
            <h1 class="text-2xl font-extrabold tracking-tight text-[#18201d]">
                Even geduld, we zetten je vragen klaar
            </h1>
            <p class="mt-3 text-sm leading-relaxed text-[#5e6862]">
                We nemen even de aanvraaggegevens door. Dit duurt meestal maar een paar seconden.
            </p>
            <div class="mt-6 h-1.5 w-full overflow-hidden rounded-full bg-[#dde2da]" aria-hidden="true">
                <div class="h-full w-1/3 animate-pulse rounded-full bg-[var(--tenant-primary)]"></div>
            </div>
        </div>
    @elseif ($completed)
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
                <p class="mt-3 text-sm leading-relaxed text-[#5e6862]" data-testid="customer-close-hint">
                    Je kunt dit venster nu sluiten.
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
                                    @elseif (($item['reason'] ?? '') === 'must_accept')
                                        <span class="font-normal text-[#5e6862]"> — {{ \App\Domains\Intake\Support\MustAcceptQuestions::missingRequirementHint($item['question_key'] ?? null) }}</span>
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
                            @php
                                $groupKeyName = $step['group_key'] ?? 'group';
                                $isDrainNearbyGroup = $groupKeyName === 'drain_nearby';
                                $isDimensionsGroup = $groupKeyName === 'room_dimensions';
                            @endphp
                            @php
                                $lengthQuestion = collect($groupQuestions)->firstWhere('key', 'room_length_m');
                                $widthQuestion = collect($groupQuestions)->firstWhere('key', 'room_width_m');
                                $areaQuestion = collect($groupQuestions)->firstWhere('key', 'room_area_m2');
                                $lengthComposite = $lengthQuestion
                                    ? \App\Domains\Intake\Services\VisibilityResolver::compositeKey($lengthQuestion->key, $step['section_instance_key'])
                                    : null;
                                $widthComposite = $widthQuestion
                                    ? \App\Domains\Intake\Services\VisibilityResolver::compositeKey($widthQuestion->key, $step['section_instance_key'])
                                    : null;
                                $areaComposite = $areaQuestion
                                    ? \App\Domains\Intake\Services\VisibilityResolver::compositeKey($areaQuestion->key, $step['section_instance_key'])
                                    : null;
                                $lengthNumber = $lengthComposite ? data_get($this->form, $lengthComposite.'.number') : null;
                                $widthNumber = $widthComposite ? data_get($this->form, $widthComposite.'.number') : null;
                                $areaNumber = $areaComposite ? data_get($this->form, $areaComposite.'.number') : null;
                                $hasLxB = is_numeric($lengthNumber) && (float) $lengthNumber > 0
                                    && is_numeric($widthNumber) && (float) $widthNumber > 0;
                                $hasTypedArea = is_numeric($areaNumber) && (float) $areaNumber > 0;
                                $showAreaField = $areaQuestion
                                    && ! $hasLxB
                                    && ($this->showAreaOnlyField || $hasTypedArea);
                            @endphp
                            <div
                                class="{{ $isDimensionsGroup ? 'space-y-4' : ($isDrainNearbyGroup ? 'space-y-5' : 'space-y-5') }}"
                                data-testid="{{ $isDrainNearbyGroup ? 'drain-nearby-group' : ($isDimensionsGroup ? 'dimensions-group' : 'question-group') }}"
                                @if ($isDimensionsGroup)
                                    x-data="{
                                        length: {{ \Illuminate\Support\Js::from($lengthNumber) }},
                                        width: {{ \Illuminate\Support\Js::from($widthNumber) }},
                                        format(value) {
                                            const rounded = Math.round(Number(value) * 10) / 10;
                                            if (! Number.isFinite(rounded)) {
                                                return '';
                                            }
                                            const formatted = rounded.toFixed(1).replace('.', ',');
                                            return formatted.endsWith(',0') ? formatted.slice(0, -2) : formatted;
                                        },
                                        get areaLabel() {
                                            const length = parseFloat(this.length);
                                            const width = parseFloat(this.width);
                                            if (! (length > 0) || ! (width > 0)) {
                                                return '';
                                            }
                                            return 'Oppervlak: ' + this.format(length * width) + ' m²';
                                        }
                                    }"
                                @endif
                            >
                                @if ($isDimensionsGroup)
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        @foreach ([$lengthQuestion, $widthQuestion] as $dimQuestion)
                                            @continue(! $dimQuestion)
                                            @php
                                                $groupComposite = \App\Domains\Intake\Services\VisibilityResolver::compositeKey($dimQuestion->key, $step['section_instance_key']);
                                                $groupState = $visibility[$groupComposite] ?? ['visible' => false, 'required' => false];
                                                $alpineModel = $dimQuestion->key === 'room_length_m' ? 'length' : 'width';
                                            @endphp
                                            <div wire:key="group-field-{{ $groupComposite }}">
                                                <label for="field-{{ $groupComposite }}" class="mb-1 block text-sm font-medium text-[#18201d]">
                                                    {{ $dimQuestion->label }}
                                                    @if ($groupState['required'] || ($step['is_required'] ?? false))
                                                        <span class="text-[#a84832]">*</span>
                                                    @endif
                                                </label>
                                                <input
                                                    id="field-{{ $groupComposite }}"
                                                    type="number"
                                                    inputmode="decimal"
                                                    wire:model.blur="form.{{ $groupComposite }}.number"
                                                    x-model="{{ $alpineModel }}"
                                                    class="block min-h-11 w-full rounded-xl border-[#dde2da] shadow-sm focus:border-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]"
                                                    @if ($groupState['required']) required @endif
                                                >
                                            </div>
                                        @endforeach
                                    </div>
                                    <p
                                        class="text-sm font-medium text-[#18201d]"
                                        data-testid="live-room-area"
                                        x-show="areaLabel !== ''"
                                        x-text="areaLabel"
                                        x-cloak
                                    ></p>
                                    @if ($areaQuestion && $areaComposite)
                                        @if ($showAreaField)
                                            <div wire:key="group-field-{{ $areaComposite }}" data-testid="area-only-field">
                                                <label for="field-{{ $areaComposite }}" class="mb-1 block text-sm font-medium text-[#18201d]">
                                                    {{ $areaQuestion->label }}
                                                </label>
                                                <input
                                                    id="field-{{ $areaComposite }}"
                                                    type="number"
                                                    inputmode="decimal"
                                                    wire:model.blur="form.{{ $areaComposite }}.number"
                                                    class="block min-h-11 w-full rounded-xl border-[#dde2da] shadow-sm focus:border-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]"
                                                >
                                                @if ($areaQuestion->help_text)
                                                    <p class="mt-1 text-xs text-[#5e6862]">{{ $areaQuestion->help_text }}</p>
                                                @endif
                                            </div>
                                        @elseif (! $hasLxB)
                                            <button
                                                type="button"
                                                wire:click="revealAreaOnlyField"
                                                class="text-sm font-semibold text-[var(--tenant-primary)] underline decoration-[var(--tenant-primary)]/40 underline-offset-2 hover:decoration-[var(--tenant-primary)]"
                                                data-testid="reveal-area-only"
                                            >
                                                Weet je alleen het oppervlak in m²?
                                            </button>
                                        @endif
                                    @endif
                                    @if (! $hasLxB && ! $hasTypedArea)
                                        <button
                                            type="button"
                                            wire:click="skipOptionalPhoto"
                                            class="min-h-11 w-full rounded-xl border border-[#dde2da] bg-white px-4 text-sm font-semibold text-[#18201d]"
                                            data-testid="dimensions-skip"
                                        >
                                            Weet ik niet / sla over
                                        </button>
                                    @endif
                                @else
                                @foreach ($groupQuestions as $groupQuestion)
                                    @php
                                        $groupComposite = \App\Domains\Intake\Services\VisibilityResolver::compositeKey($groupQuestion->key, $step['section_instance_key']);
                                        $groupState = $visibility[$groupComposite] ?? ['visible' => false, 'required' => false];
                                        $groupType = $groupQuestion->type->value;
                                    @endphp
                                    <div wire:key="group-field-{{ $groupComposite }}">
                                        @if ($groupType === 'number')
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
                                            @if ($groupQuestion->help_text && ! $isDrainNearbyGroup)
                                                <p class="mt-1 text-xs text-[#5e6862]">{{ $groupQuestion->help_text }}</p>
                                            @endif
                                        @elseif ($groupType === 'single_choice')
                                            @unless ($isDrainNearbyGroup)
                                                <p class="mb-2 text-sm font-medium text-[#18201d]">{{ $groupQuestion->label }}</p>
                                            @endunless
                                            <div class="space-y-2" role="radiogroup" aria-label="{{ $groupQuestion->label }}">
                                                @foreach ($groupQuestion->options as $option)
                                                    <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border border-[#dde2da] px-3 py-2 has-[:checked]:border-[var(--tenant-primary)] has-[:checked]:bg-[#eef1ec]">
                                                        <input
                                                            type="radio"
                                                            wire:model.live="form.{{ $groupComposite }}.value"
                                                            value="{{ $option->value }}"
                                                            class="border-[#dde2da] text-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]"
                                                        >
                                                        <span class="text-sm font-medium">{{ $option->label }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        @elseif ($groupType === 'photo')
                                            @include('livewire.customer.partials.photo-question-field', [
                                                'question' => $groupQuestion,
                                                'composite' => $groupComposite,
                                                'fieldRequired' => $groupState['required'] || ($step['is_required'] ?? false),
                                                'showQuestionLabel' => true,
                                                'wrapperTestId' => 'group-photo-field',
                                            ])
                                        @endif
                                    </div>
                                @endforeach
                                @endif
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
                                @if (\App\Domains\Intake\Support\MustAcceptQuestions::requiresAcceptance($question))
                                    <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border border-[#dde2da] px-4 py-3 has-[:checked]:border-[var(--tenant-primary)] has-[:checked]:bg-[#eef1ec]" data-testid="must-accept-checkbox">
                                        <input
                                            type="checkbox"
                                            wire:model.live="form.{{ $composite }}.bool"
                                            class="rounded border-[#dde2da] text-[var(--tenant-primary)] focus:ring-[var(--tenant-primary)]"
                                        >
                                        <span class="text-sm font-semibold">{{ \App\Domains\Intake\Support\MustAcceptQuestions::checkboxLabel($question) }}</span>
                                    </label>
                                    @if ($showMissing && ! \App\Domains\Intake\Support\MustAcceptQuestions::isAccepted($form[$composite] ?? null))
                                        <p class="mt-2 text-sm text-[#a84832]" data-testid="must-accept-refusal">
                                            {{ \App\Domains\Intake\Support\MustAcceptQuestions::refusalMessage($question->key) }}
                                        </p>
                                    @endif
                                @else
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
                                @endif
                                @break

                            @case('photo')
                                @include('livewire.customer.partials.photo-question-field', [
                                    'question' => $question,
                                    'composite' => $composite,
                                    'fieldRequired' => $state['required'] ?? false,
                                    'showQuestionLabel' => false,
                                ])
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

    @unless ($completed || ($waitingForPrefill ?? false))
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
