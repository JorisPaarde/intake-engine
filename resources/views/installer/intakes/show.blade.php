<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $intake->customer_name }}
            </h2>
            <a href="{{ route('dashboard') }}" class="text-sm text-gray-600 hover:text-gray-900">← Terug naar overzicht</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800">
                        {{ $intake->status->label() }}
                    </span>
                    <span class="text-sm text-gray-500">{{ $intake->progress_percent }}% compleet</span>
                    <span class="text-sm text-gray-500">{{ $intake->templateVersion?->template?->name }} · v{{ $intake->templateVersion?->version }}</span>
                </div>

                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 text-sm">
                    <div>
                        <dt class="text-gray-500">E-mail</dt>
                        <dd class="text-gray-900">{{ $intake->customer_email }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Telefoon</dt>
                        <dd class="text-gray-900">{{ $intake->customer_phone ?: '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-gray-500">Adres</dt>
                        <dd class="text-gray-900">{{ $intake->fullAddress() }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Aangemaakt</dt>
                        <dd class="text-gray-900">{{ $intake->created_at?->timezone(config('app.timezone'))->format('d-m-Y H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Afgerond</dt>
                        <dd class="text-gray-900">{{ $intake->completed_at?->timezone(config('app.timezone'))->format('d-m-Y H:i') ?? '—' }}</dd>
                    </div>
                    @if ($intake->internal_note)
                        <div class="sm:col-span-2">
                            <dt class="text-gray-500">Interne notitie</dt>
                            <dd class="text-gray-900 whitespace-pre-wrap">{{ $intake->internal_note }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4" x-data="{ copied: false }">
                <h3 class="text-base font-semibold text-gray-900">Klantlink</h3>
                <p class="text-sm text-gray-600">
                    Bij het aanmaken (en bij een nieuwe link) mailen we de klant automatisch.
                    De kopieerbare link blijft beschikbaar als fallback.
                    @if ($intake->token_expires_at)
                        Geldig tot {{ $intake->token_expires_at->timezone(config('app.timezone'))->format('d-m-Y') }}.
                    @endif
                    @if ($intake->token_revoked_at)
                        <span class="text-red-600 font-medium">Ingetrokken op {{ $intake->token_revoked_at->timezone(config('app.timezone'))->format('d-m-Y H:i') }}.</span>
                    @endif
                </p>

                <div class="flex flex-col gap-2 sm:flex-row">
                    <input
                        id="customer-link"
                        type="text"
                        readonly
                        value="{{ $intake->customerUrl() }}"
                        class="block w-full rounded-md border-gray-300 bg-gray-50 text-sm shadow-sm"
                    >
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700"
                        @click="
                            navigator.clipboard.writeText(document.getElementById('customer-link').value);
                            copied = true;
                            setTimeout(() => copied = false, 2000);
                        "
                    >
                        <span x-show="!copied">Kopiëren</span>
                        <span x-cloak x-show="copied">Gekopieerd</span>
                    </button>
                </div>

                <div class="flex flex-wrap gap-3 pt-2">
                    @if ($intake->token_revoked_at === null && $intake->status !== \App\Enums\IntakeStatus::Cancelled && ! $intake->is_demo)
                        <form method="POST" action="{{ route('intakes.send-link', $intake) }}">
                            @csrf
                            <x-primary-button type="submit">Opnieuw mailen</x-primary-button>
                        </form>
                    @endif

                    @if ($intake->token_revoked_at === null && $intake->status !== \App\Enums\IntakeStatus::Cancelled)
                        <form method="POST" action="{{ route('intakes.revoke', $intake) }}" onsubmit="return confirm('Klantlink intrekken en opname annuleren?')">
                            @csrf
                            <x-danger-button>Link intrekken</x-danger-button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('intakes.regenerate-token', $intake) }}" onsubmit="return confirm('Nieuwe link genereren? De oude link werkt daarna niet meer.')">
                        @csrf
                        <x-secondary-button type="submit">Nieuwe link genereren</x-secondary-button>
                    </form>
                </div>
            </div>


            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="text-base font-semibold text-gray-900">Foto’s</h3>
                @if ($intake->uploads->isEmpty())
                    <p class="text-sm text-gray-500">Nog geen foto’s geüpload.</p>
                @else
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        @foreach ($intake->uploads->sortBy('sort_order') as $upload)
                            <figure class="space-y-2">
                                <a href="{{ route('installer.uploads.show', [$intake, $upload]) }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-md border border-gray-200">
                                    <img
                                        src="{{ route('installer.uploads.show', [$intake, $upload]) }}"
                                        alt="{{ $upload->original_filename }}"
                                        class="aspect-square w-full object-cover"
                                    >
                                </a>
                                <figcaption class="text-xs text-gray-500">
                                    {{ $upload->question_key }}
                                    @if ($upload->section_instance_key)
                                        · {{ $upload->section_instance_key }}
                                    @endif
                                </figcaption>
                            </figure>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($intake->attentionPoints->isNotEmpty())
                <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                    <h3 class="text-base font-semibold text-gray-900">Aandachtspunten</h3>
                    <ul class="list-disc space-y-1 pl-5 text-sm text-gray-800">
                        @foreach ($intake->attentionPoints as $point)
                            <li>
                                {{ $point->label }}
                                @if ($point->is_resolved)
                                    <span class="text-gray-500">(opgelost)</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($intake->report)
                <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-base font-semibold text-gray-900">Rapport</h3>
                        <p class="text-xs text-gray-500">
                            Gegenereerd {{ $intake->report->generated_at?->timezone(config('app.timezone'))->format('d-m-Y H:i') }}
                        </p>
                    </div>

                    @php
                        $aiSummary = is_array($intake->report->meta['ai_summary'] ?? null)
                            ? $intake->report->meta['ai_summary']
                            : null;
                    @endphp

                    @if ($aiSummary)
                        <div class="rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950">
                            <p class="font-semibold">AI-voorstel (niet bindend)</p>
                            <p class="mt-1">{{ $aiSummary['summary'] ?? '' }}</p>
                            @if (! empty($aiSummary['highlights']) && is_array($aiSummary['highlights']))
                                <ul class="mt-2 list-disc space-y-1 pl-5">
                                    @foreach ($aiSummary['highlights'] as $highlight)
                                        <li>{{ $highlight }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif

                    <iframe
                        title="Opnamerapport"
                        srcdoc="{{ $intake->report->html }}"
                        class="h-[32rem] w-full rounded-md border border-gray-200 bg-white"
                    ></iframe>
                </div>
            @endif

            @if (in_array($intake->status, [\App\Enums\IntakeStatus::Completed, \App\Enums\IntakeStatus::Reviewed], true))
                <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                    <h3 class="text-base font-semibold text-gray-900">Beoordeling</h3>

                    @if ($intake->review)
                        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-gray-500">Beslissing</dt>
                                <dd class="text-gray-900">{{ $intake->review->decision->label() }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Beoordeeld</dt>
                                <dd class="text-gray-900">{{ $intake->review->reviewed_at?->timezone(config('app.timezone'))->format('d-m-Y H:i') ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Locatiebezoek nodig</dt>
                                <dd class="text-gray-900">{{ $intake->review->site_visit_needed ? 'Ja' : 'Nee' }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Voldoende informatie</dt>
                                <dd class="text-gray-900">{{ $intake->review->enough_information ? 'Ja' : 'Nee' }}</dd>
                            </div>
                            @if ($intake->review->summary)
                                <div class="sm:col-span-2">
                                    <dt class="text-gray-500">Samenvatting</dt>
                                    <dd class="whitespace-pre-wrap text-gray-900">{{ $intake->review->summary }}</dd>
                                </div>
                            @endif
                        </dl>
                    @endif

                    <form method="POST" action="{{ route('intakes.review', $intake) }}" class="space-y-4 border-t border-gray-100 pt-4">
                        @csrf
                        <div>
                            <x-input-label for="decision" value="Beslissing" />
                            <select id="decision" name="decision" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                                <option value="">Kies een beoordeling…</option>
                                @foreach ($reviewDecisions as $decision)
                                    <option value="{{ $decision->value }}" @selected(old('decision', $intake->review?->decision?->value) === $decision->value)>
                                        {{ $decision->label() }}
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('decision')" class="mt-2" />
                        </div>

                        <div class="flex flex-wrap gap-6 text-sm">
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" name="site_visit_needed" value="1" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" @checked(old('site_visit_needed', $intake->review?->site_visit_needed))>
                                <span>Locatiebezoek nodig</span>
                            </label>
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" name="enough_information" value="1" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" @checked(old('enough_information', $intake->review?->enough_information ?? true))>
                                <span>Voldoende informatie</span>
                            </label>
                        </div>

                        <div>
                            <x-input-label for="summary" value="Samenvatting (optioneel)" />
                            <textarea id="summary" name="summary" rows="4" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('summary', $intake->review?->summary) }}</textarea>
                            <x-input-error :messages="$errors->get('summary')" class="mt-2" />
                        </div>

                        <x-primary-button>
                            {{ $intake->review ? 'Beoordeling bijwerken' : 'Beoordeling opslaan' }}
                        </x-primary-button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
