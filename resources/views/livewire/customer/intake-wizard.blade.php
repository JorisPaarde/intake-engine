<div class="mx-auto flex min-h-[100svh] max-w-lg flex-col px-4 pb-8 pt-4 sm:px-6">
    @if ($intake->is_demo)
        <div class="mb-4 rounded-md border border-brand-ember/40 bg-brand-ember/10 px-4 py-2.5 text-center text-sm font-semibold text-brand-ember" role="status">
            Demo — geen echte offerte
        </div>
    @endif

    <header class="mb-6">
        <p class="font-display text-lg font-semibold text-brand-deep">Digitale Opname</p>
        <p class="mt-1 text-sm text-brand-ink/60">{{ $intake->customer_name }} · {{ $intake->fullAddress() }}</p>

        <div class="mt-4">
            <div class="flex items-center justify-between text-sm text-brand-ink/70">
                <span>Voortgang</span>
                <span class="font-medium text-brand-ink">{{ $progressPercent }}%</span>
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-brand-fog/60" role="progressbar" aria-valuenow="{{ $progressPercent }}" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full rounded-full bg-brand-sea transition-all duration-300" style="width: {{ $progressPercent }}%"></div>
            </div>
        </div>

        @if ($saveMessage !== '')
            <p class="mt-3 text-sm font-medium text-brand-sea" wire:key="save-{{ $saveMessage }}-{{ now()->timestamp }}" aria-live="polite">
                {{ $saveMessage }}
            </p>
        @endif
    </header>

    @if ($completed)
        <div class="flex flex-1 flex-col justify-center rounded-lg bg-white p-6 shadow-sm">
            <h1 class="font-display text-2xl font-semibold tracking-tight text-brand-ink">Bedankt</h1>
            <p class="mt-3 text-sm leading-relaxed text-brand-ink/70">
                @if ($intake->is_demo)
                    Dit was een demo. Er wordt geen echte offerte gemaakt en de gegevens verdwijnen automatisch.
                    Je kunt dit venster sluiten — of <a href="{{ url('/') }}" class="font-semibold text-brand-sea underline">terug naar de homepage</a>.
                @else
                    Je opname is volledig ingevuld en doorgestuurd. De installateur neemt de gegevens verder in behandeling.
                    Je kunt dit venster sluiten.
                @endif
            </p>
        </div>
    @elseif ($step === null || $question === null)
        <p class="rounded-md bg-white p-4 text-sm text-brand-ink/80 shadow-sm">
            Er zijn nog geen vragen beschikbaar. Vul eerst het aantal binnenunits in bij Aanvraag.
        </p>
    @else
        @php
            $composite = \App\Domains\Intake\Services\VisibilityResolver::compositeKey($question->key, $step['section_instance_key']);
            $state = $visibility[$composite] ?? ['visible' => false, 'required' => false];
        @endphp

        <div class="mb-4">
            <p class="text-xs font-medium uppercase tracking-wide text-brand-ink/50">
                {{ $step['section_title'] }}
                <span class="mx-1.5 text-brand-ink/30">·</span>
                Vraag {{ $stepIndex + 1 }} van {{ count($steps) }}
            </p>
            <h1 class="mt-1 font-display text-2xl font-semibold tracking-tight text-brand-ink">
                {{ $question->label }}
                @if ($state['required'])
                    <span class="text-brand-ember">*</span>
                @endif
            </h1>
            @if ($question->help_text)
                <p class="mt-2 text-sm leading-relaxed text-brand-ink/70">{{ $question->help_text }}</p>
            @elseif ($step['description'])
                <p class="mt-2 text-sm leading-relaxed text-brand-ink/70">{{ $step['description'] }}</p>
            @endif
        </div>

        @if ($showMissing)
            <div class="mb-4 rounded-md border border-brand-ember/30 bg-white px-4 py-3 text-sm text-brand-ember" role="alert">
                @if ($completionMissing !== [])
                    <p class="font-medium">Nog niet alles is ingevuld.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-brand-ink/80">
                        @foreach ($completionMissing as $item)
                            <li>
                                {{ $item['label'] ?? $item['question_key'] }}
                                @if ($item['section_instance_key'])
                                    ({{ $item['section_instance_key'] }})
                                @endif
                                @if (($item['reason'] ?? '') === 'required_photo')
                                    — foto verplicht
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    Beantwoord eerst deze verplichte vraag.
                @endif
            </div>
        @endif

        @error('completeness')
            <div class="mb-4 rounded-md border border-brand-ember/30 bg-white px-4 py-3 text-sm text-brand-ember" role="alert">
                {{ $message }}
            </div>
        @enderror

        <div class="flex-1" wire:key="q-{{ $composite }}">
            @if ($state['visible'])
                @if (! empty($prefillNotice[$composite]))
                    <div class="mb-3 flex items-start gap-2 rounded-md border border-brand-sea/30 bg-brand-mist/50 px-3 py-2 text-sm text-brand-ink/80" role="status">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-brand-sea" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                        </svg>
                        <span>{{ $prefillNotice[$composite] }}</span>
                    </div>
                @endif
                <div class="rounded-lg bg-white p-4 shadow-sm">
                    <div>
                        @switch ($question->type->value)
                            @case('short_text')
                                <input
                                    id="field-{{ $composite }}"
                                    type="text"
                                    wire:model.blur="form.{{ $composite }}.text"
                                    class="block w-full rounded-md border-brand-fog shadow-sm focus:border-brand-sea focus:ring-brand-sea"
                                    @if ($state['required']) required @endif
                                >
                                @break

                            @case('long_text')
                                <textarea
                                    id="field-{{ $composite }}"
                                    rows="4"
                                    wire:model.blur="form.{{ $composite }}.text"
                                    class="block w-full rounded-md border-brand-fog shadow-sm focus:border-brand-sea focus:ring-brand-sea"
                                    @if ($state['required']) required @endif
                                ></textarea>
                                @break

                            @case('number')
                                <input
                                    id="field-{{ $composite }}"
                                    type="number"
                                    inputmode="decimal"
                                    wire:model.blur="form.{{ $composite }}.number"
                                    class="block w-full rounded-md border-brand-fog shadow-sm focus:border-brand-sea focus:ring-brand-sea"
                                    @if ($state['required']) required @endif
                                >
                                @break

                            @case('single_choice')
                                <div class="space-y-2" role="radiogroup" aria-labelledby="field-{{ $composite }}">
                                    @foreach ($question->options as $option)
                                        <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-md border border-brand-fog px-3 py-2 has-[:checked]:border-brand-sea has-[:checked]:bg-brand-mist/50">
                                            <input
                                                type="radio"
                                                wire:model.live="form.{{ $composite }}.value"
                                                value="{{ $option->value }}"
                                                class="border-brand-fog text-brand-sea focus:ring-brand-sea"
                                            >
                                            <span class="text-sm font-medium">{{ $option->label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @break

                            @case('multi_choice')
                                <div class="space-y-2">
                                    @foreach ($question->options as $option)
                                        <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-md border border-brand-fog px-3 py-2 has-[:checked]:border-brand-sea has-[:checked]:bg-brand-mist/50">
                                            <input
                                                type="checkbox"
                                                wire:model.live="form.{{ $composite }}.values"
                                                value="{{ $option->value }}"
                                                class="rounded border-brand-fog text-brand-sea focus:ring-brand-sea"
                                            >
                                            <span class="text-sm font-medium">{{ $option->label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @break

                            @case('boolean')
                                <div class="grid grid-cols-2 gap-2">
                                    <label class="flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-md border border-brand-fog px-3 py-2 has-[:checked]:border-brand-sea has-[:checked]:bg-brand-mist/50">
                                        <input type="radio" wire:model.live="form.{{ $composite }}.bool" value="1" class="border-brand-fog text-brand-sea focus:ring-brand-sea">
                                        <span class="text-sm font-semibold">Ja</span>
                                    </label>
                                    <label class="flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-md border border-brand-fog px-3 py-2 has-[:checked]:border-brand-sea has-[:checked]:bg-brand-mist/50">
                                        <input type="radio" wire:model.live="form.{{ $composite }}.bool" value="0" class="border-brand-fog text-brand-sea focus:ring-brand-sea">
                                        <span class="text-sm font-semibold">Nee</span>
                                    </label>
                                </div>
                                @break

                            @case('photo')
                                <div class="space-y-3">
                                    @if ($question->photo_instructions)
                                        <p class="text-sm text-brand-ink/70">{{ $question->photo_instructions }}</p>
                                    @endif

                                    @php
                                        $existingUploads = $uploadsByQuestion[$question->key] ?? collect();
                                    @endphp

                                    @if ($existingUploads->isNotEmpty())
                                        <ul class="grid grid-cols-2 gap-3">
                                            @foreach ($existingUploads as $upload)
                                                <li class="relative overflow-hidden rounded-md border border-brand-fog bg-brand-mist/30">
                                                    <img
                                                        src="{{ route('customer.uploads.show', ['token' => $token, 'upload' => $upload]) }}"
                                                        alt="{{ $upload->original_filename }}"
                                                        class="aspect-square w-full object-cover"
                                                    >
                                                    <button
                                                        type="button"
                                                        wire:click="removePhoto({{ $upload->id }})"
                                                        wire:loading.attr="disabled"
                                                        class="absolute inset-x-0 bottom-0 bg-brand-ink/75 px-2 py-1.5 text-xs font-semibold text-white"
                                                    >
                                                        Verwijderen
                                                    </button>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    <div>
                                        <label class="flex min-h-12 cursor-pointer flex-col items-center justify-center gap-1 rounded-md border border-dashed border-brand-fog bg-brand-mist/40 px-4 py-5 text-center">
                                            <span class="text-sm font-semibold text-brand-ink">Foto maken of kiezen</span>
                                            <span class="text-xs text-brand-ink/55">JPEG, PNG, WebP of HEIC · max {{ number_format($maxUploadKb / 1024, 0) }} MB</span>
                                            <input
                                                type="file"
                                                accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif,image/*"
                                                capture="environment"
                                                class="sr-only"
                                                wire:model="photoFiles.{{ $composite }}"
                                            >
                                        </label>
                                        <div wire:loading wire:target="photoFiles.{{ $composite }}" class="mt-2 text-sm font-medium text-brand-sea">
                                            Bezig met uploaden…
                                        </div>
                                        @error('photoFiles.'.$composite)
                                            <p class="mt-2 text-sm text-brand-ember">{{ $message }}</p>
                                        @enderror
                                        @error('photo')
                                            <p class="mt-2 text-sm text-brand-ember">{{ $message }}</p>
                                        @enderror
                                    </div>
                                </div>
                                @break
                        @endswitch
                    </div>

                    @error('value')
                        <p class="mt-2 text-sm text-brand-ember">{{ $message }}</p>
                    @enderror
                </div>
            @endif
        </div>
    @endif

    @unless ($completed)
        <footer class="sticky bottom-0 -mx-4 mt-8 border-t border-brand-fog/70 bg-brand-sand/95 px-4 py-4 backdrop-blur sm:-mx-6 sm:px-6">
            <div class="flex gap-3">
                <button
                    type="button"
                    wire:click="previous"
                    @disabled($stepIndex === 0)
                    class="min-h-12 flex-1 rounded-md border border-brand-fog bg-white px-4 text-sm font-semibold text-brand-ink disabled:opacity-40"
                >
                    Vorige
                </button>

                @if ($isLastStep)
                    <button
                        type="button"
                        wire:click="complete"
                        wire:loading.attr="disabled"
                        class="min-h-12 flex-[1.4] rounded-md bg-brand-sea px-4 text-sm font-semibold text-white disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="complete">Afronden</span>
                        <span wire:loading wire:target="complete">Bezig…</span>
                    </button>
                @else
                    <button
                        type="button"
                        wire:click="next"
                        class="min-h-12 flex-[1.4] rounded-md bg-brand-sea px-4 text-sm font-semibold text-white"
                    >
                        Volgende
                    </button>
                @endif
            </div>
            <p class="mt-3 text-center text-xs text-brand-ink/50">
                Je voortgang blijft bewaard via deze link tot je afrondt.
            </p>
        </footer>
    @endunless
</div>
