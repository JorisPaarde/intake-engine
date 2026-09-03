<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $intake->customer_name }}</h2>
            <div class="flex items-center gap-3">
                <a href="{{ $workspaceUrl }}{{ $primaryAction['href'] }}" class="inline-flex min-h-10 items-center rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-500">Opname openen</a>
                <a href="{{ route('dashboard') }}" class="text-sm text-gray-600 hover:text-gray-900">← Overzicht</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            @if ($intake->is_demo && ! session('public_demo_path_chosen'))
                <div class="rounded-2xl border border-sky-200 bg-sky-50 px-5 py-4 text-sm text-sky-950" data-demo-anchor="branch-panel">
                    <p class="font-semibold">Demo: adresgegevens staan al in de opname</p>
                    <p class="mt-1 text-sky-900/80">Bekijk hieronder de opgehaalde woninggegevens. Doe de opname zelf, of bekijk wat de klant ziet. Er gaat geen e-mail uit in de demo.</p>
                </div>
            @endif

            @php
                $openAreas = $dossier['blockers'] ?? collect();
                $areaTargetResolver = app(\App\Domains\Intake\Services\WorkspacePrimaryActionResolver::class);
                $firstOpenArea = $areaTargetResolver->firstActionableOpenArea($openAreas);
                $firstOpenTarget = $firstOpenArea
                    ? $areaTargetResolver->targetForArea($intake, $firstOpenArea->key)
                    : null;
                $authoritativePoints = $intake->attentionPoints->filter(
                    fn ($p) => ($p->status === null || $p->status === \App\Enums\AttentionPointStatus::Accepted)
                        && ! $p->is_resolved,
                );
                $resolvedPoints = $intake->attentionPoints->filter(
                    fn ($p) => ($p->status === null || $p->status === \App\Enums\AttentionPointStatus::Accepted)
                        && $p->is_resolved,
                );
                $proposedPoints = $intake->attentionPoints->filter(
                    fn ($p) => $p->source === \App\Enums\AttentionPointSource::Ai
                        && $p->status === \App\Enums\AttentionPointStatus::Proposed,
                );
                $showAttentionSection = $authoritativePoints->isNotEmpty()
                    || $proposedPoints->isNotEmpty()
                    || $resolvedPoints->isNotEmpty()
                    || $aiAttentionAvailable;
                $mapQuery = rawurlencode($intake->fullAddress());
                $phoneDigits = $intake->customer_phone
                    ? preg_replace('/[^\d+]/', '', $intake->customer_phone)
                    : null;
                $addressVerification = $intake->externalFacts->first(
                    fn ($fact) => $fact->fact_key === 'address_verification'
                        && $fact->source === \App\Domains\Intake\Services\PdokAddressService::sourceName()
                );
                $addressVerificationStatus = $addressVerification?->value['status'] ?? null;
            @endphp

            <section class="min-w-0 rounded-2xl border border-indigo-100 bg-indigo-50/40 p-6 shadow-sm space-y-4" aria-labelledby="detail-next-action">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 id="detail-next-action" class="text-base font-semibold text-gray-950">Wat nu te doen</h3>
                        <p class="mt-1 text-sm text-gray-700">{{ $primaryAction['summary'] }}</p>
                    </div>
                    <div class="shrink-0 text-right text-sm">
                        <p class="font-semibold tabular-nums text-gray-900">Klaar voor offerte: {{ $dossier['ready_count'] }}/{{ $dossier['total_count'] }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="inline-flex rounded-full bg-white px-2.5 py-0.5 text-xs font-medium text-gray-800">
                        {{ $intake->status->label() }}
                    </span>
                    <span class="text-gray-500">Klanttaak: {{ $intake->progress_percent }}% beantwoord</span>
                    <span class="text-gray-500">{{ $intake->workflow_mode->label() }}</span>
                </div>

                <a
                    href="{{ $workspaceUrl }}{{ $primaryAction['href'] }}"
                    class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-500 sm:w-auto"
                >
                    {{ $primaryAction['label'] }}
                </a>

                @if ($openAreas->isNotEmpty())
                    <div class="rounded-xl border border-amber-200 bg-white p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-amber-950">Open punten</p>
                            @if ($firstOpenTarget)
                                <a
                                    href="{{ $workspaceUrl }}{{ $firstOpenTarget['href'] }}"
                                    class="text-xs font-semibold text-indigo-700 hover:text-indigo-900"
                                >
                                    Volgende open punt →
                                </a>
                            @endif
                        </div>
                        <ul class="mt-3 space-y-2">
                            @foreach ($openAreas as $area)
                                @php $areaTarget = $areaTargetResolver->targetForArea($intake, $area->key); @endphp
                                <li>
                                    <a
                                        href="{{ $workspaceUrl }}{{ $areaTarget['href'] }}"
                                        class="block rounded-lg border border-amber-100 px-3 py-2 transition hover:border-amber-300 hover:bg-amber-50/60"
                                    >
                                        <span class="text-sm font-medium text-gray-950">{{ $area->label }}</span>
                                        @if ($area->blocker)
                                            <span class="mt-0.5 block text-xs text-gray-600">{{ $area->blocker }}</span>
                                        @endif
                                        <span class="mt-1 block text-xs font-semibold text-indigo-700">{{ $areaTarget['label'] }} →</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @elseif ($dossier['quote']?->next_action)
                    <p class="text-sm text-gray-700">
                        Volgende actie: {{ $dossier['quote']->next_action->label() }}.
                        @if ($dossier['quote']?->blocker)
                            {{ $dossier['quote']->blocker }}
                        @endif
                    </p>
                @endif

                <dl class="grid grid-cols-1 gap-4 border-t border-indigo-100 pt-4 sm:grid-cols-2 text-sm">
                    <div>
                        <dt class="text-gray-500">E-mail</dt>
                        <dd class="break-words text-gray-900">
                            <a href="mailto:{{ $intake->customer_email }}" class="font-medium text-indigo-700 underline decoration-indigo-200 underline-offset-2 hover:text-indigo-900">{{ $intake->customer_email }}</a>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Telefoon</dt>
                        <dd class="text-gray-900">
                            @if ($intake->customer_phone && $phoneDigits)
                                <a href="tel:{{ $phoneDigits }}" class="font-medium text-indigo-700 underline decoration-indigo-200 underline-offset-2 hover:text-indigo-900">{{ $intake->customer_phone }}</a>
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-gray-500">Adres</dt>
                        <dd class="break-words text-gray-900">
                            @if ($intake->fullAddress() !== '')
                                <a href="https://www.google.com/maps/search/?api=1&amp;query={{ $mapQuery }}" target="_blank" rel="noopener" class="font-medium text-indigo-700 underline decoration-indigo-200 underline-offset-2 hover:text-indigo-900">{{ $intake->fullAddress() }}</a>
                            @else
                                —
                            @endif
                        </dd>
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

                <div class="border-t border-indigo-100 pt-4">
                    <h4 class="text-sm font-semibold text-gray-900">Korte samenvatting</h4>
                    <p class="mt-1 break-words text-sm text-gray-700">{{ $dossierSummary }}</p>
                </div>
            </section>

            <details class="min-w-0 rounded-2xl border border-gray-200 bg-white shadow-sm">
                <summary class="cursor-pointer list-none px-6 py-4 text-base font-semibold text-gray-900 [&::-webkit-details-marker]:hidden">
                    Woninggegevens
                    <span class="mt-1 block text-xs font-normal text-gray-500">Automatisch opgehaald · tik om te openen</span>
                </summary>
                <div class="space-y-4 border-t border-gray-100 px-6 pb-6 pt-4">
                    <p class="text-xs text-gray-500">Automatisch opgehaald voor deze opname. Hier staan alleen gegevens die kunnen helpen bij de installatie.</p>
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
                    <p class="text-sm text-gray-500">Nog geen relevante woninggegevens gevonden.</p>
                @endif

                @if ($externalData['aerial_image'])
                    <details class="group rounded-lg border border-gray-200 bg-gray-50">
                        <summary class="cursor-pointer list-none px-4 py-3 text-sm font-medium text-gray-800">
                            <span class="inline-flex items-center gap-2">
                                <span>Luchtfoto van de omgeving bekijken</span>
                                <span class="text-gray-400 transition group-open:rotate-180" aria-hidden="true">⌄</span>
                            </span>
                        </summary>
                        <figure class="space-y-2 border-t border-gray-200 p-4">
                            <div class="relative aspect-[3/2] max-w-3xl overflow-hidden rounded-md border border-gray-200 bg-gray-100">
                                <img
                                    src="{{ $externalData['aerial_image']['data_uri'] }}"
                                    alt="Luchtfoto rond de BAG-locatie van deze opname"
                                    class="h-full w-full object-cover"
                                >
                                <span class="pointer-events-none absolute left-1/2 top-1/2 h-5 w-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-red-600 shadow" aria-hidden="true"></span>
                            </div>
                            <figcaption class="text-xs text-gray-500">
                                Omgevingsbeeld met de BAG-locatie rood gemarkeerd
                                @if ($externalData['aerial_image']['ground_width_meters'] && $externalData['aerial_image']['ground_height_meters'])
                                    · circa {{ $externalData['aerial_image']['ground_width_meters'] }} × {{ $externalData['aerial_image']['ground_height_meters'] }} meter
                                @endif
                                ·
                                @if ($externalData['aerial_image']['source_url'])
                                    <a href="{{ $externalData['aerial_image']['source_url'] }}" target="_blank" rel="noopener" class="underline hover:text-gray-700">{{ $externalData['aerial_image']['source'] }}</a>
                                @else
                                    {{ $externalData['aerial_image']['source'] }}
                                @endif
                            </figcaption>
                        </figure>
                    </details>
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

                @if (in_array($addressVerificationStatus, ['not_found', 'unavailable'], true))
                    <form method="POST" action="{{ route('intakes.address-enrichment.retry', $intake) }}">
                        @csrf
                        <x-secondary-button type="submit">Adres opnieuw controleren</x-secondary-button>
                    </form>
                @endif
                </div>
            </details>

            @if ($showAttentionSection || ! $aiAttentionAvailable)
                <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Aandachtspunten</h3>
                        <p class="text-xs text-gray-500">Overgenomen signalen en AI-voorstellen. Jij beslist wat je overneemt.</p>
                    </div>

                    @if ($authoritativePoints->isNotEmpty())
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Actueel</p>
                            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-800">
                                @foreach ($authoritativePoints as $point)
                                    <li>
                                        {{ $point->label }}
                                        @if ($point->source === \App\Enums\AttentionPointSource::Ai)
                                            <span class="text-gray-400">· overgenomen AI-voorstel</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($proposedPoints->isNotEmpty())
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">AI-voorstel · accepteren of verwijderen</p>
                            <ul class="mt-2 divide-y divide-gray-100">
                                @foreach ($proposedPoints as $point)
                                    <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div class="min-w-0 text-sm text-gray-800">
                                            <p>{{ $point->label }}</p>
                                            @if ($point->ai_confidence)
                                                <p class="mt-1 text-xs text-gray-500">
                                                    Zekerheid: {{ match ($point->ai_confidence) { 'high' => 'hoog', 'medium' => 'middel', 'low' => 'laag', default => $point->ai_confidence } }}
                                                </p>
                                            @endif
                                            @if (is_array($point->evidence) && $point->evidence !== [])
                                                <ul class="mt-1 space-y-0.5 text-xs text-gray-500">
                                                    @foreach ($point->evidence as $evidence)
                                                        <li>
                                                            {{ match ($evidence['source_type'] ?? '') {
                                                                'answer' => 'klantantwoord',
                                                                'external_fact' => 'extern feit',
                                                                'upload' => 'upload',
                                                                'follow_up' => 'aanvulling',
                                                                'installer_review' => 'installateursbeoordeling',
                                                                'pipe_route' => 'leidingroute',
                                                                'system_attention_point' => 'systeemsignaal',
                                                                default => 'dossierbron',
                                                            } }} · <code>{{ $evidence['reference'] ?? 'onbekend' }}</code>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </div>
                                        <span class="flex shrink-0 gap-2">
                                            @if ($point->hasValidAiProvenance())
                                                <form method="POST" action="{{ route('intakes.attention.accept', [$intake, $point]) }}">
                                                    @csrf
                                                    <button type="submit" class="inline-flex items-center rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">Accepteren</button>
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ route('intakes.attention.dismiss', [$intake, $point]) }}">
                                                @csrf
                                                <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50">Verwijderen</button>
                                            </form>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @elseif ($aiAttentionAvailable && ! $attentionAiSucceeded)
                        <div class="rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950">
                            <p>AI kan aandachtspunten voorstellen zodra er genoeg dossierinhoud is.</p>
                            <form method="POST" action="{{ route('intakes.attention.suggest', $intake) }}" class="mt-3">
                                @csrf
                                <button type="submit" class="inline-flex items-center rounded-md bg-sky-700 px-3 py-2 text-xs font-semibold text-white hover:bg-sky-800">
                                    AI-aandachtspunten voorstellen
                                </button>
                            </form>
                        </div>
                    @elseif ($aiAttentionAvailable)
                        <p class="text-sm text-gray-500">Geen openstaande AI-voorstellen.</p>
                    @else
                        <p class="text-sm text-gray-500">AI staat uit in deze omgeving. Aandachtspunten worden automatisch voorgesteld zodra AI is geactiveerd.</p>
                    @endif

                    @if ($resolvedPoints->isNotEmpty())
                        <details class="rounded-lg border border-gray-200 bg-gray-50">
                            <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-gray-700">
                                Opgeloste punten ({{ $resolvedPoints->count() }})
                            </summary>
                            <ul class="list-disc space-y-1 border-t border-gray-200 px-4 py-3 pl-9 text-sm text-gray-600">
                                @foreach ($resolvedPoints as $point)
                                    <li>{{ $point->label }}</li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </div>
            @endif

            @if ($intake->customer_access_enabled)
            <details class="rounded-2xl border border-gray-200 bg-white shadow-sm" x-data="{ copied: false }">
                <summary class="cursor-pointer list-none px-6 py-4 text-base font-semibold text-gray-900 [&::-webkit-details-marker]:hidden">
                    Klantlink
                    <span class="mt-1 block text-xs font-normal text-gray-500">Kopieer of beheer de link · tik om te openen</span>
                </summary>
                <div class="space-y-4 border-t border-gray-100 px-6 pb-6 pt-4">
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
            </details>
            @else
                <div class="rounded-lg border border-indigo-100 bg-indigo-50 p-6">
                    <h3 class="text-base font-semibold text-indigo-950">Geen klantlink actief</h3>
                    <p class="mt-1 text-sm text-indigo-900">Deze opname wordt door de installateur uitgevoerd. Vanuit de opname kun je later één of meer concrete klantopdrachten sturen; pas dan wordt de beveiligde link geactiveerd.</p>
                    <a href="{{ $workspaceUrl }}{{ $primaryAction['href'] }}" class="mt-4 inline-flex min-h-10 items-center rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-500">{{ $primaryAction['label'] }}</a>
                </div>
            @endif

            <details class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                <summary class="cursor-pointer list-none px-6 py-4 text-base font-semibold text-gray-900 [&::-webkit-details-marker]:hidden">
                    Foto’s en bestanden
                    <span class="mt-1 block text-xs font-normal text-gray-500">Galerij · tik om te openen</span>
                </summary>
                <div class="space-y-4 border-t border-gray-100 px-6 pb-6 pt-4">
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
                                                <figcaption class="space-y-2 text-xs text-gray-500">
                                                    <p>{{ $item['caption'] }}</p>
                                                    @if ($item['upload']->usability_verdict && $item['upload']->usability_verdict->installerLabel())
                                                        <div class="space-y-2">
                                                            <span class="inline-flex items-center rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-800" title="Automatische indicatie — niet bindend">
                                                                ⚠ {{ $item['upload']->usability_verdict->installerLabel() }}
                                                            </span>
                                                            @php
                                                                $photoSubjectId = preg_match('/^subject-(\d+)$/', (string) $item['upload']->section_instance_key, $subjectMatches)
                                                                    ? (int) $subjectMatches[1]
                                                                    : null;
                                                                $photoPrompt = \Illuminate\Support\Str::limit(
                                                                    'Maak een scherpere, goed belichte foto: '.$item['caption'],
                                                                    500,
                                                                    '',
                                                                );
                                                            @endphp
                                                            <form method="POST" action="{{ route('intakes.workspace.tasks.quick', $intake) }}">
                                                                @csrf
                                                                <input type="hidden" name="type" value="{{ \App\Enums\FollowUpItemType::Photo->value }}">
                                                                <input type="hidden" name="prompt" value="{{ $photoPrompt }}">
                                                                @if ($photoSubjectId)
                                                                    <input type="hidden" name="dossier_subject_id" value="{{ $photoSubjectId }}">
                                                                @endif
                                                                <button type="submit" class="inline-flex min-h-8 items-center rounded-md border border-amber-300 bg-white px-2 py-1 text-[11px] font-semibold text-amber-900 hover:bg-amber-50">
                                                                    Vraag betere foto
                                                                </button>
                                                            </form>
                                                        </div>
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
            </details>

            @if ($intake->is_demo)
                <x-demo-pdf-request :intake="$intake" />
            @endif

            @if ($intake->report)
                @php
                    $aiSummary = is_array($intake->report->meta['ai_summary'] ?? null)
                        ? $intake->report->meta['ai_summary']
                        : null;
                    $pdfReady = $intake->report->hasPdf();
                @endphp
                <details class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <summary class="cursor-pointer list-none px-6 py-4 [&::-webkit-details-marker]:hidden">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">Rapport</h3>
                                <p class="text-xs text-gray-500">
                                    Gegenereerd {{ $intake->report->generated_at?->timezone(config('app.timezone'))->format('d-m-Y H:i') }}
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if ($intake->is_demo)
                                    @if ($pdfReady)
                                        <a
                                            href="{{ route('intakes.pdf', $intake) }}"
                                            class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700"
                                        >
                                            Download PDF
                                        </a>
                                    @else
                                        <span class="inline-flex items-center rounded-md bg-gray-100 px-3 py-2 text-xs font-medium text-gray-600">
                                            PDF op aanvraag via het formulier hierboven
                                        </span>
                                    @endif
                                @else
                                    @if ($pdfReady)
                                        <a
                                            href="{{ route('intakes.pdf', $intake) }}"
                                            class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700"
                                        >
                                            Download PDF
                                        </a>
                                    @else
                                        <span class="inline-flex items-center rounded-md bg-amber-100 px-3 py-2 text-xs font-medium text-amber-900">
                                            PDF wordt voorbereid…
                                        </span>
                                    @endif
                                    <a
                                        href="{{ route('intakes.show', $intake) }}"
                                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                                    >
                                        Status verversen
                                    </a>
                                    <form method="POST" action="{{ route('intakes.pdf.regenerate', $intake) }}">
                                        @csrf
                                        <x-secondary-button type="submit">
                                            {{ $pdfReady ? 'PDF opnieuw genereren' : 'PDF genereren' }}
                                        </x-secondary-button>
                                    </form>
                                @endif
                            </div>
                        </div>
                        @if (! $pdfReady && ! $intake->is_demo)
                            <p class="mt-2 text-xs text-gray-500">Ververs de status over een moment om te controleren of de PDF klaar is.</p>
                        @endif
                    </summary>
                    <div class="space-y-4 border-t border-gray-100 px-6 pb-6 pt-4">
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

                        <div class="flex flex-wrap gap-2">
                            <a
                                href="{{ route('intakes.report', $intake) }}"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-500"
                            >
                                Rapport openen
                            </a>
                        </div>

                        <details class="rounded-lg border border-gray-200">
                            <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-gray-800">
                                Voorbeeld op deze pagina
                            </summary>
                            <iframe
                                title="Opnamerapport"
                                data-lazy-report
                                data-src="{{ route('intakes.report', $intake) }}"
                                sandbox="allow-same-origin"
                                loading="lazy"
                                class="h-[32rem] w-full border-0 bg-white"
                            ></iframe>
                        </details>
                    </div>
                </details>
                <script>
                    document.querySelectorAll('details').forEach(function (details) {
                        details.addEventListener('toggle', function () {
                            if (! details.open) {
                                return;
                            }

                            details.querySelectorAll('iframe[data-lazy-report]').forEach(function (iframe) {
                                if (! iframe.src && iframe.dataset.src) {
                                    iframe.src = iframe.dataset.src;
                                }
                            });
                        });
                    });
                </script>
            @endif

            @if (in_array($intake->status, [\App\Enums\IntakeStatus::Completed, \App\Enums\IntakeStatus::Reviewed, \App\Enums\IntakeStatus::AwaitingCustomer], true))
                <details class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <summary class="cursor-pointer list-none px-6 py-4 text-base font-semibold text-gray-900 [&::-webkit-details-marker]:hidden">
                        Beoordeling
                        <span class="mt-1 block text-xs font-normal text-gray-500">Beslissing en aanvullende rondes · tik om te openen</span>
                    </summary>
                    <div class="space-y-4 border-t border-gray-100 px-6 pb-6 pt-4">
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
                </details>
            @endif
        </div>
    </div>

    @if ($intake->is_demo && (bool) session('public_demo_mode', false))
        <x-demo-guide
            :step="session('demo_coachmark', session('public_demo_path_chosen') ? null : 'branch')"
            :has-intake="true"
            :intake="$intake"
        />
    @endif
</x-app-layout>
