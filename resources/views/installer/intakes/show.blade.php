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

            <div class="min-w-0 bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
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
                        <dd class="break-words text-gray-900">{{ $intake->customer_email }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Telefoon</dt>
                        <dd class="text-gray-900">{{ $intake->customer_phone ?: '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-gray-500">Adres</dt>
                        <dd class="break-words text-gray-900">{{ $intake->fullAddress() }}</dd>
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

                <div class="border-t border-gray-100 pt-4">
                    <h3 class="text-sm font-semibold text-gray-900">Korte samenvatting</h3>
                    <p class="mt-1 break-words text-sm text-gray-700">{{ $dossierSummary }}</p>
                </div>
            </div>

            <div class="min-w-0 bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <div>
                    <h3 class="break-words text-base font-semibold text-gray-900">Automatisch verzamelde informatie</h3>
                    <p class="mt-1 text-xs text-gray-500">Bron en zekerheid blijven zichtbaar; onzekere gegevens vragen om controle.</p>
                </div>

                @if ($externalData['aerial_image'])
                    <figure class="max-w-3xl space-y-2">
                        <div class="relative aspect-[3/2] overflow-hidden rounded-md border border-gray-200 bg-gray-100">
                            <img
                                src="{{ $externalData['aerial_image']['data_uri'] }}"
                                alt="Luchtfoto rond de BAG-locatie van deze opname"
                                class="h-full w-full object-cover"
                            >
                            <span class="pointer-events-none absolute left-1/2 top-1/2 h-5 w-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-red-600 shadow" aria-hidden="true"></span>
                        </div>
                        <figcaption class="text-xs text-gray-500">
                            Rode markering: BAG-locatie
                            @if ($externalData['aerial_image']['ground_width_meters'] && $externalData['aerial_image']['ground_height_meters'])
                                · circa {{ $externalData['aerial_image']['ground_width_meters'] }} × {{ $externalData['aerial_image']['ground_height_meters'] }} meter
                            @endif
                            · Bron:
                            @if ($externalData['aerial_image']['source_url'])
                                <a href="{{ $externalData['aerial_image']['source_url'] }}" target="_blank" rel="noopener" class="underline hover:text-gray-700">{{ $externalData['aerial_image']['source'] }}</a>
                            @else
                                {{ $externalData['aerial_image']['source'] }}
                            @endif
                        </figcaption>
                    </figure>
                @endif

                @if ($externalData['facts'] !== [])
                    <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                        @foreach ($externalData['facts'] as $fact)
                            <div class="min-w-0">
                                <dt class="text-gray-500">{{ $fact['label'] }}</dt>
                                <dd class="break-words text-gray-900">{{ $fact['display'] }}</dd>
                                <dd class="mt-0.5 break-words text-xs text-gray-400">
                                    @if ($fact['source_url'])
                                        <a href="{{ $fact['source_url'] }}" target="_blank" rel="noopener" class="underline hover:text-gray-600">{{ $fact['source'] }}</a>
                                    @else
                                        {{ $fact['source'] }}
                                    @endif
                                    · {{ $fact['confidence'] }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                @else
                    <p class="text-sm text-gray-500">Nog geen externe gegevens beschikbaar.</p>
                @endif

                @if ($externalData['uncertainties'] !== [])
                    <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3">
                        <p class="text-sm font-medium text-amber-900">Nog controleren</p>
                        <ul class="mt-1 list-disc space-y-1 pl-5 text-sm text-amber-900">
                            @foreach ($externalData['uncertainties'] as $uncertainty)
                                <li>{{ $uncertainty }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
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
                <h3 class="text-base font-semibold text-gray-900">Foto’s en bestanden</h3>
                @if ($photoGroups === [])
                    <p class="text-sm text-gray-500">Nog geen foto’s of bestanden geüpload.</p>
                @else
                    <div class="space-y-6">
                        @foreach ($photoGroups as $group)
                            <div class="space-y-3">
                                <h4 class="text-sm font-medium text-gray-800">{{ $group['heading'] }}</h4>
                                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                                    @foreach ($group['uploads'] as $item)
                                        @if (str_starts_with($item['upload']->mime_type, 'image/'))
                                            <figure class="space-y-2">
                                                <a href="{{ route('installer.uploads.show', [$intake, $item['upload']]) }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-md border border-gray-200">
                                                    <img
                                                        src="{{ route('installer.uploads.show', [$intake, $item['upload']]) }}"
                                                        alt="{{ $item['caption'] }}"
                                                        class="aspect-square w-full object-cover"
                                                    >
                                                </a>
                                                <figcaption class="text-xs text-gray-500">
                                                    {{ $item['caption'] }}
                                                    @if ($item['upload']->usability_verdict && $item['upload']->usability_verdict->installerLabel())
                                                        <span class="mt-0.5 inline-flex items-center rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-800" title="Automatische indicatie — niet bindend">
                                                            ⚠ {{ $item['upload']->usability_verdict->installerLabel() }}
                                                        </span>
                                                    @endif
                                                </figcaption>
                                            </figure>
                                        @else
                                            <div class="min-w-0 rounded-md border border-gray-200 bg-gray-50 p-4">
                                                <a href="{{ route('installer.uploads.show', [$intake, $item['upload']]) }}" target="_blank" rel="noopener" class="block truncate text-sm font-semibold text-indigo-600 underline decoration-indigo-200 underline-offset-2 hover:text-indigo-800">
                                                    {{ $item['upload']->original_filename }}
                                                </a>
                                                <p class="mt-1 text-xs text-gray-500">{{ $item['caption'] }}</p>
                                                <p class="mt-1 text-xs text-gray-400">PDF · {{ number_format($item['upload']->size_bytes / 1024, 0, ',', '.') }} KB</p>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            @php
                $authoritativePoints = $intake->attentionPoints->filter(
                    fn ($p) => $p->status === null || $p->status === \App\Enums\AttentionPointStatus::Accepted
                );
                $proposedPoints = $intake->attentionPoints->filter(
                    fn ($p) => $p->source === \App\Enums\AttentionPointSource::Ai
                        && $p->status === \App\Enums\AttentionPointStatus::Proposed
                );
            @endphp

            @if ($authoritativePoints->isNotEmpty())
                <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                    <h3 class="text-base font-semibold text-gray-900">Aandachtspunten</h3>
                    <ul class="list-disc space-y-1 pl-5 text-sm text-gray-800">
                        @foreach ($authoritativePoints as $point)
                            <li>
                                {{ $point->label }}
                                @if ($point->source === \App\Enums\AttentionPointSource::Ai)
                                    <span class="text-gray-400">· overgenomen AI-voorstel</span>
                                @endif
                                @if ($point->is_resolved)
                                    <span class="text-gray-500">(opgelost)</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">AI-voorgestelde aandachtspunten</h3>
                        <p class="text-xs text-gray-500">Niet bindend — u beslist wat u overneemt.</p>
                    </div>
                    <form method="POST" action="{{ route('intakes.attention.suggest', $intake) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 hover:bg-gray-50">
                            {{ $proposedPoints->isEmpty() ? 'AI-aandachtspunten voorstellen' : 'Opnieuw voorstellen' }}
                        </button>
                    </form>
                </div>

                @if ($proposedPoints->isNotEmpty())
                    <ul class="divide-y divide-gray-100">
                        @foreach ($proposedPoints as $point)
                            <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <span class="text-sm text-gray-800">{{ $point->label }}</span>
                                <span class="flex shrink-0 gap-2">
                                    <form method="POST" action="{{ route('intakes.attention.accept', [$intake, $point]) }}">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">Accepteren</button>
                                    </form>
                                    <form method="POST" action="{{ route('intakes.attention.dismiss', [$intake, $point]) }}">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50">Verwijderen</button>
                                    </form>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-gray-500">Geen openstaande AI-voorstellen.</p>
                @endif
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                @include('installer.intakes._pipe-route')
            </div>

            @if ($intake->report)
                <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">Rapport</h3>
                            <p class="text-xs text-gray-500">
                                Gegenereerd {{ $intake->report->generated_at?->timezone(config('app.timezone'))->format('d-m-Y H:i') }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($intake->report->hasPdf())
                                <a
                                    href="{{ route('intakes.pdf', $intake) }}"
                                    class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700"
                                >
                                    Download PDF
                                </a>
                            @else
                                <span class="inline-flex items-center rounded-md bg-gray-100 px-3 py-2 text-xs font-medium text-gray-600">
                                    PDF wordt voorbereid…
                                </span>
                            @endif
                            <form method="POST" action="{{ route('intakes.pdf.regenerate', $intake) }}">
                                @csrf
                                <x-secondary-button type="submit">
                                    {{ $intake->report->hasPdf() ? 'PDF opnieuw genereren' : 'PDF genereren' }}
                                </x-secondary-button>
                            </form>
                        </div>
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
                        src="{{ route('intakes.report', $intake) }}"
                        sandbox="allow-same-origin"
                        class="h-[32rem] w-full rounded-md border border-gray-200 bg-white"
                    ></iframe>
                </div>
            @endif

            @if (in_array($intake->status, [\App\Enums\IntakeStatus::Completed, \App\Enums\IntakeStatus::Reviewed, \App\Enums\IntakeStatus::AwaitingCustomer], true))
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

                    @if ($intake->followUpRounds->isNotEmpty())
                        <div class="space-y-4 border-t border-gray-100 pt-4">
                            <h4 class="text-sm font-semibold text-gray-900">Aanvullende informatierondes</h4>
                            @foreach ($intake->followUpRounds as $round)
                                <section class="border-l-2 border-indigo-200 pl-4">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <h5 class="text-sm font-semibold text-gray-900">Ronde {{ $round->round_number }}</h5>
                                        <span class="text-xs font-medium text-gray-500">
                                            {{ $round->completed_at ? 'Aangevuld '.$round->completed_at->timezone(config('app.timezone'))->format('d-m-Y H:i') : 'Wacht op klant' }}
                                        </span>
                                    </div>
                                    <ol class="mt-3 space-y-4">
                                        @foreach ($round->items as $item)
                                            <li class="text-sm">
                                                <p class="font-medium text-gray-900">{{ $item->prompt }}</p>
                                                @if ($item->type === \App\Enums\FollowUpItemType::Text)
                                                    <p class="mt-1 whitespace-pre-wrap text-gray-700">{{ $item->response_text ?: 'Nog niet beantwoord' }}</p>
                                                @elseif ($item->uploads->isEmpty())
                                                    <p class="mt-1 text-gray-500">
                                                        {{ $item->type === \App\Enums\FollowUpItemType::Photo ? 'Nog geen foto aangeleverd' : 'Nog geen document aangeleverd' }}
                                                    </p>
                                                @elseif ($item->type === \App\Enums\FollowUpItemType::Photo)
                                                    <ul class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                                        @foreach ($item->uploads as $upload)
                                                            <li>
                                                                <a href="{{ route('installer.uploads.show', [$intake, $upload]) }}" target="_blank" rel="noopener" class="block">
                                                                    <img src="{{ route('installer.uploads.show', [$intake, $upload]) }}" alt="Aanvullende foto" class="aspect-square w-full rounded-md border border-gray-200 object-cover">
                                                                </a>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                @else
                                                    <ul class="mt-2 space-y-2">
                                                        @foreach ($item->uploads as $upload)
                                                            <li>
                                                                <a href="{{ route('installer.uploads.show', [$intake, $upload]) }}" target="_blank" rel="noopener" class="font-medium text-indigo-600 underline decoration-indigo-200 underline-offset-2 hover:text-indigo-800">
                                                                    {{ $upload->original_filename }}
                                                                </a>
                                                                <span class="text-xs text-gray-500">· PDF · {{ number_format($upload->size_bytes / 1024, 0, ',', '.') }} KB</span>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ol>
                                </section>
                            @endforeach
                        </div>
                    @endif

                    @if ($intake->status !== \App\Enums\IntakeStatus::AwaitingCustomer)
                    @php
                        $initialDecision = old('decision', $intake->review?->decision?->value ?? '');
                        $oldFollowUpItems = array_values(old('follow_up_items', [['type' => 'text', 'prompt' => '']]));
                        $followUpCount = max(1, count($oldFollowUpItems));
                        $followUpRows = array_pad($oldFollowUpItems, (int) config('intake.follow_up.max_items_per_round', 5), ['type' => 'text', 'prompt' => '']);
                    @endphp
                    <form
                        method="POST"
                        action="{{ route('intakes.review', $intake) }}"
                        class="space-y-4 border-t border-gray-100 pt-4"
                        x-data="{ decision: {{ \Illuminate\Support\Js::from($initialDecision) }}, followUpCount: {{ $followUpCount }} }"
                    >
                        @csrf
                        <div>
                            <x-input-label for="decision" value="Beslissing" />
                            <select id="decision" name="decision" x-model="decision" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
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
                            <label class="inline-flex items-center gap-2" x-show="decision !== '{{ \App\Enums\ReviewDecision::NeedMoreInfo->value }}'">
                                <input type="checkbox" name="enough_information" value="1" :disabled="decision === '{{ \App\Enums\ReviewDecision::NeedMoreInfo->value }}'" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" @checked(old('enough_information', $intake->review?->enough_information ?? true))>
                                <span>Voldoende informatie</span>
                            </label>
                        </div>

                        <div>
                            <x-input-label for="summary" value="Samenvatting (optioneel)" />
                            <textarea id="summary" name="summary" rows="4" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('summary', $intake->review?->summary) }}</textarea>
                            <x-input-error :messages="$errors->get('summary')" class="mt-2" />
                        </div>

                        <div x-cloak x-show="decision === '{{ \App\Enums\ReviewDecision::NeedMoreInfo->value }}'" class="space-y-3 border-l-2 border-indigo-200 pl-4">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-900">Wat ontbreekt nog?</h4>
                            </div>

                            @foreach ($followUpRows as $index => $item)
                                <div x-show="followUpCount > {{ $index }}" class="grid gap-2 sm:grid-cols-[10rem_1fr]">
                                    <select
                                        name="follow_up_items[{{ $index }}][type]"
                                        class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        :required="decision === '{{ \App\Enums\ReviewDecision::NeedMoreInfo->value }}' && followUpCount > {{ $index }}"
                                    >
                                        @foreach (\App\Enums\FollowUpItemType::cases() as $type)
                                            <option value="{{ $type->value }}" @selected(($item['type'] ?? 'text') === $type->value)>{{ $type->label() }}</option>
                                        @endforeach
                                    </select>
                                    <textarea
                                        name="follow_up_items[{{ $index }}][prompt]"
                                        rows="2"
                                        maxlength="500"
                                        placeholder="Concrete vraag, foto- of documentopdracht"
                                        class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        :required="decision === '{{ \App\Enums\ReviewDecision::NeedMoreInfo->value }}' && followUpCount > {{ $index }}"
                                    >{{ $item['prompt'] ?? '' }}</textarea>
                                </div>
                            @endforeach

                            <button
                                type="button"
                                x-show="followUpCount < {{ (int) config('intake.follow_up.max_items_per_round', 5) }}"
                                x-on:click="followUpCount++"
                                class="text-sm font-semibold text-indigo-700 hover:text-indigo-900"
                            >
                                Vraag toevoegen
                            </button>
                            <x-input-error :messages="$errors->get('follow_up_items')" class="mt-2" />
                            <x-input-error :messages="$errors->get('follow_up_items.*.type')" class="mt-2" />
                            <x-input-error :messages="$errors->get('follow_up_items.*.prompt')" class="mt-2" />
                        </div>

                        <x-primary-button>
                            {{ $intake->review ? 'Beoordeling bijwerken' : 'Beoordeling opslaan' }}
                        </x-primary-button>
                    </form>
                    @endif
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
