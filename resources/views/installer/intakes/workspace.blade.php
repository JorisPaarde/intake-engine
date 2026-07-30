<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500">{{ $intake->customer_name }} · {{ $intake->fullAddress() }}</p>
                <h2 class="mt-1 text-xl font-semibold leading-tight text-gray-900">Technische opname</h2>
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
                <section id="demo-intro" class="overflow-hidden rounded-3xl border border-sky-200 bg-sky-50 shadow-sm">
                    <div class="grid gap-5 p-5 sm:p-6 lg:grid-cols-[1fr_auto] lg:items-center">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-sky-700">Interactieve demo · echte werkplek</p>
                            <h3 class="mt-2 text-xl font-semibold text-gray-950">Beoordeel een vooraf gevulde airco-opname</h3>
                            <p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-700">
                                Deze woning, klant en foto’s zijn volledig fictief. U gebruikt wel dezelfde installateursflow, validaties en dossieropslag als in de app. De sessie verdwijnt na {{ max(1, (int) config('intake.demo.ttl_hours', 2)) }} uur; live AI, e-mail en PDF-export staan uit.
                            </p>
                            <nav class="mt-4 flex flex-wrap gap-2 text-xs font-semibold" aria-label="Demoroute">
                                <a href="#demo-context" class="rounded-full bg-white px-3 py-2 text-sky-800 shadow-sm ring-1 ring-sky-200">1. Woningcontext</a>
                                <a href="#demo-evidence" class="rounded-full bg-white px-3 py-2 text-sky-800 shadow-sm ring-1 ring-sky-200">2. Foto’s</a>
                                <a href="#demo-proposal" class="rounded-full bg-white px-3 py-2 text-sky-800 shadow-sm ring-1 ring-sky-200">3. Voorstel en routes</a>
                                <a href="#demo-customer-task" class="rounded-full bg-white px-3 py-2 text-sky-800 shadow-sm ring-1 ring-sky-200">4. Gerichte klanttaak</a>
                            </nav>
                        </div>
                        <a href="{{ url('/') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-sky-300 bg-white px-4 py-2 text-sm font-semibold text-sky-900 hover:bg-sky-100">
                            Terug naar website
                        </a>
                    </div>
                </section>
            @endif

            <section class="overflow-hidden rounded-3xl bg-gray-950 text-white shadow-sm">
                <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-[1fr_auto] lg:items-end">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-white/55">Offertebesluit</p>
                        <h3 class="mt-2 text-2xl font-semibold tracking-tight">
                            {{ $quoteArea?->next_action?->label() ?? 'Opname opbouwen' }}
                        </h3>
                        <p class="mt-2 max-w-2xl text-sm leading-relaxed text-white/70">
                            {{ $quoteArea?->blocker ?: 'De technische basis is aanwezig. Controleer het voorstel als geheel en leg uw beslissing vast.' }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="text-right">
                            <p class="text-3xl font-semibold tabular-nums">{{ $dossier['ready_count'] }}/{{ $dossier['total_count'] }}</p>
                            <p class="text-xs text-white/55">beslisgebieden gereed</p>
                        </div>
                        <span @class([
                            'inline-flex min-h-11 items-center rounded-full px-4 py-2 text-sm font-semibold',
                            'bg-emerald-400/20 text-emerald-200' => $quoteArea?->status === \App\Enums\DecisionAreaStatus::Ready,
                            'bg-amber-400/20 text-amber-100' => $quoteArea?->status === \App\Enums\DecisionAreaStatus::Review,
                            'bg-red-400/20 text-red-100' => $quoteArea?->status === \App\Enums\DecisionAreaStatus::Blocked,
                            'bg-white/10 text-white/70' => ! in_array($quoteArea?->status, [\App\Enums\DecisionAreaStatus::Ready, \App\Enums\DecisionAreaStatus::Review, \App\Enums\DecisionAreaStatus::Blocked], true),
                        ])>
                            {{ $quoteArea?->status?->label() ?? 'Onbekend' }}
                        </span>
                    </div>
                </div>
            </section>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <main class="min-w-0 space-y-6">
                    <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-950">Beslisgereedheid</h3>
                                <p class="mt-1 text-sm text-gray-500">Taakvoortgang en technische zekerheid zijn bewust gescheiden.</p>
                            </div>
                            <span class="text-sm font-medium text-gray-500">{{ $intake->workflow_mode->label() }}</span>
                        </div>

                        <div class="mt-5 grid gap-3 sm:grid-cols-2">
                            @foreach ($dossier['areas'] as $area)
                                <article @class([
                                    'rounded-2xl border p-4',
                                    'border-emerald-200 bg-emerald-50/60' => $area->status === \App\Enums\DecisionAreaStatus::Ready,
                                    'border-amber-200 bg-amber-50/70' => $area->status === \App\Enums\DecisionAreaStatus::Review,
                                    'border-red-200 bg-red-50/60' => $area->status === \App\Enums\DecisionAreaStatus::Blocked,
                                    'border-gray-200 bg-gray-50' => in_array($area->status, [\App\Enums\DecisionAreaStatus::Unknown, \App\Enums\DecisionAreaStatus::NotApplicable], true),
                                ])>
                                    <div class="flex items-start justify-between gap-3">
                                        <h4 class="text-sm font-semibold text-gray-950">{{ $area->label }}</h4>
                                        <span class="shrink-0 text-xs font-semibold text-gray-600">{{ $area->status->label() }}</span>
                                    </div>
                                    @if ($area->blocker)
                                        <p class="mt-2 text-xs leading-relaxed text-gray-600">{{ $area->blocker }}</p>
                                    @endif
                                    @if ($area->next_action)
                                        <p class="mt-2 text-xs font-semibold text-gray-900">{{ $area->next_action->label() }}</p>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    </section>

                    <section id="demo-ai" class="rounded-3xl border border-indigo-100 bg-indigo-50/50 p-5 shadow-sm sm:p-6">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-indigo-600">AI-opnameassistent</p>
                                <h3 class="mt-1 text-lg font-semibold text-gray-950">Integraal voorstel, geen losse veldbevestigingen</h3>
                                <p class="mt-1 text-sm leading-relaxed text-gray-600">
                                    De synthese gebruikt alleen brongebonden dossierinformatie. Opties, routes en klanttaken blijven voorstellen totdat u ze als geheel kiest of verstuurt.
                                </p>
                            </div>
                            @if ($intake->is_demo)
                                <span class="inline-flex min-h-11 shrink-0 items-center rounded-xl bg-indigo-100 px-4 py-2 text-sm font-semibold text-indigo-800">
                                    Vooraf berekend · € 0
                                </span>
                            @else
                                <form method="POST" action="{{ route('intakes.workspace.synthesis', $intake) }}">
                                    @csrf
                                    <button class="inline-flex min-h-11 shrink-0 items-center rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                                        AI-voorstel vernieuwen
                                    </button>
                                </form>
                            @endif
                        </div>

                        @if ($aiSynthesis)
                            <div class="mt-5 rounded-2xl border border-indigo-100 bg-white p-4">
                                <p class="text-sm font-medium leading-relaxed text-gray-900">{{ $aiSynthesis->value['summary'] ?? 'Synthese beschikbaar.' }}</p>
                                @if (! empty($aiSynthesis->value['exceptions']))
                                    <ul class="mt-3 space-y-2">
                                        @foreach ($aiSynthesis->value['exceptions'] as $exception)
                                            <li class="rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-950">
                                                <strong>{{ $exception['label'] }}</strong>
                                                <span class="block text-xs text-amber-800">{{ $exception['decision_area_key'] }} · zekerheid {{ $exception['confidence'] }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="mt-2 text-xs text-gray-500">Geen beslissende uitzondering voorgesteld.</p>
                                @endif
                            </div>
                        @else
                            <p class="mt-4 text-sm text-indigo-900">Nog geen integrale AI-synthese opgeslagen. De deterministische beslisgereedheid hierboven blijft leidend.</p>
                        @endif
                    </section>

                    <section id="demo-context" class="scroll-mt-6 rounded-3xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-950">Automatisch voor u gevonden</h3>
                            <p class="mt-1 text-sm text-gray-500">Alleen woninggegevens die helpen bij de beoordeling. De volledige brondata blijft in het dossier bewaard.</p>
                        </div>
                        <dl class="mt-5 grid gap-3 sm:grid-cols-2">
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
                    </section>

                    <section id="demo-evidence" class="scroll-mt-6 rounded-3xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-950">Beeldbewijs in het dossier</h3>
                            <p class="mt-1 text-sm text-gray-500">Elke foto blijft aan het juiste technische onderdeel gekoppeld; de installateur kan het origineel openen.</p>
                        </div>

                        @forelse ($photoGroups as $group)
                            <div class="mt-5">
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
                            <div class="mt-5 rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-5 py-8 text-center text-sm text-gray-500">
                                Nog geen foto’s aan het dossier gekoppeld.
                            </div>
                        @endforelse
                    </section>

                    <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-950">Gewenste ruimtes</h3>
                            <p class="mt-1 text-sm text-gray-500">Een ruimte is nog geen gekozen binnenunit.</p>
                        </div>

                        <div class="mt-5 space-y-4">
                            @forelse ($intake->aircoRooms as $room)
                                <article class="rounded-2xl border border-gray-200 p-4">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h4 class="font-semibold text-gray-950">{{ $room->name }}</h4>
                                            <p class="mt-1 text-xs text-gray-500">
                                                {{ match ($room->use_type) {
                                                    'bedroom' => 'Slaapkamer',
                                                    'living_room' => 'Woonkamer',
                                                    'office' => 'Werkkamer',
                                                    'attic' => 'Zolder',
                                                    'other' => 'Andere ruimte',
                                                    default => 'Gebruik nog niet vastgesteld',
                                                } }}
                                                @if (is_array($room->dimensions) && $room->dimensions !== [])
                                                    ·
                                                    {{ collect($room->dimensions)->map(fn ($value, $key) => number_format((float) $value, 1, ',', '.').' m')->implode(' × ') }}
                                                @endif
                                            </p>
                                        </div>
                                        <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600">
                                            {{ $room->source_type === 'installer' ? 'Ter plaatse' : 'Uit klanttaak' }}
                                        </span>
                                    </div>

                                    @if ($room->placements->isNotEmpty())
                                        <ul class="mt-4 grid gap-2 sm:grid-cols-2">
                                            @foreach ($room->placements as $placement)
                                                <li class="rounded-xl bg-gray-50 px-3 py-3 text-sm">
                                                    <span class="font-semibold text-gray-900">{{ $placement->type->label() }}</span>
                                                    <span class="block text-gray-600">{{ $placement->label }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
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
                                    <x-input-label for="room_name" value="Herkenbare naam" />
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
                                <div class="grid grid-cols-3 gap-2 sm:col-span-2">
                                    <div>
                                        <x-input-label for="room_length" value="Lengte (m)" />
                                        <x-text-input id="room_length" name="length_m" type="number" step="0.1" min="0.5" class="mt-1 block w-full" />
                                    </div>
                                    <div>
                                        <x-input-label for="room_width" value="Breedte (m)" />
                                        <x-text-input id="room_width" name="width_m" type="number" step="0.1" min="0.5" class="mt-1 block w-full" />
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

                    <section id="demo-placements" class="scroll-mt-6 rounded-3xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-950">Kandidaatposities</h3>
                            <p class="mt-1 text-sm text-gray-500">Binnen, buiten, voeding en afvoer blijven losse mogelijkheden tot u een opstelling kiest.</p>
                        </div>

                        @if ($intake->aircoPlacements->isNotEmpty())
                            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                                @foreach ($intake->aircoPlacements as $placement)
                                    <article class="rounded-2xl border border-gray-200 p-4">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $placement->type->label() }}</p>
                                        <h4 class="mt-1 font-semibold text-gray-950">{{ $placement->label }}</h4>
                                        @if ($placement->room)
                                            <p class="mt-1 text-xs text-gray-500">{{ $placement->room->name }}</p>
                                        @endif
                                        @if ($placement->description)
                                            <p class="mt-2 text-sm leading-relaxed text-gray-600">{{ $placement->description }}</p>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        @endif

                        <details class="mt-5 rounded-2xl border border-gray-200 bg-gray-50 p-4">
                            <summary class="cursor-pointer text-sm font-semibold text-gray-900">Kandidaatpositie toevoegen</summary>
                            <form method="POST" action="{{ route('intakes.workspace.placements.store', $intake) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                                @csrf
                                <div>
                                    <x-input-label for="placement_type" value="Soort positie" />
                                    <select id="placement_type" name="type" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300" required>
                                        @foreach ($placementTypes as $type)
                                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <x-input-label for="placement_room" value="Ruimte (indien relevant)" />
                                    <select id="placement_room" name="airco_room_id" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300">
                                        <option value="">Algemeen / buitenzijde</option>
                                        @foreach ($intake->aircoRooms as $room)
                                            <option value="{{ $room->id }}">{{ $room->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="sm:col-span-2">
                                    <x-input-label for="placement_label" value="Korte omschrijving" />
                                    <x-text-input id="placement_label" name="label" class="mt-1 block w-full" placeholder="Binnenunit boven de deur" required />
                                </div>
                                <div class="sm:col-span-2">
                                    <x-input-label for="placement_description" value="Technische waarneming (optioneel)" />
                                    <textarea id="placement_description" name="description" rows="3" class="mt-1 block w-full rounded-xl border-gray-300" placeholder="Vrije wand, bereikbaarheid, obstakels…"></textarea>
                                </div>
                                <div class="sm:col-span-2">
                                    <x-primary-button>Positie opslaan</x-primary-button>
                                </div>
                            </form>
                        </details>
                    </section>

                    <section id="demo-proposal" class="scroll-mt-6 rounded-3xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-950">Installatieopties</h3>
                            <p class="mt-1 text-sm text-gray-500">Vergelijk bijvoorbeeld één multi-split met twee losse single-splits.</p>
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
                                                    · {{ number_format($option->confidence * 100, 0) }}% zekerheid
                                                @endif
                                            </p>
                                            @if ($option->summary)
                                                <p class="mt-2 text-sm leading-relaxed text-gray-600">{{ $option->summary }}</p>
                                            @endif
                                        </div>
                                        @if ($option->status === \App\Enums\AircoOptionStatus::Selected)
                                            <span class="rounded-full bg-emerald-600 px-3 py-1 text-xs font-semibold text-white">Geselecteerd</span>
                                        @else
                                            <form method="POST" action="{{ route('intakes.workspace.options.select', [$intake, $option]) }}">
                                                @csrf
                                                <button class="min-h-11 rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Deze optie kiezen</button>
                                            </form>
                                        @endif
                                    </div>

                                    <ul class="mt-4 flex flex-wrap gap-2">
                                        @foreach ($option->placements as $placement)
                                            <li class="rounded-full bg-white px-3 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-200">
                                                {{ $placement->type->label() }} · {{ $placement->label }}
                                            </li>
                                        @endforeach
                                    </ul>

                                    <div class="mt-5 space-y-3">
                                        @foreach ($option->connections as $connection)
                                            <div class="rounded-2xl border border-gray-200 bg-white p-4">
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
                                                    <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700">{{ $connection->status->label() }}</span>
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
                                                <x-input-label value="Huidige zekerheid" />
                                                <select name="status" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300" required>
                                                    @foreach ($connectionStatuses as $status)
                                                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="sm:col-span-2">
                                                <x-input-label value="Herkenbare naam" />
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
                                    <p class="font-semibold text-gray-900">Nog geen installatieoptie</p>
                                    <p class="mt-1 text-sm text-gray-500">Leg eerst binnen- en buitenposities vast en combineer die daarna.</p>
                                </div>
                            @endforelse
                        </div>

                        <details class="mt-5 rounded-2xl border border-gray-200 bg-gray-50 p-4">
                            <summary class="cursor-pointer text-sm font-semibold text-gray-900">Installatieoptie maken</summary>
                            <form method="POST" action="{{ route('intakes.workspace.options.store', $intake) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                                @csrf
                                <div>
                                    <x-input-label value="Naam" />
                                    <x-text-input name="label" class="mt-1 block w-full" placeholder="Optie A · één multi-split" required />
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
                                    <x-input-label value="Posities in deze optie" />
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
                                    <x-input-label value="Waarom deze optie?" />
                                    <textarea name="summary" rows="3" class="mt-1 block w-full rounded-xl border-gray-300"></textarea>
                                </div>
                                <div class="sm:col-span-2">
                                    <x-primary-button>Installatieoptie opslaan</x-primary-button>
                                </div>
                            </form>
                        </details>
                    </section>
                </main>

                <aside class="space-y-6">
                    <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm">
                        <h3 class="font-semibold text-gray-950">Camera en bewijs</h3>
                        <p class="mt-1 text-sm leading-relaxed text-gray-500">Foto’s komen direct bij het juiste dossieronderdeel. Voor AI wordt automatisch een kleinere kopie gebruikt.</p>
                        <form method="POST" enctype="multipart/form-data" action="{{ route('intakes.workspace.evidence.store', $intake) }}" class="mt-4 space-y-4">
                            @csrf
                            <div>
                                <x-input-label value="Onderdeel" />
                                <select name="dossier_subject_id" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300" required>
                                    @foreach ($intake->dossierSubjects as $subject)
                                        <option value="{{ $subject->id }}">{{ $subject->label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="flex min-h-24 cursor-pointer flex-col items-center justify-center rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-4 text-center">
                                    <span class="text-sm font-semibold text-gray-900">Foto maken of kiezen</span>
                                    <span class="mt-1 text-xs text-gray-500">JPEG, PNG, WebP of HEIC · automatisch verkleind</span>
                                    <input type="file" name="photo" accept="image/*,.heic,.heif" class="sr-only" required>
                                </label>
                            </div>
                            @if ($intake->aircoInstallationOptions->flatMap->connections->isNotEmpty())
                                <div>
                                    <x-input-label value="Ook als routesegment (optioneel)" />
                                    <select name="airco_connection_id" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300">
                                        <option value="">Alleen dossierbewijs</option>
                                        @foreach ($intake->aircoInstallationOptions as $option)
                                            @foreach ($option->connections as $connection)
                                                <option value="{{ $connection->id }}">{{ $connection->type->label() }} · {{ $connection->label }}</option>
                                            @endforeach
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <x-input-label value="Rol van dit segment" />
                                    <x-text-input name="route_segment_label" class="mt-1 block w-full" placeholder="Andere kant van de wand" />
                                </div>
                            @endif
                            <x-primary-button class="w-full justify-center">Foto opslaan</x-primary-button>
                        </form>
                    </section>

                    <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm">
                        <h3 class="font-semibold text-gray-950">Vakwaarneming</h3>
                        <p class="mt-1 text-sm text-gray-500">Ter plaatse vastgesteld; een foto is niet verplicht.</p>
                        <form method="POST" action="{{ route('intakes.workspace.observations.store', $intake) }}" class="mt-4 space-y-4">
                            @csrf
                            <select name="dossier_subject_id" class="block min-h-11 w-full rounded-xl border-gray-300" required>
                                @foreach ($intake->dossierSubjects as $subject)
                                    <option value="{{ $subject->id }}">{{ $subject->label }}</option>
                                @endforeach
                            </select>
                            <x-text-input name="key" class="block w-full" placeholder="bijv. wandopbouw" required />
                            <textarea name="text" rows="4" class="block w-full rounded-xl border-gray-300" placeholder="Wat heeft u vastgesteld?" required></textarea>
                            <select name="method" class="block min-h-11 w-full rounded-xl border-gray-300" required>
                                <option value="on_site">Ter plaatse vastgesteld</option>
                                <option value="from_photo">Vastgesteld vanaf foto</option>
                                <option value="phone">Telefonisch vastgesteld</option>
                                <option value="manual">Handmatig toegevoegd</option>
                            </select>
                            <x-primary-button class="w-full justify-center">Waarneming opslaan</x-primary-button>
                        </form>
                    </section>

                    <section id="demo-customer-task" class="scroll-mt-6 rounded-3xl border border-gray-200 bg-white p-5 shadow-sm">
                        <h3 class="font-semibold text-gray-950">Gerichte klanttaak</h3>
                        <p class="mt-1 text-sm leading-relaxed text-gray-500">
                            De klant ziet alleen deze opdrachten. Pas bij activeren wordt de klantlink actief.
                            @if ($intake->is_demo)
                                De klantweergave opent als simulatie; er wordt geen e-mail verstuurd.
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
                        <form method="POST" action="{{ route('intakes.workspace.tasks.store', $intake) }}" class="mt-4 space-y-4">
                            @csrf
                            @for ($index = 0; $index < 3; $index++)
                                <fieldset class="rounded-2xl border border-gray-200 p-3">
                                    <legend class="px-1 text-xs font-semibold text-gray-500">Opdracht {{ $index + 1 }}{{ $index > 0 ? ' (optioneel)' : '' }}</legend>
                                    <select name="contribution_items[{{ $index }}][type]" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300 text-sm">
                                        @foreach ($followUpTypes as $type)
                                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                        @endforeach
                                    </select>
                                    <textarea name="contribution_items[{{ $index }}][prompt]" rows="3" class="mt-2 block w-full rounded-xl border-gray-300 text-sm" placeholder="{{ $index === 0 ? 'Bijv. Maak een leesbare foto van de volledige meterkast.' : 'Nog een concrete opdracht' }}"></textarea>
                                    <select name="contribution_items[{{ $index }}][decision_area_key]" class="mt-2 block min-h-11 w-full rounded-xl border-gray-300 text-sm">
                                        <option value="">Algemeen dossier</option>
                                        @foreach ($dossier['areas']->where('key', '!=', 'quote') as $area)
                                            <option value="{{ $area->key }}">{{ $area->label }}</option>
                                        @endforeach
                                    </select>
                                </fieldset>
                            @endfor
                            <x-primary-button class="w-full justify-center">
                                {{ $intake->is_demo ? 'Klantweergave activeren' : 'Klanttaak maken en mailen' }}
                            </x-primary-button>
                        </form>
                    </section>

                    <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm">
                        <h3 class="font-semibold text-gray-950">Opnamebijdrage afronden</h3>
                        <p class="mt-1 text-sm leading-relaxed text-gray-500">
                            @if ($proposalAlreadyApproved)
                                De gekozen opstelling en alle bijbehorende verbindingen zijn integraal goedgekeurd.
                            @elseif ($canApproveProposal)
                                Hiermee keurt u de gekozen opstelling integraal goed. Aannemelijke routes worden samen bevestigd; beslissende open punten blijven zichtbaar.
                            @elseif ($selectedOption)
                                Los eerst de beslissende open punten op. Een geblokkeerde route of ontbrekend bewijs wordt nooit stilzwijgend goedgekeurd.
                            @else
                                Selecteer eerst één installatievoorstel met koel-, condens- en stroomverbindingen.
                            @endif
                        </p>
                        @if ($proposalAlreadyApproved)
                            <div class="mt-4 rounded-xl bg-emerald-50 px-4 py-3 text-center text-sm font-semibold text-emerald-800">
                                Integraal goedgekeurd
                            </div>
                        @elseif ($canApproveProposal)
                            <form method="POST" action="{{ route('intakes.workspace.complete', $intake) }}" class="mt-4">
                                @csrf
                                <button class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-gray-950 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                                    Geselecteerd voorstel integraal goedkeuren
                                </button>
                            </form>
                        @else
                            <button type="button" disabled class="mt-4 inline-flex min-h-11 w-full cursor-not-allowed items-center justify-center rounded-xl bg-gray-200 px-4 py-2 text-sm font-semibold text-gray-500">
                                Voorstel nog niet goed te keuren
                            </button>
                        @endif
                    </section>

                    <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm">
                        <h3 class="font-semibold text-gray-950">Uitkomst na offerte of plaatsing</h3>
                        <p class="mt-1 text-sm text-gray-500">Kort vastleggen om bespaarde ritten en montageverrassingen werkelijk te meten.</p>
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
                    </section>
                </aside>
            </div>
        </div>
    </div>
</x-app-layout>
