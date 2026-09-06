<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500">{{ $intake->customer_name }} · {{ $intake->fullAddress() }}</p>
                <h2 class="mt-1 text-xl font-semibold leading-tight text-gray-900">Opname</h2>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('intakes.show', $intake) }}" class="inline-flex min-h-11 items-center rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                    Aanvraaggegevens
                </a>
                @if ($intake->customer_access_enabled && $intake->isTokenValid())
                    <a href="{{ $intake->customerUrl() }}" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                        Klantweergave
                    </a>
                @endif
            </div>
        </div>
    </x-slot>

    @php
        $quoteArea = $dossier['quote'];
        $selectedOption = $intake->aircoInstallationOptions->first(
            fn ($option) => $option->status === \App\Enums\AircoOptionStatus::Selected
        );
        $rootSubject = $intake->dossierSubjects->firstWhere('key', 'survey');
        $aiSynthesis = $rootSubject?->records
            ?->where('key', 'ai_dossier_synthesis')
            ->sortByDesc('id')
            ->first();
        $proposedCustomerTasks = $intake->contributionTasks
            ->where('status', \App\Enums\ContributionTaskStatus::Proposed);
        $proposalAlreadyApproved = $selectedOption
            && in_array($intake->status, [
                \App\Enums\IntakeStatus::Completed,
                \App\Enums\IntakeStatus::Reviewed,
            ], true)
            && $selectedOption->connections->isNotEmpty()
            && $selectedOption->connections->every(
                fn ($connection) => $connection->status === \App\Enums\AircoConnectionStatus::Approved
                    && (! $connection->routeSession
                        || $connection->routeSession->status === \App\Enums\PipeRouteStatus::Approved)
            );
        $canApproveProposal = ! $proposalAlreadyApproved
            && $selectedOption
            && in_array($quoteArea?->status, [
                \App\Enums\DecisionAreaStatus::Ready,
                \App\Enums\DecisionAreaStatus::Review,
            ], true);
        $openAreas = $dossier['areas']->filter(
            static fn ($area): bool => in_array($area->status, [
                \App\Enums\DecisionAreaStatus::Blocked,
                \App\Enums\DecisionAreaStatus::Review,
            ], true),
        )->values();
        $aiExceptions = is_array($aiSynthesis?->value['exceptions'] ?? null)
            ? $aiSynthesis->value['exceptions']
            : [];
        $aiSectionOpen = $aiExceptions !== [];
        $photoCount = collect($photoGroups ?? [])->sum(
            static fn (array $group): int => count($group['uploads'] ?? []),
        );
        $factCount = count($externalData['facts'] ?? []);
        $primaryAction = app(\App\Domains\Intake\Services\WorkspacePrimaryActionResolver::class)->resolve(
            $intake,
            $quoteArea,
            $canApproveProposal,
            $proposalAlreadyApproved,
            $proposedCustomerTasks,
            $openAreas,
        );
        $primaryCtaHref = $primaryAction['href'];
        $primaryCtaLabel = $primaryAction['label'];
        $primarySummary = $primaryAction['summary'];
        $areaTargetResolver = app(\App\Domains\Intake\Services\WorkspacePrimaryActionResolver::class);
        $customerTaskBuilder = app(\App\Domains\Intake\Services\ContextualCustomerTaskBuilder::class);
        $firstActionableOpenKey = $areaTargetResolver->firstActionableOpenArea($openAreas)?->key;
        $hasOpenPoints = $openAreas->isNotEmpty();
        $customerTaskDraft = is_array($customerTaskDraft ?? null) ? $customerTaskDraft : null;
        $hasCustomerTaskDraft = $customerTaskDraft !== null;
    @endphp

    <div class="py-6 sm:py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900" role="status">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">
                    <p class="font-semibold">Dit onderdeel kon nog niet worden opgeslagen.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($intake->is_demo)
                @php
                    $demoWorkStarted = ($demoScenarioLoaded ?? false)
                        || $photoCount > 0
                        || $intake->aircoPlacements->isNotEmpty()
                        || $intake->aircoInstallationOptions->isNotEmpty();
                    $showSampleDossierCta = ! ($demoScenarioLoaded ?? false) && ! $demoWorkStarted;
                @endphp
                <section id="demo-intro" class="overflow-hidden rounded-3xl border border-sky-200 bg-sky-50 shadow-sm" data-demo-anchor="workspace-intro">
                    <div class="grid gap-5 p-5 sm:p-6 lg:grid-cols-[1fr_auto] lg:items-center">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-sky-700">Demo</p>
                            <h3 class="mt-2 text-xl font-semibold text-gray-950">
                                {{ ($demoScenarioLoaded ?? false) ? 'Voorbeelddossier geladen' : 'Bouw de opname op' }}
                            </h3>
                            <p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-700">
                                @if ($demoScenarioLoaded ?? false)
                                    Je bekijkt voorbeeldinhoud. Je kunt dit verder bewerken of AI opnieuw laten kijken. Geen echte klant, geen mail.
                                @elseif ($demoWorkStarted)
                                    Je werkt in een opname. Adresinvulling en AI werken. Geen echte klant, geen mail.
                                @else
                                    Begin met een lege opname — net als na een echte aanvraag. Adresinvulling en AI werken. Geen echte klant, geen mail.
                                @endif
                                Demogegevens verdwijnen na {{ max(1, (int) config('intake.demo.ttl_hours', 2)) }} uur.
                            </p>
                            @if ($showSampleDossierCta)
                                <form method="POST" action="{{ route('demo.scenario.load', $intake) }}" class="mt-4">
                                    @csrf
                                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-sky-400 bg-white px-4 py-2 text-sm font-semibold text-sky-900 hover:bg-sky-100">
                                        Toon voorbeelddossier
                                    </button>
                                </form>
                                <p class="mt-2 text-xs leading-relaxed text-sky-900/70">
                                    Niet nodig om de demo af te ronden. Alleen als je snel een rijk eindbeeld wilt zien.
                                    @if ($intake->aircoRooms->isNotEmpty())
                                        Uit de aanvraag zijn al ruimtes gehaald.
                                    @endif
                                </p>
                            @endif
                        </div>
                        <a href="{{ url('/') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-sky-300 bg-white px-4 py-2 text-sm font-semibold text-sky-900 hover:bg-sky-100">
                            Terug naar website
                        </a>
                    </div>
                </section>
            @endif

            {{-- Sticky next action: compact, below demo modal z-index (BL-054/056) --}}
            <div class="sticky top-0 z-30 -mx-4 border-b border-gray-200 bg-white/95 px-4 py-2.5 shadow-sm backdrop-blur supports-[backdrop-filter]:bg-white/90 sm:-mx-6 sm:px-6 lg:mx-0 lg:rounded-2xl lg:border lg:px-4 lg:py-3 lg:shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-medium text-gray-500">Volgende stap</p>
                        <p class="truncate text-sm font-semibold text-gray-900">{{ $primarySummary }}</p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="text-xs font-semibold tabular-nums text-gray-700">{{ $dossier['filled_count'] }}/{{ $dossier['total_count'] }}</p>
                        <p class="text-[11px] font-medium leading-tight text-gray-500">met inhoud</p>
                        @if ($dossier['ready_count'] !== $dossier['filled_count'])
                            <p class="mt-0.5 text-[11px] leading-tight text-gray-400">{{ $dossier['ready_count'] }} klaar voor offerte</p>
                        @endif
                    </div>
                </div>
                <a
                    href="{{ $primaryCtaHref }}"
                    class="mt-2 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-gray-950 px-4 text-sm font-semibold text-white hover:bg-gray-800"
                >
                    {{ $primaryCtaLabel }}
                </a>
            </div>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <main class="min-w-0 space-y-6">
                    {{-- Central Alle onderdelen overview (BL-099): één lijst, geen dubbele open-puntenkaarten --}}
                    <section
                        id="workspace-open-items"
                        @class([
                            'scroll-mt-24 rounded-3xl border p-4 shadow-sm sm:p-5',
                            'border-amber-200 bg-amber-50/50' => $hasOpenPoints,
                            'border-emerald-200 bg-emerald-50/40' => ! $hasOpenPoints,
                        ])
                    >
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-gray-950">Alle onderdelen</h3>
                                <p class="mt-0.5 text-xs leading-relaxed text-gray-600">
                                    @if ($hasOpenPoints)
                                        {{ $openAreas->count() }} open · {{ $dossier['ready_count'] }} van {{ $dossier['total_count'] }} klaar voor offerte
                                    @else
                                        Geen open punten meer · {{ $dossier['ready_count'] }} van {{ $dossier['total_count'] }} klaar voor offerte
                                    @endif
                                </p>
                            </div>
                            <span class="shrink-0 text-xs font-medium text-gray-500">{{ $intake->workflow_mode->label() }}</span>
                        </div>

                        <div class="mt-3 space-y-2">
                            @foreach ($dossier['areas'] as $area)
                                @php
                                    $overviewItem = $areaTargetResolver->overviewItem($intake, $area);
                                    $expandByDefault = $hasOpenPoints && $area->key === $firstActionableOpenKey;
                                @endphp
                                <details
                                    id="dossier-area-{{ $area->key }}"
                                    @class([
                                        'min-w-0 overflow-hidden rounded-2xl border bg-white',
                                        'border-emerald-200' => $area->status === \App\Enums\DecisionAreaStatus::Ready,
                                        'border-amber-200' => $area->status === \App\Enums\DecisionAreaStatus::Review,
                                        'border-red-200' => $area->status === \App\Enums\DecisionAreaStatus::Blocked,
                                        'border-gray-200' => in_array($area->status, [
                                            \App\Enums\DecisionAreaStatus::Unknown,
                                            \App\Enums\DecisionAreaStatus::NotApplicable,
                                        ], true),
                                    ])
                                    @if ($expandByDefault) open @endif
                                >
                                    <summary class="flex cursor-pointer list-none items-start justify-between gap-3 p-3 [&::-webkit-details-marker]:hidden">
                                        <span class="min-w-0 text-sm font-semibold text-gray-950">{{ $area->label }}</span>
                                        <span @class([
                                            'shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold',
                                            'bg-emerald-100 text-emerald-900' => $area->status === \App\Enums\DecisionAreaStatus::Ready,
                                            'bg-amber-100 text-amber-900' => $area->status === \App\Enums\DecisionAreaStatus::Review,
                                            'bg-red-100 text-red-800' => $area->status === \App\Enums\DecisionAreaStatus::Blocked,
                                            'bg-gray-100 text-gray-700' => in_array($area->status, [
                                                \App\Enums\DecisionAreaStatus::Unknown,
                                                \App\Enums\DecisionAreaStatus::NotApplicable,
                                            ], true),
                                        ])>{{ $area->status->label() }}</span>
                                    </summary>
                                    <div class="min-w-0 space-y-2 border-t border-gray-100 px-3 pb-3 pt-2">
                                        @if ($overviewItem['detail'])
                                            <p class="break-words text-xs leading-relaxed text-gray-600">{{ $overviewItem['detail'] }}</p>
                                        @elseif (! $overviewItem['is_open'])
                                            <p class="text-xs text-gray-500">Geen open detail voor dit onderdeel.</p>
                                        @endif

                                        @if ($overviewItem['is_open'])
                                            <a
                                                href="{{ $overviewItem['href'] }}"
                                                class="inline-flex min-h-10 w-full items-center justify-center rounded-lg bg-gray-950 px-3 py-2 text-xs font-semibold text-white hover:bg-gray-800 sm:w-auto"
                                            >
                                                {{ $overviewItem['label'] }} →
                                            </a>

                                            @if ($overviewItem['ask_customer'] !== null)
                                                <a
                                                    href="{{ route('intakes.workspace.tasks.prepare', array_filter([
                                                        'intake' => $intake,
                                                        'type' => $overviewItem['ask_customer']['type'],
                                                        'prompt' => $overviewItem['ask_customer']['prompt'],
                                                        'decision_area_key' => $overviewItem['ask_customer']['decision_area_key'],
                                                        'dossier_subject_id' => $overviewItem['ask_customer']['dossier_subject_id'],
                                                    ], static fn (mixed $value): bool => $value !== null && $value !== '')) }}"
                                                    class="inline-flex min-h-10 w-full items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-900 hover:bg-gray-50"
                                                >
                                                    Vraag de klant
                                                </a>
                                            @endif
                                        @endif
                                    </div>
                                </details>
                            @endforeach
                        </div>
                    </section>

                    <section id="workspace-rooms" class="scroll-mt-24 rounded-3xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-950">Gewenste ruimtes</h3>
                            <p class="mt-1 text-sm text-gray-500">Kamers uit de aanvraag.</p>
                        </div>

                        <div class="mt-5 space-y-4">
                            @forelse ($intake->aircoRooms as $room)
                                @php
                                    $roomSubject = $intake->dossierSubjects->firstWhere('id', $room->dossier_subject_id);
                                    $roomMeasures = \App\Domains\Intake\Support\RoomDimensions::from(is_array($room->dimensions) ? $room->dimensions : null);
                                    $length = $roomMeasures->lengthM();
                                    $width = $roomMeasures->widthM();
                                    $height = $roomMeasures->heightM();
                                    $areaM2 = $roomMeasures->declaredAreaM2();
                                    $computedArea = $roomMeasures->areaFromLengthWidth();
                                    $hasAnyDimension = $roomMeasures->hasAnyMeasure();
                                    $floorConflict = $roomMeasures->hasFloorAreaConflict();
                                    $heightNeeded = $room->use_type === 'attic';
                                    $roomCustomerAsk = $customerTaskBuilder->forRoom($room);
                                @endphp
                                <article id="room-{{ $room->id }}" class="scroll-mt-28 rounded-2xl border border-gray-200 p-4">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <p class="text-xs text-gray-500">
                                            @if ($floorConflict)
                                                Controleer maten: L×B en m² komen niet overeen
                                            @elseif ($roomMeasures->hasLengthAndWidth())
                                                {{ number_format((float) $length, 1, ',', '.').' × '.number_format((float) $width, 1, ',', '.') }} m
                                                @if ($computedArea !== null)
                                                    <span class="text-gray-400">({{ number_format($computedArea, 1, ',', '.') }} m²)</span>
                                                @endif
                                                @if (is_numeric($height))
                                                    <span class="text-gray-400">· H {{ number_format((float) $height, 1, ',', '.') }} m</span>
                                                @endif
                                            @elseif ($roomMeasures->hasTrustedAreaM2())
                                                {{ number_format((float) $areaM2, 1, ',', '.') }} m²
                                                @if (is_numeric($height))
                                                    <span class="text-gray-400">· H {{ number_format((float) $height, 1, ',', '.') }} m</span>
                                                @endif
                                            @elseif ($roomMeasures->hasUntrustedAreaM2())
                                                {{ number_format((float) $areaM2, 1, ',', '.') }} m² — nog controleren
                                            @elseif ($hasAnyDimension)
                                                Maten deels ingevuld
                                            @else
                                                Maten nog leeg
                                            @endif
                                        </p>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600">
                                                {{ match ($room->source_type) {
                                                    'installer' => 'Door installateur toegevoegd',
                                                    'ai' => 'Door AI voorgesteld',
                                                    'customer' => 'Door klant opgegeven',
                                                    'template_bridge' => 'Uit aanvraag overgenomen',
                                                    default => 'Automatisch toegevoegd',
                                                } }}
                                            </span>
                                            @if ($roomCustomerAsk !== null)
                                                <a
                                                    href="{{ route('intakes.workspace.tasks.prepare', array_filter([
                                                        'intake' => $intake,
                                                        'type' => $roomCustomerAsk['type'],
                                                        'prompt' => $roomCustomerAsk['prompt'],
                                                        'decision_area_key' => $roomCustomerAsk['decision_area_key'],
                                                        'dossier_subject_id' => $roomCustomerAsk['dossier_subject_id'],
                                                    ], static fn (mixed $value): bool => $value !== null && $value !== '')) }}"
                                                    class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-900 hover:bg-gray-50"
                                                >
                                                    Vraag de klant
                                                </a>
                                            @endif
                                        </div>
                                    </div>

                                    @if ($floorConflict)
                                        <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                            Lengte×breedte ({{ number_format((float) $computedArea, 1, ',', '.') }} m²) en opgegeven oppervlak ({{ number_format((float) $areaM2, 1, ',', '.') }} m²) komen niet overeen. Kies één betrouwbare grondslag.
                                        </p>
                                    @elseif ($roomMeasures->hasUntrustedAreaM2() && ! $roomMeasures->hasLengthAndWidth())
                                        <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                            Oppervlak {{ number_format((float) $areaM2, 1, ',', '.') }} m² is nog niet betrouwbaar genoeg
                                            @if ($roomMeasures->areaConfidence())
                                                ({{ $roomMeasures->areaConfidence() === 'high' ? 'hoge' : ($roomMeasures->areaConfidence() === 'medium' ? 'middelmatige' : 'lage') }} zekerheid)
                                            @endif
                                            @if ($roomMeasures->areaSource())
                                                · bron: {{ $roomMeasures->areaSource() }}
                                            @endif
                                            . Bevestig of vul lengte en breedte in.
                                        </p>
                                    @endif

                                    <form method="POST" action="{{ route('intakes.workspace.rooms.update', [$intake, $room]) }}" class="mt-4 grid gap-3 rounded-xl border border-gray-100 bg-gray-50 p-3 sm:grid-cols-2">
                                        @csrf
                                        <div>
                                            <x-input-label for="room-{{ $room->id }}-name" value="Naam" />
                                            <x-text-input id="room-{{ $room->id }}-name" name="name" class="mt-1 block w-full" value="{{ $room->name }}" required />
                                        </div>
                                        <div>
                                            <x-input-label for="room-{{ $room->id }}-use" value="Gebruik" />
                                            <select id="room-{{ $room->id }}-use" name="use_type" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300">
                                                <option value="" @selected($room->use_type === null)>Nog niet vastgesteld</option>
                                                <option value="bedroom" @selected($room->use_type === 'bedroom')>Slaapkamer</option>
                                                <option value="living_room" @selected($room->use_type === 'living_room')>Woonkamer</option>
                                                <option value="office" @selected($room->use_type === 'office')>Werkkamer</option>
                                                <option value="attic" @selected($room->use_type === 'attic')>Zolder</option>
                                                <option value="other" @selected($room->use_type === 'other')>Anders</option>
                                            </select>
                                        </div>
                                        <div class="sm:col-span-2">
                                            <p class="text-xs text-gray-500">Vloeroppervlak: vul lengte en breedte in, of een betrouwbaar aantal m² — niet allebei nodig.</p>
                                        </div>
                                        <div class="grid grid-cols-2 gap-2 sm:col-span-2 sm:grid-cols-4">
                                            <div>
                                                <x-input-label for="room-{{ $room->id }}-length" value="Lengte (m)" />
                                                <x-text-input id="room-{{ $room->id }}-length" name="length_m" type="number" step="0.1" min="0.5" class="mt-1 block w-full" value="{{ $roomMeasures->lengthM() ?? '' }}" />
                                            </div>
                                            <div>
                                                <x-input-label for="room-{{ $room->id }}-width" value="Breedte (m)" />
                                                <x-text-input id="room-{{ $room->id }}-width" name="width_m" type="number" step="0.1" min="0.5" class="mt-1 block w-full" value="{{ $roomMeasures->widthM() ?? '' }}" />
                                            </div>
                                            <div>
                                                <x-input-label for="room-{{ $room->id }}-area" value="Oppervlak (m²)" />
                                                <x-text-input id="room-{{ $room->id }}-area" name="area_m2" type="number" step="0.1" min="1" class="mt-1 block w-full" value="{{ $roomMeasures->declaredAreaM2() ?? '' }}" />
                                            </div>
                                            <div>
                                                <x-input-label for="room-{{ $room->id }}-height" :value="$heightNeeded ? 'Hoogte (m, nodig)' : 'Hoogte (m)'" />
                                                <x-text-input id="room-{{ $room->id }}-height" name="height_m" type="number" step="0.1" min="1.5" class="mt-1 block w-full" value="{{ $roomMeasures->heightM() ?? '' }}" />
                                                @unless ($heightNeeded)
                                                    <p class="mt-1 text-xs text-gray-400">Alleen als die een besluit verandert.</p>
                                                @endunless
                                            </div>
                                        </div>
                                        @if ($roomMeasures->hasTrustedAreaM2() || $roomMeasures->areaSource())
                                            <div class="sm:col-span-2 text-xs text-gray-500">
                                                @if ($roomMeasures->areaSource())
                                                    Bron oppervlak: {{ $roomMeasures->areaSource() }}
                                                @endif
                                                @if ($roomMeasures->areaConfidence())
                                                    · zekerheid: {{ $roomMeasures->areaConfidence() }}
                                                @endif
                                                @if ($roomMeasures->areaEvidence())
                                                    · {{ $roomMeasures->areaEvidence() }}
                                                @endif
                                            </div>
                                        @endif
                                        <div class="sm:col-span-2">
                                            <x-primary-button>Wijzigingen opslaan</x-primary-button>
                                        </div>
                                    </form>

                                    @php
                                        $roomIndoor = $room->placements
                                            ->firstWhere('type', \App\Enums\AircoPlacementType::IndoorUnit);
                                        $activeOption = $intake->aircoInstallationOptions
                                            ->firstWhere('status', \App\Enums\AircoOptionStatus::Selected)
                                            ?? $intake->aircoInstallationOptions->first();
                                        $linkedOutdoor = null;
                                        $refrigerantLink = null;
                                        if ($roomIndoor && $activeOption) {
                                            $refrigerantLink = $activeOption->connections
                                                ->filter(static fn ($c) => $c->type === \App\Enums\AircoConnectionType::Refrigerant)
                                                ->first(static function ($c) use ($roomIndoor) {
                                                    return in_array($roomIndoor->id, [$c->from_placement_id, $c->to_placement_id], true);
                                                });
                                            if ($refrigerantLink) {
                                                $otherId = $refrigerantLink->from_placement_id === $roomIndoor->id
                                                    ? $refrigerantLink->to_placement_id
                                                    : $refrigerantLink->from_placement_id;
                                                $linkedOutdoor = $intake->aircoPlacements->firstWhere('id', $otherId)
                                                    ?? $activeOption->placements->firstWhere('id', $otherId);
                                            }
                                        }
                                        $outdoorChoices = $intake->aircoPlacements
                                            ->filter(static fn ($p) => $p->type === \App\Enums\AircoPlacementType::OutdoorUnit);
                                    @endphp

                                    <div class="mt-4 rounded-xl border border-gray-200 bg-white p-3">
                                        <p class="text-sm font-semibold text-gray-900">Binnen- en buitenunit</p>
                                        <p class="mt-1 text-xs text-gray-500">Koppel de binnenunit van deze ruimte aan een gedeelde buitenunit.</p>

                                        @if ($roomIndoor || $linkedOutdoor || $activeOption)
                                            <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                                                <div>
                                                    <dt class="text-xs text-gray-500">Binnenunit</dt>
                                                    <dd class="font-medium text-gray-900">{{ $roomIndoor?->label ?? 'Nog open' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-gray-500">Buitenunit</dt>
                                                    <dd class="font-medium text-gray-900">{{ $linkedOutdoor?->label ?? 'Nog niet gekoppeld' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-gray-500">Configuratie</dt>
                                                    <dd class="font-medium text-gray-900">{{ $activeOption?->configuration_type->label() ?? 'Nog geen keuze' }}</dd>
                                                </div>
                                            </dl>
                                        @endif

                                        <form method="POST" action="{{ route('intakes.workspace.rooms.unit-coupling', [$intake, $room]) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                                            @csrf
                                            @if ($activeOption)
                                                <input type="hidden" name="installation_option_id" value="{{ $activeOption->id }}">
                                            @endif
                                            <div>
                                                <x-input-label for="room-{{ $room->id }}-indoor-label" value="Naam binnenunit" />
                                                <x-text-input
                                                    id="room-{{ $room->id }}-indoor-label"
                                                    name="indoor_label"
                                                    class="mt-1 block w-full"
                                                    value="{{ old('indoor_label', $roomIndoor?->label ?? ('Binnenunit '.$room->name)) }}"
                                                    required
                                                />
                                            </div>
                                            <div>
                                                <x-input-label for="room-{{ $room->id }}-config" value="Configuratie" />
                                                <select id="room-{{ $room->id }}-config" name="configuration_type" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300" required>
                                                    @foreach ($configurationTypes as $type)
                                                        <option value="{{ $type->value }}" @selected(old('configuration_type', $activeOption?->configuration_type?->value) === $type->value)>
                                                            {{ $type->label() }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <x-input-label for="room-{{ $room->id }}-outdoor" value="Buitenunit" />
                                                <select id="room-{{ $room->id }}-outdoor" name="outdoor_placement_id" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300">
                                                    <option value="">Nieuwe buitenunit…</option>
                                                    @foreach ($outdoorChoices as $outdoor)
                                                        <option value="{{ $outdoor->id }}" @selected((int) old('outdoor_placement_id', $linkedOutdoor?->id) === $outdoor->id)>
                                                            {{ $outdoor->label }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <x-input-label for="room-{{ $room->id }}-outdoor-label" value="Nieuwe buitenunit (naam)" />
                                                <x-text-input
                                                    id="room-{{ $room->id }}-outdoor-label"
                                                    name="outdoor_label"
                                                    class="mt-1 block w-full"
                                                    value="{{ old('outdoor_label') }}"
                                                    placeholder="Bijv. plat dak aanbouw"
                                                />
                                            </div>
                                            <div class="sm:col-span-2">
                                                <x-primary-button>Koppeling opslaan</x-primary-button>
                                            </div>
                                        </form>
                                    </div>

                                    @if ($room->placements->filter(static fn ($p) => $p->type !== \App\Enums\AircoPlacementType::IndoorUnit)->isNotEmpty())
                                        <ul class="mt-4 grid gap-2 sm:grid-cols-2">
                                            @foreach ($room->placements->filter(static fn ($p) => $p->type !== \App\Enums\AircoPlacementType::IndoorUnit) as $placement)
                                                <li class="rounded-xl bg-gray-50 px-3 py-3 text-sm">
                                                    <span class="font-semibold text-gray-900">{{ $placement->type->label() }}</span>
                                                    <span class="block text-gray-600">{{ $placement->label }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    @include('installer.intakes._subject-tools', [
                                        'intake' => $intake,
                                        'subject' => $roomSubject,
                                        'connection' => null,
                                    ])
                                </article>
                            @empty
                                <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-5 py-8 text-center">
                                    <p class="font-semibold text-gray-900">Nog geen gewenste ruimte vastgelegd</p>
                                    <p class="mt-1 text-sm text-gray-500">Voeg de slaapkamers, woonkamer of andere ruimtes toe waarvoor de aanvraag geldt.</p>
                                </div>
                            @endforelse
                        </div>

                        <details class="mt-5 rounded-2xl border border-gray-200 bg-gray-50 p-4">
                            <summary class="cursor-pointer text-sm font-semibold text-gray-900">Ruimte toevoegen</summary>
                            <form method="POST" action="{{ route('intakes.workspace.rooms.store', $intake) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                                @csrf
                                <div>
                                    <x-input-label for="room_name" value="Naam" />
                                    <x-text-input id="room_name" name="name" class="mt-1 block w-full" placeholder="Slaapkamer ouders" required />
                                </div>
                                <div>
                                    <x-input-label for="room_use_type" value="Gebruik" />
                                    <select id="room_use_type" name="use_type" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300">
                                        <option value="">Nog niet vastgesteld</option>
                                        <option value="bedroom">Slaapkamer</option>
                                        <option value="living_room">Woonkamer</option>
                                        <option value="office">Werkkamer</option>
                                        <option value="attic">Zolder</option>
                                        <option value="other">Anders</option>
                                    </select>
                                </div>
                                <div class="sm:col-span-2">
                                    <p class="text-xs text-gray-500">Vloeroppervlak: lengte en breedte, of m². Hoogte alleen als die ertoe doet.</p>
                                </div>
                                <div class="grid grid-cols-2 gap-2 sm:col-span-2 sm:grid-cols-4">
                                    <div>
                                        <x-input-label for="room_length" value="Lengte (m)" />
                                        <x-text-input id="room_length" name="length_m" type="number" step="0.1" min="0.5" class="mt-1 block w-full" />
                                    </div>
                                    <div>
                                        <x-input-label for="room_width" value="Breedte (m)" />
                                        <x-text-input id="room_width" name="width_m" type="number" step="0.1" min="0.5" class="mt-1 block w-full" />
                                    </div>
                                    <div>
                                        <x-input-label for="room_area" value="Oppervlak (m²)" />
                                        <x-text-input id="room_area" name="area_m2" type="number" step="0.1" min="1" class="mt-1 block w-full" />
                                    </div>
                                    <div>
                                        <x-input-label for="room_height" value="Hoogte (m)" />
                                        <x-text-input id="room_height" name="height_m" type="number" step="0.1" min="1.5" class="mt-1 block w-full" />
                                    </div>
                                </div>
                                <div class="sm:col-span-2">
                                    <x-primary-button>Ruimte opslaan</x-primary-button>
                                </div>
                            </form>
                        </details>
                    </section>

                    <section id="demo-placements" class="scroll-mt-24 rounded-3xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-950">Binnen- en buitenunit</h3>
                            <p class="mt-1 text-sm text-gray-500">Eerst de units, daarna multi-split of singles.</p>
                        </div>

                        @if ($intake->aircoPlacements->isNotEmpty())
                            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                                @foreach ($intake->aircoPlacements as $placement)
                                    @php
                                        $placementSubject = $intake->dossierSubjects->firstWhere('id', $placement->dossier_subject_id);
                                    @endphp
                                    <article id="placement-{{ $placement->id }}" class="scroll-mt-28 rounded-2xl border border-gray-200 p-4">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $placement->type->label() }}</p>
                                        <h4 class="mt-1 font-semibold text-gray-950">{{ $placement->label }}</h4>
                                        @if ($placement->room)
                                            <p class="mt-1 text-xs text-gray-500">{{ $placement->room->name }}</p>
                                        @endif
                                        @if ($placement->description)
                                            <p class="mt-2 text-sm leading-relaxed text-gray-600">{{ $placement->description }}</p>
                                        @endif
                                        <p class="mt-2 text-xs text-gray-500">
                                            {{ match ($placement->source_type) {
                                                'installer' => 'Door installateur toegevoegd',
                                                'ai' => 'Door AI voorgesteld',
                                                'customer' => 'Door klant opgegeven',
                                                default => 'Automatisch toegevoegd',
                                            } }}
                                        </p>

                                        <details class="mt-4 rounded-xl border border-gray-200 bg-gray-50">
                                            <summary class="flex min-h-11 cursor-pointer list-none items-center px-3 py-2 text-sm font-semibold text-gray-800">
                                                Bewerken
                                            </summary>
                                            <form method="POST" action="{{ route('intakes.workspace.placements.update', [$intake, $placement]) }}" class="grid gap-3 border-t border-gray-200 p-3">
                                                @csrf
                                                <fieldset class="sm:col-span-2">
                                                    <legend class="sr-only">Soort unit of aansluiting</legend>
                                                    <div class="grid grid-cols-2 gap-2">
                                                        @foreach ($placementTypes as $type)
                                                            <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-900 has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50">
                                                                <input
                                                                    type="radio"
                                                                    id="placement-{{ $placement->id }}-type-{{ $type->value }}"
                                                                    name="type"
                                                                    value="{{ $type->value }}"
                                                                    class="border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                                    @checked($placement->type === $type)
                                                                    required
                                                                >
                                                                <span>{{ $type->label() }}</span>
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                </fieldset>
                                                <div>
                                                    <x-input-label for="placement-{{ $placement->id }}-room" value="Ruimte (verplicht bij binnenunit)" />
                                                    <select id="placement-{{ $placement->id }}-room" name="airco_room_id" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300">
                                                        <option value="">Algemeen / buitenzijde</option>
                                                        @foreach ($intake->aircoRooms as $room)
                                                            <option value="{{ $room->id }}" @selected($placement->airco_room_id === $room->id)>{{ $room->name }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div>
                                                    <x-input-label for="placement-{{ $placement->id }}-label" value="Naam" />
                                                    <x-text-input id="placement-{{ $placement->id }}-label" name="label" class="mt-1 block w-full" value="{{ $placement->label }}" required />
                                                </div>
                                                <div>
                                                    <x-input-label for="placement-{{ $placement->id }}-description" value="Notitie" />
                                                    <textarea id="placement-{{ $placement->id }}-description" name="description" rows="3" class="mt-1 block w-full rounded-xl border-gray-300">{{ $placement->description }}</textarea>
                                                </div>
                                                <div>
                                                    <x-primary-button>Wijzigingen opslaan</x-primary-button>
                                                </div>
                                            </form>
                                        </details>

                                        @include('installer.intakes._subject-tools', [
                                            'intake' => $intake,
                                            'subject' => $placementSubject,
                                            'connection' => null,
                                        ])
                                    </article>
                                @endforeach
                            </div>
                        @endif

                        <details class="mt-5 rounded-2xl border border-gray-200 bg-gray-50 p-4">
                            <summary class="cursor-pointer text-sm font-semibold text-gray-900">Binnen- of buitenunit toevoegen</summary>
                            <form method="POST" action="{{ route('intakes.workspace.placements.store', $intake) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                                @csrf
                                <fieldset class="sm:col-span-2">
                                    <legend class="sr-only">Soort unit of aansluiting</legend>
                                    <div class="grid grid-cols-2 gap-2">
                                        @foreach ($placementTypes as $type)
                                            <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-900 has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50">
                                                <input
                                                    type="radio"
                                                    id="placement_type_{{ $type->value }}"
                                                    name="type"
                                                    value="{{ $type->value }}"
                                                    class="border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                    @checked($loop->first)
                                                    required
                                                >
                                                <span>{{ $type->label() }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                                <div>
                                    <x-input-label for="placement_room" value="Ruimte (verplicht bij binnenunit)" />
                                    <select id="placement_room" name="airco_room_id" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300">
                                        <option value="">Algemeen / buitenzijde</option>
                                        @foreach ($intake->aircoRooms as $room)
                                            <option value="{{ $room->id }}">{{ $room->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="sm:col-span-2">
                                    <x-input-label for="placement_label" value="Naam" />
                                    <x-text-input id="placement_label" name="label" class="mt-1 block w-full" placeholder="Binnenunit boven de deur" required />
                                </div>
                                <div class="sm:col-span-2">
                                    <x-input-label for="placement_description" value="Notitie" />
                                    <textarea id="placement_description" name="description" rows="3" class="mt-1 block w-full rounded-xl border-gray-300" placeholder="Vrije wand, bereikbaarheid, obstakels…"></textarea>
                                </div>
                                <div class="sm:col-span-2">
                                    <x-primary-button>Opslaan</x-primary-button>
                                </div>
                            </form>
                        </details>
                    </section>

                    <section id="demo-proposal" class="scroll-mt-24 rounded-3xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-950">Multi-split of singles</h3>
                        </div>

                        <div class="mt-5 space-y-5">
                            @forelse ($intake->aircoInstallationOptions as $option)
                                <article @class([
                                    'rounded-2xl border p-4 sm:p-5',
                                    'border-emerald-300 bg-emerald-50/40' => $option->status === \App\Enums\AircoOptionStatus::Selected,
                                    'border-gray-200' => $option->status !== \App\Enums\AircoOptionStatus::Selected,
                                ])>
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $option->configuration_type->label() }}</p>
                                            <h4 class="mt-1 text-base font-semibold text-gray-950">{{ $option->label }}</h4>
                                            <p class="mt-1 text-xs text-gray-500">
                                                {{ $option->source_type === 'ai' ? 'AI-voorstel' : 'Door installateur toegevoegd' }}
                                                @if ($option->confidence !== null)
                                                    · {{ \App\Domains\Intake\Services\DecisionReadinessService::confidencePhrase($option->confidence) }}
                                                @endif
                                            </p>
                                            @if ($option->summary)
                                                <p class="mt-2 text-sm leading-relaxed text-gray-600">{{ $option->summary }}</p>
                                            @endif
                                            <form method="POST" action="{{ route('intakes.workspace.options.configuration', [$intake, $option]) }}" class="mt-3 flex flex-wrap items-end gap-2">
                                                @csrf
                                                <div>
                                                    <x-input-label for="option-{{ $option->id }}-config" value="Configuratie" />
                                                    <select id="option-{{ $option->id }}-config" name="configuration_type" class="mt-1 block min-h-11 rounded-xl border-gray-300" required>
                                                        @foreach ($configurationTypes as $type)
                                                            <option value="{{ $type->value }}" @selected($option->configuration_type === $type)>{{ $type->label() }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <button class="min-h-11 rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Opslaan</button>
                                            </form>
                                        </div>
                                        @if ($option->status === \App\Enums\AircoOptionStatus::Selected)
                                            <span class="rounded-full bg-emerald-600 px-3 py-1 text-xs font-semibold text-white">Geselecteerd</span>
                                        @else
                                            <form method="POST" action="{{ route('intakes.workspace.options.select', [$intake, $option]) }}">
                                                @csrf
                                                <button class="min-h-11 rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Deze keuze</button>
                                            </form>
                                        @endif
                                    </div>

                                    <ul class="mt-4 flex flex-wrap gap-2">
                                        @foreach ($option->placements as $placement)
                                            <li class="rounded-full bg-white px-3 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-200">
                                                {{ $placement->type->label() }} · {{ $placement->label }}
                                                @if ($placement->room)
                                                    · {{ $placement->room->name }}
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                    @php
                                        $optionRefrigerantLinks = $option->connections
                                            ->filter(static fn ($c) => $c->type === \App\Enums\AircoConnectionType::Refrigerant);
                                    @endphp
                                    @if ($optionRefrigerantLinks->isNotEmpty())
                                        <ul class="mt-3 space-y-1 text-sm text-gray-700">
                                            @foreach ($optionRefrigerantLinks as $link)
                                                <li>
                                                    {{ $link->fromPlacement?->label ?? 'Binnenunit' }}
                                                    →
                                                    {{ $link->toPlacement?->label ?? 'Buitenunit' }}
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    <div class="mt-5 space-y-3">
                                        @foreach ($option->connections as $connection)
                                            @php
                                                $connectionSubject = $intake->dossierSubjects->firstWhere('id', $connection->dossier_subject_id);
                                                $connectionCustomerAsk = $customerTaskBuilder->forConnection($connection);
                                            @endphp
                                            <div id="connection-{{ $connection->id }}" class="scroll-mt-24 rounded-2xl border border-gray-200 bg-white p-4">
                                                <div class="flex flex-wrap items-start justify-between gap-3">
                                                    <div>
                                                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $connection->type->label() }}</p>
                                                        <h5 class="mt-1 font-semibold text-gray-950">{{ $connection->label }}</h5>
                                                        <p class="mt-1 text-xs text-gray-500">
                                                            {{ $connection->fromPlacement?->label ?? 'Beginpunt open' }}
                                                            →
                                                            {{ $connection->toPlacement?->label ?? 'Eindpunt open' }}
                                                            @if ($connection->length_class)
                                                                · {{ $connection->length_class }}
                                                            @endif
                                                        </p>
                                                    </div>
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700">{{ $connection->status->label() }}</span>
                                                        @if ($connectionCustomerAsk !== null)
                                                            <a
                                                                href="{{ route('intakes.workspace.tasks.prepare', array_filter([
                                                                    'intake' => $intake,
                                                                    'type' => $connectionCustomerAsk['type'],
                                                                    'prompt' => $connectionCustomerAsk['prompt'],
                                                                    'decision_area_key' => $connectionCustomerAsk['decision_area_key'],
                                                                    'dossier_subject_id' => $connectionCustomerAsk['dossier_subject_id'],
                                                                ], static fn (mixed $value): bool => $value !== null && $value !== '')) }}"
                                                                class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-900 hover:bg-gray-50"
                                                            >
                                                                Vraag de klant
                                                            </a>
                                                        @endif
                                                    </div>
                                                </div>

                                                @if (is_array($connection->segments) && $connection->segments !== [])
                                                    <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-gray-600">
                                                        @foreach ($connection->segments as $segment)
                                                            <li>{{ $segment }}</li>
                                                        @endforeach
                                                    </ol>
                                                @endif

                                                @if (is_array($connection->uncertainties) && $connection->uncertainties !== [])
                                                    <div class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                                        {{ implode(' · ', $connection->uncertainties) }}
                                                    </div>
                                                @endif

                                                @if ($connection->routeSession)
                                                    <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-3">
                                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                                            <p class="text-xs font-semibold text-gray-700">{{ $connection->routeSession->segments->count() }} routesegment(en)</p>
                                                            <p class="text-xs text-gray-500">{{ $connection->routeSession->status->label() }}</p>
                                                        </div>
                                                        @if ($connection->routeSession->next_photo_instruction)
                                                            <p class="mt-2 text-xs font-medium text-gray-800">{{ $connection->routeSession->next_photo_instruction }}</p>
                                                        @endif
                                                        <div class="mt-3 flex flex-wrap gap-2">
                                                            <form method="POST" action="{{ route('intakes.workspace.routes.synthesize', [$intake, $connection->routeSession]) }}">
                                                                @csrf
                                                                <button class="min-h-10 rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700">Route samenvatten</button>
                                                            </form>
                                                            @if ($connection->routeSession->status === \App\Enums\PipeRouteStatus::Proposed)
                                                                <form method="POST" action="{{ route('intakes.workspace.routes.approve', [$intake, $connection->routeSession]) }}">
                                                                    @csrf
                                                                    <button class="min-h-10 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white">Route goedkeuren</button>
                                                                </form>
                                                            @endif
                                                        </div>
                                                    </div>
                                                @endif

                                                @include('installer.intakes._subject-tools', [
                                                    'intake' => $intake,
                                                    'subject' => $connectionSubject,
                                                    'connection' => $connection,
                                                ])
                                            </div>
                                        @endforeach
                                    </div>

                                    <details class="mt-4 rounded-xl border border-gray-200 bg-white p-4">
                                        <summary class="cursor-pointer text-sm font-semibold text-gray-900">Koel-, condens- of stroomroute toevoegen</summary>
                                        <form method="POST" action="{{ route('intakes.workspace.connections.store', [$intake, $option]) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                                            @csrf
                                            <div>
                                                <x-input-label value="Verbinding" />
                                                <select name="type" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300" required>
                                                    @foreach ($connectionTypes as $type)
                                                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <x-input-label value="Hoe zeker ben je?" />
                                                <select name="status" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300" required>
                                                    @foreach ($connectionStatuses as $status)
                                                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="sm:col-span-2">
                                                <x-input-label value="Naam" />
                                                <x-text-input name="label" class="mt-1 block w-full" placeholder="Koelleiding slaapkamer ouders" required />
                                            </div>
                                            <div>
                                                <x-input-label value="Van" />
                                                <select name="from_placement_id" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300">
                                                    <option value="">Nog open</option>
                                                    @foreach ($option->placements as $placement)
                                                        <option value="{{ $placement->id }}">{{ $placement->label }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <x-input-label value="Naar" />
                                                <select name="to_placement_id" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300">
                                                    <option value="">Nog open</option>
                                                    @foreach ($option->placements as $placement)
                                                        <option value="{{ $placement->id }}">{{ $placement->label }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <x-input-label value="Lengteklasse" />
                                                <select name="length_class" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300">
                                                    <option value="unknown">Onbekend</option>
                                                    <option value="short">Kort</option>
                                                    <option value="medium">Middel</option>
                                                    <option value="long">Lang</option>
                                                </select>
                                            </div>
                                            <div>
                                                <x-input-label value="Kostenimpact" />
                                                <select name="cost_impact" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300">
                                                    <option value="unknown">Onbekend</option>
                                                    <option value="low">Laag</option>
                                                    <option value="medium">Middel</option>
                                                    <option value="high">Hoog</option>
                                                </select>
                                            </div>
                                            <div class="sm:col-span-2">
                                                <x-input-label value="Route, één segment per regel" />
                                                <textarea name="segments_text" rows="3" class="mt-1 block w-full rounded-xl border-gray-300" placeholder="Doorvoer achter binnenunit&#10;Langs achtergevel omlaag"></textarea>
                                            </div>
                                            <div>
                                                <x-input-label value="Obstakels, één per regel" />
                                                <textarea name="obstacles_text" rows="3" class="mt-1 block w-full rounded-xl border-gray-300"></textarea>
                                            </div>
                                            <div>
                                                <x-input-label value="Onzekerheden, één per regel" />
                                                <textarea name="uncertainties_text" rows="3" class="mt-1 block w-full rounded-xl border-gray-300"></textarea>
                                            </div>
                                            <div class="sm:col-span-2">
                                                <x-primary-button>Verbinding opslaan</x-primary-button>
                                            </div>
                                        </form>
                                    </details>
                                </article>
                            @empty
                                <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-5 py-8 text-center">
                                    <p class="font-semibold text-gray-900">Nog geen keuze</p>
                                    <p class="mt-1 text-sm text-gray-500">Nog geen keuze. Eerst binnen- en buitenunit.</p>
                                </div>
                            @endforelse
                        </div>

                        <details class="mt-5 rounded-2xl border border-gray-200 bg-gray-50 p-4">
                            <summary class="cursor-pointer text-sm font-semibold text-gray-900">Kies multi-split of singles</summary>
                            <form method="POST" action="{{ route('intakes.workspace.options.store', $intake) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                                @csrf
                                <div>
                                    <x-input-label value="Naam" />
                                    <x-text-input name="label" class="mt-1 block w-full" placeholder="Keuze A · één multi-split" required />
                                </div>
                                <div>
                                    <x-input-label value="Configuratie" />
                                    <select name="configuration_type" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300" required>
                                        @foreach ($configurationTypes as $type)
                                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="sm:col-span-2">
                                    <x-input-label value="Units in deze keuze" />
                                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                        @foreach ($intake->aircoPlacements as $placement)
                                            <label class="flex min-h-11 items-center gap-3 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm">
                                                <input type="checkbox" name="placement_ids[]" value="{{ $placement->id }}" class="rounded border-gray-300 text-indigo-600">
                                                <span><strong>{{ $placement->type->label() }}</strong> · {{ $placement->label }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="sm:col-span-2">
                                    <x-input-label value="Waarom deze keuze?" />
                                    <textarea name="summary" rows="3" class="mt-1 block w-full rounded-xl border-gray-300"></textarea>
                                </div>
                                <div class="sm:col-span-2">
                                    <x-primary-button>Keuze opslaan</x-primary-button>
                                </div>
                            </form>
                        </details>
                    </section>

                    <section id="demo-customer-task" class="scroll-mt-24 rounded-3xl border border-gray-200 bg-white p-5 shadow-sm">
                        <h3 class="font-semibold text-gray-950">Taak voor de klant</h3>
                        <p class="mt-1 text-sm text-gray-500">
                            @if ($hasCustomerTaskDraft)
                                Controleer de vooringevulde opdracht en verstuur hem daarna.
                            @else
                                Alleen wat de klant moet doen. Gebruik dit blok voor een algemene of extra opdracht.
                            @endif
                            @if ($intake->is_demo)
                                Geen e-mail in de demo.
                            @endif
                        </p>
                        @if ($proposedCustomerTasks->isNotEmpty())
                            <div class="mt-4 space-y-3">
                                @foreach ($proposedCustomerTasks as $task)
                                    <article class="rounded-2xl border border-indigo-200 bg-indigo-50 p-3">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">AI-voorstel · {{ $task->type->label() }}</p>
                                        <p class="mt-1 text-sm font-medium text-gray-950">{{ $task->prompt }}</p>
                                        @if (! empty($task->meta['reason']))
                                            <p class="mt-1 text-xs text-gray-600">{{ $task->meta['reason'] }}</p>
                                        @endif
                                        <form method="POST" action="{{ route('intakes.workspace.tasks.send', [$intake, $task]) }}" class="mt-3">
                                            @csrf
                                            <button class="min-h-10 rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white">
                                                {{ $intake->is_demo ? 'Controleren en klantweergave activeren' : 'Controleren en versturen' }}
                                            </button>
                                        </form>
                                    </article>
                                @endforeach
                            </div>
                        @endif
                        <details class="mt-4 rounded-2xl border border-gray-200 bg-gray-50 p-4" @if ($proposedCustomerTasks->isEmpty() || $hasCustomerTaskDraft) open @endif>
                            <summary class="cursor-pointer text-sm font-semibold text-gray-900">
                                {{ $hasCustomerTaskDraft ? 'Vooringevulde klanttaak controleren' : 'Klanttaak maken' }}
                            </summary>
                            <form method="POST" action="{{ route('intakes.workspace.tasks.store', $intake) }}" class="mt-4 space-y-4">
                                @csrf
                                @for ($index = 0; $index < 3; $index++)
                                    @php
                                        $draftType = $index === 0 ? ($customerTaskDraft['type'] ?? null) : null;
                                        $draftPrompt = $index === 0 ? ($customerTaskDraft['prompt'] ?? '') : '';
                                        $draftArea = $index === 0 ? ($customerTaskDraft['decision_area_key'] ?? '') : '';
                                        $draftSubjectId = $index === 0 ? ($customerTaskDraft['dossier_subject_id'] ?? null) : null;
                                    @endphp
                                    <fieldset class="rounded-2xl border border-gray-200 bg-white p-3">
                                        <legend class="px-1 text-xs font-semibold text-gray-500">Opdracht {{ $index + 1 }}{{ $index > 0 ? ' (optioneel)' : '' }}</legend>
                                        <select name="contribution_items[{{ $index }}][type]" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300 text-sm">
                                            @foreach ($followUpTypes as $type)
                                                <option value="{{ $type->value }}" @selected($draftType === $type->value)>{{ $type->label() }}</option>
                                            @endforeach
                                        </select>
                                        <textarea name="contribution_items[{{ $index }}][prompt]" rows="3" class="mt-2 block w-full rounded-xl border-gray-300 text-sm" placeholder="{{ $index === 0 ? 'Bijv. Maak een leesbare foto van de volledige meterkast.' : 'Nog een concrete opdracht' }}">{{ $draftPrompt }}</textarea>
                                        <select name="contribution_items[{{ $index }}][decision_area_key]" class="mt-2 block min-h-11 w-full rounded-xl border-gray-300 text-sm">
                                            <option value="">Algemene opname</option>
                                            @foreach ($dossier['areas']->where('key', '!=', 'quote') as $area)
                                                <option value="{{ $area->key }}" @selected($draftArea === $area->key)>{{ $area->label }}</option>
                                            @endforeach
                                        </select>
                                        @if ($draftSubjectId)
                                            <input type="hidden" name="contribution_items[{{ $index }}][dossier_subject_id]" value="{{ $draftSubjectId }}">
                                        @endif
                                    </fieldset>
                                @endfor
                                <x-primary-button class="w-full justify-center">
                                    {{ $intake->is_demo ? 'Klantweergave activeren' : 'Klanttaak maken en mailen' }}
                                </x-primary-button>
                            </form>
                        </details>
                    </section>

                    <section id="workspace-complete" class="scroll-mt-24 rounded-3xl border border-gray-200 bg-white p-5 shadow-sm">
                        <h3 class="font-semibold text-gray-950">Voorstel afronden</h3>
                        @if ($proposalAlreadyApproved)
                            <div class="mt-3 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
                                Goedgekeurd. Leg hieronder de uitkomst vast als je wilt.
                            </div>
                            <a href="#workspace-outcome" class="mt-3 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-emerald-300 bg-white px-4 text-sm font-semibold text-emerald-900 hover:bg-emerald-50">
                                Uitkomst vastleggen
                            </a>
                        @elseif ($canApproveProposal)
                            <p class="mt-1 text-sm text-gray-500">Keurt je keuze en de routes in één keer goed.</p>
                            <form method="POST" action="{{ route('intakes.workspace.complete', $intake) }}" class="mt-4">
                                @csrf
                                <button class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-gray-950 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                                    Voorstel goedkeuren
                                </button>
                            </form>
                        @elseif ($selectedOption)
                            <p class="mt-1 text-sm text-gray-500">Los eerst de open punten op. Daarna kun je goedkeuren.</p>
                            <button type="button" disabled class="mt-4 inline-flex min-h-11 w-full cursor-not-allowed items-center justify-center rounded-xl bg-gray-200 px-4 py-2 text-sm font-semibold text-gray-500">
                                Nog niet klaar om goed te keuren
                            </button>
                        @else
                            <p class="mt-1 text-sm text-gray-500">Kies eerst multi-split of singles met koel-, condens- en stroomroute.</p>
                            <a href="#demo-proposal" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                                Naar multi-split of singles
                            </a>
                        @endif
                    </section>

                    <section id="demo-ai" class="scroll-mt-24 rounded-3xl border border-indigo-100 bg-indigo-50/50 shadow-sm">
                        <details class="group" @if ($aiSectionOpen) open @endif>
                            <summary class="cursor-pointer list-none px-5 py-4 sm:px-6 [&::-webkit-details-marker]:hidden">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-indigo-600">AI-opnameassistent</p>
                                        <h3 class="mt-1 text-lg font-semibold text-gray-950">
                                            @if ($aiSectionOpen)
                                                {{ count($aiExceptions) }} uitzondering(en) bekijken
                                            @elseif ($aiSynthesis)
                                                AI-voorstel bekijken
                                            @elseif ($intake->aircoInstallationOptions->isEmpty())
                                                AI-voorstel wacht op een keuze
                                            @else
                                                Nog geen AI-voorstel
                                            @endif
                                        </h3>
                                    </div>
                                </div>
                            </summary>
                            <div class="space-y-4 border-t border-indigo-100 px-5 py-4 sm:px-6">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <p class="text-sm leading-relaxed text-gray-600">
                                        Het voorstel gebruikt alleen gegevens uit deze opname.
                                    </p>
                                    <form method="POST" action="{{ route('intakes.workspace.synthesis', $intake) }}">
                                        @csrf
                                        <button class="inline-flex min-h-11 shrink-0 items-center rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                                            AI-voorstel vernieuwen
                                        </button>
                                    </form>
                                </div>
                                @if ($aiSynthesis)
                                    <div class="rounded-2xl border border-indigo-100 bg-white p-4">
                                        <p class="text-sm font-medium leading-relaxed text-gray-900">{{ $aiSynthesis->value['summary'] ?? 'Synthese beschikbaar.' }}</p>
                                        @if ($aiExceptions !== [])
                                            <ul class="mt-3 space-y-2">
                                                @foreach ($aiExceptions as $exception)
                                                    @php
                                                        $exceptionLabel = is_string($exception['label'] ?? null) ? $exception['label'] : 'Onbekende uitzondering';
                                                        $exceptionAreaKey = is_string($exception['decision_area_key'] ?? null) ? $exception['decision_area_key'] : null;
                                                        $exceptionAreaLabel = $exceptionAreaKey
                                                            ? \App\Domains\Intake\Services\DecisionReadinessService::areaLabel($exceptionAreaKey)
                                                            : null;
                                                        $exceptionConfidence = \App\Domains\Intake\Services\DecisionReadinessService::confidencePhrase($exception['confidence'] ?? null);
                                                        $exceptionType = in_array($exceptionAreaKey, ['capacity', 'placement', 'refrigerant', 'condensate', 'power'], true)
                                                            ? \App\Enums\FollowUpItemType::Photo->value
                                                            : \App\Enums\FollowUpItemType::Text->value;
                                                        $exceptionPrompt = \Illuminate\Support\Str::limit($exceptionLabel, 500, '');
                                                    @endphp
                                                    <li class="rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-950">
                                                        <strong>{{ $exceptionLabel }}</strong>
                                                        <span class="block text-xs text-amber-800">
                                                            @if ($exceptionAreaLabel)
                                                                {{ $exceptionAreaLabel }}:
                                                            @endif
                                                            {{ $exceptionConfidence }}
                                                        </span>
                                                        <form method="POST" action="{{ route('intakes.workspace.tasks.quick', $intake) }}" class="mt-2">
                                                            @csrf
                                                            <input type="hidden" name="type" value="{{ $exceptionType }}">
                                                            <input type="hidden" name="prompt" value="{{ $exceptionPrompt }}">
                                                            @if ($exceptionAreaKey)
                                                                <input type="hidden" name="decision_area_key" value="{{ $exceptionAreaKey }}">
                                                            @endif
                                                            <button class="inline-flex min-h-10 items-center rounded-lg bg-amber-900 px-3 py-2 text-xs font-semibold text-white hover:bg-amber-800">
                                                                Vraag de klant
                                                            </button>
                                                        </form>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <p class="mt-2 text-xs text-gray-500">Geen beslissende uitzondering voorgesteld.</p>
                                        @endif
                                    </div>
                                @elseif ($intake->aircoInstallationOptions->isEmpty())
                                    <p class="text-sm text-indigo-900">
                                        Nog geen keuze. Eerst binnen- en buitenunit.
                                    </p>
                                @else
                                    <p class="text-sm text-indigo-900">Er is nog geen AI-voorstel opgeslagen. Tik op vernieuwen om er een te maken.</p>
                                @endif
                            </div>
                        </details>
                    </section>

                    <section id="demo-context" class="scroll-mt-24 rounded-3xl border border-gray-200 bg-white shadow-sm">
                        <details>
                            <summary class="cursor-pointer list-none px-5 py-4 sm:px-6 [&::-webkit-details-marker]:hidden">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-950">Woninggegevens</h3>
                                        <p class="mt-1 text-sm text-gray-500">{{ $factCount }} gegeven{{ $factCount === 1 ? '' : 's' }} · tik om te openen</p>
                                    </div>
                                </div>
                            </summary>
                            <div class="border-t border-gray-100 px-5 py-4 sm:px-6">
                                <dl class="grid gap-3 sm:grid-cols-2">
                                    @forelse ($externalData['facts'] as $fact)
                                        <div class="rounded-xl bg-gray-50 px-3 py-2">
                                            <dt class="text-xs font-medium text-gray-500">{{ $fact['label'] }}</dt>
                                            <dd class="mt-0.5 text-sm font-semibold text-gray-900">{{ $fact['display'] }}</dd>
                                            <dd class="text-xs text-gray-500">{{ $fact['source'] }} · {{ $fact['confidence'] }}</dd>
                                        </div>
                                    @empty
                                        <p class="text-sm text-gray-500">Nog geen relevante woninggegevens gevonden.</p>
                                    @endforelse
                                </dl>
                                @if ($externalData['aerial_image'])
                                    <details class="mt-4 overflow-hidden rounded-2xl border border-gray-200 bg-gray-50">
                                        <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-gray-800">Luchtfoto van de omgeving bekijken</summary>
                                        <figure class="border-t border-gray-200 bg-white">
                                            <img src="{{ $externalData['aerial_image']['data_uri'] }}" alt="Luchtfoto van de woningomgeving" class="aspect-[3/2] w-full object-cover">
                                            <figcaption class="px-3 py-2 text-xs text-gray-500">{{ $externalData['aerial_image']['source'] }} · {{ $externalData['aerial_image']['confidence'] }}</figcaption>
                                        </figure>
                                    </details>
                                @endif
                                @if ($externalData['uncertainties'] !== [])
                                    <details class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2">
                                        <summary class="cursor-pointer text-sm font-semibold text-amber-950">{{ count($externalData['uncertainties']) }} bronbeperking(en)</summary>
                                        <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-amber-900">
                                            @foreach ($externalData['uncertainties'] as $uncertainty)
                                                <li>{{ $uncertainty }}</li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @endif
                            </div>
                        </details>
                    </section>

                    <section id="demo-evidence" class="scroll-mt-24 rounded-3xl border border-gray-200 bg-white shadow-sm">
                        <details>
                            <summary class="cursor-pointer list-none px-5 py-4 sm:px-6 [&::-webkit-details-marker]:hidden">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-950">Foto’s</h3>
                                        <p class="mt-1 text-sm text-gray-500">{{ $photoCount }} foto{{ $photoCount === 1 ? '' : '’s' }} · tik om te openen</p>
                                    </div>
                                </div>
                            </summary>
                            <div class="border-t border-gray-100 px-5 py-4 sm:px-6">
                                @forelse ($photoGroups as $group)
                                    <div class="mt-2 first:mt-0">
                                        <h4 class="text-sm font-semibold text-gray-800">{{ $group['heading'] }}</h4>
                                        <ul class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-3">
                                            @foreach ($group['uploads'] as $item)
                                                <li>
                                                    <a
                                                        href="{{ route('installer.uploads.show', [$intake, $item['upload']]) }}"
                                                        target="_blank"
                                                        rel="noopener"
                                                        class="group block overflow-hidden rounded-2xl border border-gray-200 bg-gray-50"
                                                    >
                                                        <img
                                                            src="{{ route('installer.uploads.show', [$intake, $item['upload']]) }}"
                                                            alt="{{ $group['heading'] }} · {{ $item['caption'] }}"
                                                            class="aspect-[4/3] w-full object-cover transition group-hover:scale-[1.02]"
                                                        >
                                                        <span class="block truncate px-3 py-2 text-xs font-medium text-gray-700">{{ $item['caption'] }}</span>
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @empty
                                    <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-5 py-8 text-center text-sm text-gray-500">
                                        Nog geen foto’s aan het dossier gekoppeld.
                                    </div>
                                @endforelse
                            </div>
                        </details>
                    </section>

                </main>

                <aside class="space-y-6 lg:sticky lg:top-24 lg:self-start">
                    <section id="workspace-outcome" class="scroll-mt-24 rounded-3xl border border-gray-200 bg-white shadow-sm">
                        <details>
                            <summary class="cursor-pointer list-none px-5 py-4 [&::-webkit-details-marker]:hidden">
                                <h3 class="text-base font-semibold text-gray-950">Uitkomst na offerte of plaatsing</h3>
                                <p class="mt-1 text-sm text-gray-500">Later invullen · tik om te openen</p>
                            </summary>
                            <div class="border-t border-gray-100 px-5 py-4">
                        @php
                            $recordedVisitReasons = old('site_visit_reasons', $intake->outcome?->site_visit_reasons ?? []);
                            $recordedProposalDeltas = old('proposal_delta_codes', $intake->outcome?->proposal_delta['codes'] ?? []);
                        @endphp
                        <form method="POST" action="{{ route('intakes.workspace.outcome', $intake) }}" class="mt-4 space-y-3">
                            @csrf
                            <select name="result" class="block min-h-11 w-full rounded-xl border-gray-300" required>
                                <option value="remote_quote" @selected(old('result', $intake->outcome?->result) === 'remote_quote')>Op afstand geoffreerd</option>
                                <option value="estimate" @selected(old('result', $intake->outcome?->result) === 'estimate')>Prijsindicatie</option>
                                <option value="site_visit" @selected(old('result', $intake->outcome?->result) === 'site_visit')>Locatiebezoek</option>
                                <option value="installed" @selected(old('result', $intake->outcome?->result) === 'installed')>Geplaatst</option>
                                <option value="rejected" @selected(old('result', $intake->outcome?->result) === 'rejected')>Afgewezen</option>
                            </select>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <x-input-label value="Installateur min." />
                                    <x-text-input name="active_installer_minutes" type="number" min="0" class="mt-1 block w-full" :value="old('active_installer_minutes', $intake->outcome?->active_installer_minutes)" />
                                </div>
                                <div>
                                    <x-input-label value="Klant min." />
                                    <x-text-input name="customer_minutes" type="number" min="0" class="mt-1 block w-full" :value="old('customer_minutes', $intake->outcome?->customer_minutes)" />
                                </div>
                            </div>
                            <label class="flex min-h-11 items-center gap-3 rounded-xl border border-gray-200 px-3 text-sm">
                                <input type="checkbox" name="site_visit_occurred" value="1" class="rounded border-gray-300" @checked(old('site_visit_occurred', $intake->outcome?->site_visit_occurred))>
                                Locatiebezoek uitgevoerd
                            </label>
                            <fieldset class="rounded-xl border border-gray-200 p-3">
                                <legend class="px-1 text-xs font-semibold text-gray-600">Waarom was een locatiebezoek nodig?</legend>
                                <p class="mb-2 text-xs text-gray-500">Kies maximaal drie redenen wanneer een bezoek nodig of uitgevoerd is.</p>
                                <div class="space-y-2">
                                    @foreach ($siteVisitReasons as $reason)
                                        <label class="flex items-start gap-2 text-xs text-gray-700">
                                            <input
                                                type="checkbox"
                                                name="site_visit_reasons[]"
                                                value="{{ $reason->value }}"
                                                class="mt-0.5 rounded border-gray-300 text-indigo-600"
                                                @checked(in_array($reason->value, $recordedVisitReasons, true))
                                            >
                                            <span>{{ $reason->label() }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <fieldset class="rounded-xl border border-gray-200 p-3">
                                <label class="flex items-start gap-2 text-xs font-semibold text-gray-700">
                                    <input
                                        type="checkbox"
                                        name="proposal_assessed"
                                        value="1"
                                        class="mt-0.5 rounded border-gray-300 text-indigo-600"
                                        @checked(old('proposal_assessed', $intake->outcome?->proposal_delta !== null))
                                    >
                                    <span>Het eerste installatievoorstel is met de definitieve keuze vergeleken</span>
                                </label>
                                <p class="my-2 text-xs text-gray-500">Laat alles hieronder leeg als het voorstel ongewijzigd bleef.</p>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    @foreach ($proposalDeltas as $delta)
                                        <label class="flex items-start gap-2 text-xs text-gray-700">
                                            <input
                                                type="checkbox"
                                                name="proposal_delta_codes[]"
                                                value="{{ $delta->value }}"
                                                class="mt-0.5 rounded border-gray-300 text-indigo-600"
                                                @checked(in_array($delta->value, $recordedProposalDeltas, true))
                                            >
                                            <span>{{ $delta->label() }} aangepast</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <select name="installation_surprise" class="block min-h-11 w-full rounded-xl border-gray-300">
                                <option value="">Nog niet geplaatst</option>
                                <option value="none" @selected(old('installation_surprise', $intake->outcome?->installation_surprise) === 'none')>Geen verrassing</option>
                                <option value="minor" @selected(old('installation_surprise', $intake->outcome?->installation_surprise) === 'minor')>Kleine afwijking / meerwerk</option>
                                <option value="major" @selected(old('installation_surprise', $intake->outcome?->installation_surprise) === 'major')>Grote afwijking / meerwerk</option>
                            </select>
                            <textarea name="surprise_notes" rows="3" class="block w-full rounded-xl border-gray-300" placeholder="Wat bleek bij montage anders?">{{ old('surprise_notes', $intake->outcome?->surprise_notes) }}</textarea>
                            @if ($selectedOption)
                                <input type="hidden" name="selected_installation_option_id" value="{{ $selectedOption->id }}">
                            @endif
                            <x-primary-button class="w-full justify-center">Uitkomst opslaan</x-primary-button>
                        </form>
                            </div>
                        </details>
                    </section>
                </aside>
            </div>
            @if ($intake->is_demo)
                <div class="mt-6">
                    <x-demo-pdf-request :intake="$intake" />
                </div>
            @endif
        </div>
    </div>

    {{-- Open collapsed info sections when a demo/hash jump lands on them --}}
    <script>
        (function () {
            function openTargetDetails() {
                const id = (window.location.hash || '').replace(/^#/, '');
                if (!id) return;
                const section = document.getElementById(id);
                if (!section) return;
                const details = section.matches('details') ? section : section.querySelector(':scope > details');
                if (details) {
                    details.open = true;
                }
            }
            openTargetDetails();
            window.addEventListener('hashchange', openTargetDetails);
        })();
    </script>

    @if ($intake->is_demo && (bool) session('public_demo_mode', false))
        <x-demo-guide
            :step="session('demo_coachmark', session('public_demo_guide_step'))"
            :has-intake="true"
            :intake="$intake"
        />
    @endif
</x-app-layout>
