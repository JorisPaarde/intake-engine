<x-app-layout>
    <x-slot name="header" width="max-w-4xl">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="eyebrow">Opname</p>
                <h2 class="mt-1 text-2xl font-extrabold leading-tight tracking-tight text-gray-950">{{ $intake->fullAddress() }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ $intake->customer_name }}</p>
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
        $approvalAssessment = app(\App\Domains\Intake\Services\DecisionReadinessService::class)
            ->bulkApprovalAssessment($intake);
        $canApproveProposal = ! $proposalAlreadyApproved && ($approvalAssessment['allowed'] ?? false);
        $approvalBlockers = $approvalAssessment['blockers'] ?? [];
        $hasOpenAiProposals = app(\App\Domains\Intake\Services\DecisionReadinessService::class)
            ->hasOpenAiProposals($intake);
        $showBulkApprovalPanel = $intake->aircoInstallationOptions->isNotEmpty() || $hasOpenAiProposals;
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
        $contributionPresenter = app(\App\Domains\Intake\Support\FollowUpContributionPresenter::class);
        $receivedContribution = $contributionPresenter->present($intake);
        $firstActionableOpenKey = $areaTargetResolver->firstActionableOpenArea($openAreas)?->key;
        $hasOpenPoints = $openAreas->isNotEmpty();
        $customerTaskDraft = is_array($customerTaskDraft ?? null) ? $customerTaskDraft : null;
        $customerTaskDrafts = collect(is_array($customerTaskDrafts ?? null) ? $customerTaskDrafts : [])
            ->filter(static fn (mixed $draft): bool => is_array($draft) && filled($draft['prompt'] ?? null))
            ->values()
            ->all();
        if ($customerTaskDrafts === [] && $customerTaskDraft !== null) {
            $customerTaskDrafts = [$customerTaskDraft];
        }
        $hasCustomerTaskDraft = $customerTaskDrafts !== [];
        $customerTaskDraftSlotCount = min(5, max(3, count($customerTaskDrafts)));
    @endphp

    <div class="py-6 sm:py-8">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900" role="status" data-testid="workspace-status">
                    <p>{{ session('status') }}</p>
                    @if (session('ai_synthesis_partial_detail'))
                        <details class="mt-2 text-xs font-normal text-emerald-900/80" data-testid="ai-synthesis-partial-detail">
                            <summary class="cursor-pointer font-semibold">Technisch detail (beheer)</summary>
                            <p class="mt-1 break-words font-mono leading-relaxed">{{ session('ai_synthesis_partial_detail') }}</p>
                        </details>
                    @endif
                </div>
            @endif

            @if ($receivedContribution['has_new'])
                <div class="rounded-2xl border border-emerald-300 bg-emerald-50 px-4 py-4" role="status" data-testid="new-contribution-banner">
                    <p class="text-sm font-bold text-emerald-950">{{ $receivedContribution['banner_label'] }}</p>
                    <ul class="mt-3 space-y-3">
                        @foreach ($receivedContribution['items'] as $contribution)
                            <li class="rounded-xl border border-emerald-200 bg-white px-3 py-3" data-testid="new-contribution-item">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-xs font-semibold text-emerald-900" data-testid="contribution-heading">{{ $contribution['heading'] }}</p>
                                        @if (is_string($contribution['response_text'] ?? null) && trim((string) $contribution['response_text']) !== '')
                                            <p class="text-sm font-medium text-gray-950">{{ $contribution['response_text'] }}</p>
                                        @elseif (($contribution['uploads'] ?? []) !== [])
                                            <p class="text-sm font-medium text-gray-950">Foto-aanvulling ontvangen (ronde {{ $contribution['round_number'] }})</p>
                                        @else
                                            <p class="text-sm font-medium text-gray-950">Aanvulling ontvangen</p>
                                        @endif
                                        @if (($contribution['ai_facts'] ?? []) !== [])
                                            <ul class="mt-1 space-y-0.5">
                                                @foreach ($contribution['ai_facts'] as $fact)
                                                    <li class="text-xs text-gray-600">{{ $fact }}</li>
                                                @endforeach
                                            </ul>
                                        @endif
                                        <p class="mt-1 text-xs text-emerald-900">{{ $contribution['installer_decides'] }}</p>
                                    </div>
                                    <a
                                        href="{{ $contribution['review_href'] }}"
                                        class="inline-flex min-h-11 items-center rounded-lg bg-marketing-green-dark px-3 py-2 text-xs font-semibold text-white hover:bg-marketing-green"
                                        data-testid="contribution-review-action"
                                        @if (($contribution['highlight_field_ids'] ?? []) !== [])
                                            data-highlight-fields="{{ implode(' ', $contribution['highlight_field_ids']) }}"
                                        @endif
                                    >
                                        {{ $contribution['review_label'] }}
                                    </a>
                                </div>
                                @if (($contribution['uploads'] ?? []) !== [])
                                    <ul class="mt-3 grid grid-cols-4 gap-2 sm:grid-cols-6">
                                        @foreach ($contribution['uploads'] as $contributionUpload)
                                            <li>
                                                <a href="{{ route('installer.uploads.show', [$intake, $contributionUpload]) }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-lg border border-gray-200">
                                                    <img src="{{ route('installer.uploads.show', [$intake, $contributionUpload]) }}" alt="Ontvangen foto" class="aspect-square w-full object-cover">
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (session('error'))
                <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-900" role="alert" data-testid="ai-synthesis-error">
                    <p>{{ session('error') }}</p>
                    @if (session('ai_synthesis_retry'))
                        <form method="POST" action="{{ route('intakes.workspace.synthesis', $intake) }}" class="mt-3">
                            @csrf
                            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-900 hover:bg-red-100" data-testid="ai-synthesis-retry">
                                AI-voorstel opnieuw proberen
                            </button>
                        </form>
                    @endif
                </div>
            @endif

            @php
                $errorFormKey = old('form_key');
                $placementInlineErrorKeys = ['type', 'airco_room_id', 'label', 'description'];
                $couplingInlineErrorKeys = ['indoor_label', 'configuration_type', 'outdoor_placement_id', 'outdoor_label'];
                $inlineErrorKeys = [];
                if (is_string($errorFormKey)) {
                    if (str_starts_with($errorFormKey, 'placement-')) {
                        $inlineErrorKeys = $placementInlineErrorKeys;
                    } elseif (str_starts_with($errorFormKey, 'coupling-')) {
                        $inlineErrorKeys = $couplingInlineErrorKeys;
                    }
                }
                $topErrors = [];
                foreach ($errors->getMessages() as $key => $messages) {
                    if (in_array($key, $inlineErrorKeys, true)) {
                        continue;
                    }
                    foreach ($messages as $message) {
                        $topErrors[] = $message;
                    }
                }
            @endphp
            @if ($topErrors !== [])
                <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert" data-testid="workspace-top-errors">
                    <p class="font-semibold">Dit onderdeel kon nog niet worden opgeslagen.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach ($topErrors as $error)
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
                            <p class="eyebrow">Demo</p>
                            <h3 class="mt-2 text-xl font-semibold text-gray-950">
                                {{ ($demoScenarioLoaded ?? false) ? 'Voorbeelddossier (demo)' : 'Bouw de opname op' }}
                            </h3>
                            <p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-700">
                                @if ($demoScenarioLoaded ?? false)
                                    Dit is een apart gelabeld voorbeelddossier met voorbeeldinhoud. Het is geen vermenging met je eigen aanvraag. Je kunt dit verder bewerken of AI opnieuw laten kijken. Geen echte klant, geen mail.
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
                                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-sky-400 bg-white px-4 py-2 text-sm font-semibold text-sky-900 hover:bg-sky-100" data-testid="load-example-dossier">
                                        Toon voorbeelddossier
                                    </button>
                                </form>
                                <p class="mt-2 text-xs leading-relaxed text-sky-900/70">
                                    Opent een aparte, duidelijk gelabelde demo-opname. Je huidige aanvraag blijft ongewijzigd.
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

            {{-- Sticky next action: pointer-events-none so overlap does not steal Foto maken / field clicks (BL-111) --}}
            <div class="pointer-events-none sticky top-0 z-30 -mx-4 border-b border-gray-200 bg-white/95 px-4 py-3 backdrop-blur supports-[backdrop-filter]:bg-white/90 sm:-mx-6 sm:px-6 lg:mx-0 lg:border lg:px-5 lg:py-4 lg:shadow-md">
                <div class="pointer-events-auto flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="eyebrow">Volgende stap</p>
                        <p class="mt-0.5 truncate text-base font-bold text-gray-950" data-testid="primary-step-summary">{{ $primarySummary }}</p>
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
                    data-testid="primary-step-cta"
                    class="pointer-events-auto mt-2 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-marketing-green-dark px-4 text-sm font-semibold text-white hover:bg-marketing-green"
                >
                    {{ $primaryCtaLabel }}
                </a>
            </div>

            <div class="grid gap-6">
                <main class="min-w-0 space-y-6">
                    {{-- Central Alle onderdelen overview (BL-099): één lijst, geen dubbele open-puntenkaarten --}}
                    <section
                        id="workspace-open-items"
                        @class([
                            'scroll-mt-36 border border-gray-200 bg-[#f7f8f5] p-4 sm:p-6',
                        ])
                    >
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="eyebrow">Overzicht</p>
                                <h3 class="mt-1 text-2xl font-extrabold tracking-tight text-gray-950 sm:text-[28px]">Alle onderdelen</h3>
                                <p class="mt-1 text-sm leading-relaxed text-gray-600">
                                    @if ($hasOpenPoints)
                                        {{ $openAreas->count() }} open · {{ $dossier['ready_count'] }} van {{ $dossier['total_count'] }} klaar voor offerte
                                    @else
                                        Geen open punten meer · {{ $dossier['ready_count'] }} van {{ $dossier['total_count'] }} klaar voor offerte
                                    @endif
                                </p>
                            </div>
                            <span class="shrink-0 bg-[#e5ede7] px-3 py-1.5 text-sm font-bold text-marketing-green-dark">{{ $intake->workflow_mode->label() }}</span>
                        </div>

                        <div class="mt-5 space-y-2.5">
                            @foreach ($dossier['areas'] as $area)
                                @php
                                    $overviewItem = $areaTargetResolver->overviewItem($intake, $area);
                                    $expandByDefault = $hasOpenPoints && $area->key === $firstActionableOpenKey;
                                @endphp
                                <details
                                    id="dossier-area-{{ $area->key }}"
                                    @class([
                                        'group min-w-0 scroll-mt-36 overflow-hidden border transition',
                                        'border-amber-300 bg-amber-50' => $expandByDefault,
                                        'border-gray-200 bg-white hover:border-gray-300' => ! $expandByDefault,
                                    ])
                                    @if ($expandByDefault) open @endif
                                >
                                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3.5 [&::-webkit-details-marker]:hidden">
                                        <span class="flex min-w-0 items-center gap-3">
                                            <span aria-hidden="true" @class([
                                                'h-2.5 w-2.5 shrink-0 rounded-full',
                                                'bg-emerald-500' => $area->status === \App\Enums\DecisionAreaStatus::Ready,
                                                'bg-amber-400' => $area->status === \App\Enums\DecisionAreaStatus::Review,
                                                'bg-red-600' => $area->status === \App\Enums\DecisionAreaStatus::Blocked,
                                                'bg-gray-300' => in_array($area->status, [
                                                    \App\Enums\DecisionAreaStatus::Unknown,
                                                    \App\Enums\DecisionAreaStatus::NotApplicable,
                                                ], true),
                                            ])></span>
                                            <span class="min-w-0 text-[15px] font-bold text-gray-950">{{ $area->label }}</span>
                                        </span>
                                        <span @class([
                                            'shrink-0 text-sm',
                                            'text-gray-600' => $area->status === \App\Enums\DecisionAreaStatus::Ready,
                                            'font-semibold text-amber-800' => $area->status === \App\Enums\DecisionAreaStatus::Review,
                                            'font-semibold text-red-700' => $area->status === \App\Enums\DecisionAreaStatus::Blocked,
                                            'text-gray-500' => in_array($area->status, [
                                                \App\Enums\DecisionAreaStatus::Unknown,
                                                \App\Enums\DecisionAreaStatus::NotApplicable,
                                            ], true),
                                        ])>{{ $area->status->label() }}</span>
                                    </summary>
                                    <div class="min-w-0 space-y-3 border-t border-gray-200/70 px-4 pb-4 pt-3 pl-[2.375rem]">
                                        @php
                                            $areaContributions = $contributionPresenter->forDecisionArea($intake, $area->key);
                                        @endphp
                                        @if ($areaContributions !== [])
                                            <div id="dossier-area-{{ $area->key }}-contribution" class="space-y-2 rounded-xl border border-emerald-200 bg-emerald-50/80 px-3 py-2" data-testid="area-contribution-{{ $area->key }}">
                                                <p class="text-xs font-semibold text-emerald-900">Nieuwe aanvulling ontvangen</p>
                                                @foreach ($areaContributions as $contribution)
                                                    <div class="text-xs text-gray-700">
                                                        @if (is_string($contribution['response_text'] ?? null) && trim((string) $contribution['response_text']) !== '')
                                                            <p class="font-medium text-gray-900">{{ $contribution['response_text'] }}</p>
                                                        @elseif (($contribution['uploads'] ?? []) !== [])
                                                            <ul class="mt-1 grid grid-cols-3 gap-1">
                                                                @foreach ($contribution['uploads'] as $contributionUpload)
                                                                    <li>
                                                                        <a href="{{ route('installer.uploads.show', [$intake, $contributionUpload]) }}" target="_blank" rel="noopener" class="block overflow-hidden rounded border border-emerald-200">
                                                                            <img src="{{ route('installer.uploads.show', [$intake, $contributionUpload]) }}" alt="Aanvulling" class="aspect-square w-full object-cover">
                                                                        </a>
                                                                    </li>
                                                                @endforeach
                                                            </ul>
                                                        @endif
                                                        @foreach ($contribution['ai_facts'] as $fact)
                                                            <p class="mt-1">{{ $fact }}</p>
                                                        @endforeach
                                                        <p class="mt-1 text-emerald-900">{{ $contribution['installer_decides'] }}</p>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif

                                        @if ($overviewItem['detail'])
                                            <p class="break-words text-xs leading-relaxed text-gray-600">{{ $overviewItem['detail'] }}</p>
                                        @elseif (! $overviewItem['is_open'] && $areaContributions === [])
                                            <p class="text-xs text-gray-500">Geen open detail voor dit onderdeel.</p>
                                        @endif

                                        @if ($overviewItem['is_open'])
                                            <a
                                                href="{{ $overviewItem['href'] }}"
                                                class="inline-flex min-h-10 w-full items-center justify-center rounded-lg bg-marketing-green-dark px-3 py-2 text-xs font-semibold text-white hover:bg-marketing-green sm:w-auto"
                                            >
                                                {{ $overviewItem['label'] }} →
                                            </a>

                                            @if ($overviewItem['ask_customer'] !== null)
                                                <x-ask-customer-button :intake="$intake" :ask="$overviewItem['ask_customer']" label="Vraag de klant" class="inline-flex min-h-10 w-full items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-900 hover:bg-gray-50" />
                                            @endif
                                        @endif
                                    </div>
                                </details>
                            @endforeach
                        </div>
                    </section>

                    <section id="workspace-rooms" class="scroll-mt-36 rounded-3xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
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
                                    $floorConflict = $roomMeasures->hasFloorAreaConflict();
                                    $heightNeeded = $room->use_type === 'attic';
                                    $roomCustomerAsk = $customerTaskBuilder->forRoomWithIntake($intake, $room);
                                    $customerDimLabel = \App\Domains\Intake\Support\CustomerAnswerBlocks::roomDimensionsLabel(
                                        is_array($room->dimensions) ? $room->dimensions : null,
                                    );
                                @endphp
                                <article id="room-{{ $room->id }}" class="scroll-mt-36 border border-gray-200 bg-white p-4 sm:p-5">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div class="min-w-0">
                                        <p class="text-base font-bold text-gray-950">{{ $room->name }}</p>
                                        <p class="mt-0.5 text-sm text-gray-500">
                                            {{ $customerDimLabel }}
                                        </p>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="rounded bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600">
                                                {{ match ($room->source_type) {
                                                    'installer' => 'Door installateur toegevoegd',
                                                    'ai' => 'Door AI voorgesteld',
                                                    'customer' => 'Door klant opgegeven',
                                                    'template_bridge' => 'Uit aanvraag overgenomen',
                                                    default => 'Automatisch toegevoegd',
                                                } }}
                                            </span>
                                            @if ($roomCustomerAsk !== null)
                                                <x-ask-customer-button :intake="$intake" :ask="$roomCustomerAsk" label="Vraag de klant" class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-900 hover:bg-gray-50" />
                                            @endif
                                        </div>
                                    </div>

                                    @if (session('block_status.target') === 'room-'.$room->id)
                                        <p class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-900" role="status" data-testid="block-status">{{ session('block_status.message') }}</p>
                                    @endif

                                    @if ($floorConflict)
                                        <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                            Lengte×breedte ({{ number_format((float) $computedArea, 1, ',', '.') }} m²) en opgegeven oppervlak ({{ number_format((float) $areaM2, 1, ',', '.') }} m²) komen niet overeen. Kies één betrouwbare grondslag.
                                        </p>
                                    @elseif ($roomMeasures->hasUntrustedAreaM2() && ! $roomMeasures->hasLengthAndWidth())
                                        <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                            Oppervlak {{ number_format((float) $areaM2, 1, ',', '.') }} m² is nog niet betrouwbaar genoeg
                                            @if ($roomMeasures->areaConfidence())
                                                ({{ \App\Domains\Intake\Support\InstallerDisplayLabels::confidence($roomMeasures->areaConfidence()) }} zekerheid)
                                            @endif
                                            @if ($roomMeasures->areaSource())
                                                · bron: {{ \App\Domains\Intake\Support\InstallerDisplayLabels::source($roomMeasures->areaSource()) }}
                                            @endif
                                            . Bevestig of vul lengte en breedte in.
                                        </p>
                                    @endif

                                    @php
                                        $roomMeasuresOpen = $floorConflict
                                            || ! ($roomMeasures->hasLengthAndWidth() || $roomMeasures->hasTrustedAreaM2());
                                    @endphp
                                    <details class="group/measures mt-4 border-t border-gray-100 pt-3" data-open-on-target @if ($roomMeasuresOpen) open @endif>
                                        <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 text-sm font-semibold text-gray-800 [&::-webkit-details-marker]:hidden">
                                            <span>Maten en gebruik</span>
                                            <span class="text-xs font-medium text-gray-500 group-open/measures:hidden">Aanpassen</span>
                                            <span class="hidden text-xs font-medium text-gray-500 group-open/measures:inline">Inklappen</span>
                                        </summary>
                                    <form
                                        method="POST"
                                        action="{{ route('intakes.workspace.rooms.update', [$intake, $room]) }}"
                                        class="mt-2 grid gap-3 bg-gray-50 p-3 sm:grid-cols-2"
                                        data-warn-unsaved
                                        x-data="{ dirty: false }"
                                        x-on:input="dirty = true"
                                        x-on:change="dirty = true"
                                        x-on:submit="dirty = false"
                                    >
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
                                                    Bron oppervlak: {{ \App\Domains\Intake\Support\InstallerDisplayLabels::source($roomMeasures->areaSource()) }}
                                                @endif
                                                @if ($roomMeasures->areaConfidence())
                                                    · zekerheid: {{ \App\Domains\Intake\Support\InstallerDisplayLabels::confidence($roomMeasures->areaConfidence()) }}
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
                                    </details>

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
                                        $couplingFormKey = 'coupling-'.$room->id;
                                        $couplingFormActive = old('form_key') === $couplingFormKey;
                                        $couplingIndoorLabel = $couplingFormActive
                                            ? old('indoor_label', $roomIndoor?->label ?? ('Binnenunit '.$room->name))
                                            : ($roomIndoor?->label ?? ('Binnenunit '.$room->name));
                                        $couplingConfiguration = $couplingFormActive
                                            ? old('configuration_type', $activeOption?->configuration_type?->value)
                                            : $activeOption?->configuration_type?->value;
                                        $couplingOutdoorId = $couplingFormActive
                                            ? old('outdoor_placement_id', $linkedOutdoor?->id)
                                            : $linkedOutdoor?->id;
                                        $couplingOutdoorLabel = $couplingFormActive ? old('outdoor_label') : null;
                                    @endphp

                                    <details class="group/units border-t border-gray-100 pt-3 mt-3" @if ($couplingFormActive) open x-init="$el.scrollIntoView({block:'center'})" @endif data-form-key="{{ $couplingFormKey }}">
                                        <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 text-sm [&::-webkit-details-marker]:hidden">
                                            <span class="font-semibold text-gray-800">Binnen- en buitenunit</span>
                                            @php
                                                $unitCouplingSummary = match (true) {
                                                    $roomIndoor === null => 'Binnenunit nog kiezen',
                                                    $linkedOutdoor === null => 'Binnenunit: '.$roomIndoor->label.' · buitenunit nog kiezen',
                                                    default => 'Gekoppeld: '.$roomIndoor->label.' → '.$linkedOutdoor->label,
                                                };
                                            @endphp
                                            <span class="flex min-w-0 items-center gap-2 text-xs font-medium">
                                                <span aria-hidden="true" @class(['h-2 w-2 shrink-0 rounded-full', 'bg-emerald-500' => $roomIndoor && $linkedOutdoor, 'bg-amber-400' => ! ($roomIndoor && $linkedOutdoor)])></span>
                                                <span class="min-w-0 break-words text-right text-gray-600" data-testid="room-unit-coupling-summary">{{ $unitCouplingSummary }}</span>
                                            </span>
                                        </summary>
                                        <div class="mt-2 bg-gray-50 p-3">
                                        <p class="text-xs text-gray-500">Koppel de binnenunit van deze ruimte aan een gedeelde buitenunit.</p>

                                        @if ($roomIndoor || $linkedOutdoor || $activeOption)
                                            <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                                                <div>
                                                    <dt class="text-xs text-gray-500">Binnenunit</dt>
                                                    <dd class="font-medium text-gray-900">{{ $roomIndoor?->label ?? 'Nog kiezen' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-gray-500">Buitenunit</dt>
                                                    <dd class="font-medium text-gray-900">{{ $linkedOutdoor?->label ?? 'Nog kiezen' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-gray-500">Configuratie</dt>
                                                    <dd class="font-medium text-gray-900">{{ $activeOption?->configuration_type->label() ?? 'Nog kiezen' }}</dd>
                                                </div>
                                            </dl>
                                        @endif

                                        <form method="POST" action="{{ route('intakes.workspace.rooms.unit-coupling', [$intake, $room]) }}" class="mt-3 grid gap-3 sm:grid-cols-2" data-form-key="{{ $couplingFormKey }}">
                                            @csrf
                                            <input type="hidden" name="form_key" value="{{ $couplingFormKey }}">
                                            @if ($activeOption)
                                                <input type="hidden" name="installation_option_id" value="{{ $activeOption->id }}">
                                            @endif
                                            <div>
                                                <x-input-label for="room-{{ $room->id }}-indoor-label" value="Naam binnenunit" />
                                                <x-text-input
                                                    id="room-{{ $room->id }}-indoor-label"
                                                    name="indoor_label"
                                                    class="mt-1 block w-full"
                                                    value="{{ $couplingIndoorLabel }}"
                                                    required
                                                />
                                                <x-input-error :messages="$couplingFormActive ? $errors->get('indoor_label') : []" class="mt-2" />
                                            </div>
                                            <div>
                                                <x-input-label for="room-{{ $room->id }}-config" value="Configuratie" />
                                                <select id="room-{{ $room->id }}-config" name="configuration_type" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300" required>
                                                    @foreach ($configurationTypes as $type)
                                                        <option value="{{ $type->value }}" @selected($couplingConfiguration === $type->value)>
                                                            {{ $type->label() }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <x-input-error :messages="$couplingFormActive ? $errors->get('configuration_type') : []" class="mt-2" />
                                            </div>
                                            <div>
                                                <x-input-label for="room-{{ $room->id }}-outdoor" value="Buitenunit" />
                                                <select id="room-{{ $room->id }}-outdoor" name="outdoor_placement_id" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300">
                                                    <option value="">Nieuwe buitenunit…</option>
                                                    @foreach ($outdoorChoices as $outdoor)
                                                        <option value="{{ $outdoor->id }}" @selected((int) $couplingOutdoorId === $outdoor->id)>
                                                            {{ $outdoor->label }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <x-input-error :messages="$couplingFormActive ? $errors->get('outdoor_placement_id') : []" class="mt-2" />
                                            </div>
                                            <div>
                                                <x-input-label for="room-{{ $room->id }}-outdoor-label" value="Nieuwe buitenunit (naam)" />
                                                <x-text-input
                                                    id="room-{{ $room->id }}-outdoor-label"
                                                    name="outdoor_label"
                                                    class="mt-1 block w-full"
                                                    value="{{ $couplingOutdoorLabel }}"
                                                    placeholder="Bijv. plat dak aanbouw"
                                                />
                                                <x-input-error :messages="$couplingFormActive ? $errors->get('outdoor_label') : []" class="mt-2" />
                                            </div>
                                            <div class="sm:col-span-2">
                                                <x-primary-button>Koppeling opslaan</x-primary-button>
                                            </div>
                                        </form>
                                        </div>
                                    </details>

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
                            <summary class="-my-3 cursor-pointer py-3 text-sm font-semibold text-gray-900">Ruimte toevoegen</summary>
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

                        @include('installer.intakes.partials.customer-answers', [
                            'intake' => $intake,
                            'wrapperClass' => 'mt-6 border-t border-gray-100 pt-5',
                        ])
                    </section>

                    <section id="demo-placements" class="scroll-mt-36 rounded-3xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
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
                                    <article id="placement-{{ $placement->id }}" class="scroll-mt-36 rounded-2xl border border-gray-200 p-4">
                                        <p class="text-xs font-extrabold uppercase tracking-[0.06em] text-gray-600">{{ $placement->type->label() }}</p>
                                        <h4 class="mt-1 font-semibold text-gray-950">{{ $placement->label }}</h4>
                                        @if ($placement->room)
                                            <p class="mt-1 text-xs text-gray-500">{{ $placement->room->name }}</p>
                                        @endif
                                        @if (session('block_status.target') === 'placement-'.$placement->id)
                                            <p class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-900" role="status" data-testid="block-status">{{ session('block_status.message') }}</p>
                                        @endif
                                        @if ($placement->description)
                                            <p class="mt-2 text-sm leading-relaxed text-gray-600">{{ $placement->description }}</p>
                                        @endif
                                        @php
                                            $placementEvidenceRefs = is_array($placement->location_data['evidence_references'] ?? null)
                                                ? $placement->location_data['evidence_references']
                                                : [];
                                            $placementEvidence = $placementEvidenceRefs !== []
                                                ? app(\App\Domains\Intake\Support\InstallerEvidencePresenter::class)
                                                    ->presentSynthesisReferences($intake, $placementEvidenceRefs)
                                                : [];
                                        @endphp
                                        @if ($placementEvidence !== [])
                                            <div class="mt-2" data-testid="placement-evidence">
                                                <p class="text-xs font-semibold text-gray-600">Bewijs</p>
                                                <x-evidence-citations :citations="$placementEvidence" />
                                            </div>
                                        @endif
                                        <p class="mt-2 text-xs text-gray-500">
                                            {{ match ($placement->source_type) {
                                                'installer' => 'Door installateur toegevoegd',
                                                'ai' => 'Door AI voorgesteld',
                                                'customer' => 'Door klant opgegeven',
                                                default => 'Automatisch toegevoegd',
                                            } }}
                                        </p>

                                        @php
                                            $placementFormKey = 'placement-'.$placement->id;
                                            $placementFormActive = old('form_key') === $placementFormKey;
                                            $placementFormType = $placementFormActive ? old('type', $placement->type->value) : $placement->type->value;
                                            $placementFormType = \App\Enums\AircoPlacementType::tryFrom((string) $placementFormType)?->value
                                                ?? $placement->type->value;
                                            $placementFormRoomId = $placementFormActive ? old('airco_room_id', $placement->airco_room_id) : $placement->airco_room_id;
                                            $placementFormLabel = $placementFormActive ? old('label', $placement->label) : $placement->label;
                                            $placementFormDescription = $placementFormActive ? old('description', $placement->description) : $placement->description;
                                        @endphp
                                        <details class="mt-4 rounded-xl border border-gray-200 bg-gray-50" @if ($placementFormActive) open x-init="$el.scrollIntoView({block:'center'})" @endif data-form-key="{{ $placementFormKey }}">
                                            <summary class="flex min-h-11 cursor-pointer list-none items-center px-3 py-2 text-sm font-semibold text-gray-800">
                                                Bewerken
                                            </summary>
                                            <form
                                                method="POST"
                                                action="{{ route('intakes.workspace.placements.update', [$intake, $placement]) }}"
                                                class="grid gap-3 border-t border-gray-200 p-3"
                                                x-data="{ type: @js($placementFormType) }"
                                            >
                                                @csrf
                                                <input type="hidden" name="form_key" value="{{ $placementFormKey }}">
                                                <fieldset class="sm:col-span-2">
                                                    <legend class="sr-only">Soort unit of aansluiting</legend>
                                                    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,max(11rem,calc(50%_-_0.25rem))),1fr))] gap-2">
                                                        @foreach ($placementTypes as $type)
                                                            <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-900 has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50">
                                                                <input
                                                                    type="radio"
                                                                    id="placement-{{ $placement->id }}-type-{{ $type->value }}"
                                                                    name="type"
                                                                    value="{{ $type->value }}"
                                                                    class="border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                                    @checked($placementFormType === $type->value)
                                                                    x-model="type"
                                                                    required
                                                                >
                                                                <span class="min-w-0 break-words hyphens-auto">{{ $type->label() }}</span>
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                    <x-input-error :messages="$placementFormActive ? $errors->get('type') : []" class="mt-2" />
                                                </fieldset>
                                                <div>
                                                    <x-input-label for="placement-{{ $placement->id }}-room" value="Ruimte (verplicht bij binnenunit)" />
                                                    <select
                                                        id="placement-{{ $placement->id }}-room"
                                                        name="airco_room_id"
                                                        class="mt-1 block min-h-11 w-full rounded-xl border-gray-300"
                                                    >
                                                        <option value="" x-text="type === 'indoor_unit' ? 'Kies een ruimte' : 'Algemeen / buitenzijde'">
                                                            {{ $placementFormType === 'indoor_unit' ? 'Kies een ruimte' : 'Algemeen / buitenzijde' }}
                                                        </option>
                                                        @foreach ($intake->aircoRooms as $room)
                                                            <option value="{{ $room->id }}" @selected((string) $placementFormRoomId === (string) $room->id)>{{ $room->name }}</option>
                                                        @endforeach
                                                    </select>
                                                    <x-input-error :messages="$placementFormActive ? $errors->get('airco_room_id') : []" class="mt-2" />
                                                </div>
                                                <div>
                                                    <x-input-label for="placement-{{ $placement->id }}-label" value="Naam (verplicht)" />
                                                    <x-text-input
                                                        id="placement-{{ $placement->id }}-label"
                                                        name="label"
                                                        class="mt-1 block w-full"
                                                        value="{{ $placementFormLabel }}"
                                                        placeholder="Bijv. Unit slaapkamer 1"
                                                        required
                                                    />
                                                    <x-input-error :messages="$placementFormActive ? $errors->get('label') : []" class="mt-2" />
                                                </div>
                                                <div>
                                                    <x-input-label for="placement-{{ $placement->id }}-description" value="Notitie" />
                                                    <textarea id="placement-{{ $placement->id }}-description" name="description" rows="3" class="mt-1 block w-full rounded-xl border-gray-300">{{ $placementFormDescription }}</textarea>
                                                    <x-input-error :messages="$placementFormActive ? $errors->get('description') : []" class="mt-2" />
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

                        @php
                            $newPlacementFormKey = 'placement-new';
                            $newPlacementFormActive = old('form_key') === $newPlacementFormKey;
                            $newPlacementFormType = $newPlacementFormActive
                                ? old('type', \App\Enums\AircoPlacementType::IndoorUnit->value)
                                : \App\Enums\AircoPlacementType::IndoorUnit->value;
                            $newPlacementFormType = \App\Enums\AircoPlacementType::tryFrom((string) $newPlacementFormType)?->value
                                ?? \App\Enums\AircoPlacementType::IndoorUnit->value;
                        @endphp
                        <details class="mt-5 rounded-2xl border border-gray-200 bg-gray-50 p-4" @if ($newPlacementFormActive) open x-init="$el.scrollIntoView({block:'center'})" @endif data-form-key="{{ $newPlacementFormKey }}">
                            <summary class="-my-3 cursor-pointer py-3 text-sm font-semibold text-gray-900">Binnen- of buitenunit toevoegen</summary>
                            <form
                                method="POST"
                                action="{{ route('intakes.workspace.placements.store', $intake) }}"
                                class="mt-4 grid gap-4 sm:grid-cols-2"
                                x-data="{ type: @js($newPlacementFormType) }"
                            >
                                @csrf
                                <input type="hidden" name="form_key" value="{{ $newPlacementFormKey }}">
                                <fieldset class="sm:col-span-2">
                                    <legend class="sr-only">Soort unit of aansluiting</legend>
                                    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,max(11rem,calc(50%_-_0.25rem))),1fr))] gap-2">
                                        @foreach ($placementTypes as $type)
                                            <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-900 has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50">
                                                <input
                                                    type="radio"
                                                    id="placement_type_{{ $type->value }}"
                                                    name="type"
                                                    value="{{ $type->value }}"
                                                    class="border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                    @checked($newPlacementFormType === $type->value)
                                                    x-model="type"
                                                    required
                                                >
                                                <span class="min-w-0 break-words hyphens-auto">{{ $type->label() }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <x-input-error :messages="$newPlacementFormActive ? $errors->get('type') : []" class="mt-2" />
                                </fieldset>
                                <div>
                                    <x-input-label for="placement_room" value="Ruimte (verplicht bij binnenunit)" />
                                    <select id="placement_room" name="airco_room_id" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300">
                                        <option value="" x-text="type === 'indoor_unit' ? 'Kies een ruimte' : 'Algemeen / buitenzijde'">
                                            {{ $newPlacementFormType === 'indoor_unit' ? 'Kies een ruimte' : 'Algemeen / buitenzijde' }}
                                        </option>
                                        @foreach ($intake->aircoRooms as $room)
                                            <option value="{{ $room->id }}" @selected($newPlacementFormActive && (string) old('airco_room_id') === (string) $room->id)>{{ $room->name }}</option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$newPlacementFormActive ? $errors->get('airco_room_id') : []" class="mt-2" />
                                </div>
                                <div class="sm:col-span-2">
                                    <x-input-label for="placement_label" value="Naam (verplicht)" />
                                    <x-text-input
                                        id="placement_label"
                                        name="label"
                                        class="mt-1 block w-full"
                                        value="{{ $newPlacementFormActive ? old('label') : '' }}"
                                        placeholder="Bijv. Unit slaapkamer 1"
                                        required
                                    />
                                    <x-input-error :messages="$newPlacementFormActive ? $errors->get('label') : []" class="mt-2" />
                                </div>
                                <div class="sm:col-span-2">
                                    <x-input-label for="placement_description" value="Notitie" />
                                    <textarea id="placement_description" name="description" rows="3" class="mt-1 block w-full rounded-xl border-gray-300" placeholder="Vrije wand, bereikbaarheid, obstakels…">{{ $newPlacementFormActive ? old('description') : '' }}</textarea>
                                    <x-input-error :messages="$newPlacementFormActive ? $errors->get('description') : []" class="mt-2" />
                                </div>
                                <div class="sm:col-span-2">
                                    <x-primary-button>Opslaan</x-primary-button>
                                </div>
                            </form>
                        </details>
                    </section>

                    <section id="demo-proposal" class="scroll-mt-36 rounded-3xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-950">Multi-split of singles</h3>
                            <p class="mt-1 text-sm text-gray-500">Beoordeel eerst wat technisch haalbaar is. Vraag pas daarna een klantvoorkeur.</p>
                        </div>

                        @if (! empty($preferenceState['stale_preference']))
                            <div class="mt-4 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950" role="status">
                                De eerdere klantvoorkeur is verouderd omdat de haalbare keuzes zijn gewijzigd. Beoordeel opnieuw en stuur zo nodig een nieuwe voorkeurstaak.
                            </div>
                        @endif

                        <div class="mt-5 space-y-5">
                            @forelse ($intake->aircoInstallationOptions as $option)
                                <article @class([
                                    'rounded-2xl border p-4 sm:p-5',
                                    'border-emerald-300 bg-emerald-50/40' => $option->status === \App\Enums\AircoOptionStatus::Selected,
                                    'border-rose-200 bg-rose-50/30' => $option->feasibility === \App\Enums\AircoOptionFeasibility::Infeasible,
                                    'border-sky-200 bg-sky-50/30' => $option->feasibility === \App\Enums\AircoOptionFeasibility::Feasible
                                        && $option->status !== \App\Enums\AircoOptionStatus::Selected,
                                    'border-gray-200' => $option->status !== \App\Enums\AircoOptionStatus::Selected
                                        && $option->feasibility === \App\Enums\AircoOptionFeasibility::Pending,
                                ])>
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <p class="text-xs font-extrabold uppercase tracking-[0.06em] text-gray-600">{{ $option->configuration_type->label() }}</p>
                                                <span class="rounded bg-white px-2.5 py-0.5 text-xs font-semibold text-gray-700 ring-1 ring-gray-200">
                                                    {{ $option->feasibility->label() }}
                                                </span>
                                            </div>
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
                                            @if ($option->feasibility === \App\Enums\AircoOptionFeasibility::Infeasible && $option->infeasibility_reason)
                                                <p class="mt-2 text-sm text-rose-800"><span class="font-semibold">Niet haalbaar:</span> {{ $option->infeasibility_reason }}</p>
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
                                        <div class="flex flex-col items-stretch gap-2 sm:items-end">
                                            @if ($option->status === \App\Enums\AircoOptionStatus::Selected)
                                                <span class="rounded bg-emerald-600 px-3 py-1 text-xs font-semibold text-white">Geselecteerd</span>
                                            @elseif ($option->feasibility === \App\Enums\AircoOptionFeasibility::Feasible)
                                                <form method="POST" action="{{ route('intakes.workspace.options.select', [$intake, $option]) }}">
                                                    @csrf
                                                    <button class="min-h-11 rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Deze keuze</button>
                                                </form>
                                            @endif
                                            @if ($option->feasibility !== \App\Enums\AircoOptionFeasibility::Feasible)
                                                <form method="POST" action="{{ route('intakes.workspace.options.feasible', [$intake, $option]) }}">
                                                    @csrf
                                                    <button class="min-h-11 w-full rounded-xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-500 sm:w-auto">Markeer haalbaar</button>
                                                </form>
                                            @endif
                                            @if ($option->feasibility !== \App\Enums\AircoOptionFeasibility::Infeasible && $option->status !== \App\Enums\AircoOptionStatus::Selected)
                                                <details class="rounded-xl border border-rose-200 bg-white p-3">
                                                    <summary class="-my-3 cursor-pointer py-3 text-sm font-semibold text-rose-800">Niet haalbaar</summary>
                                                    <form method="POST" action="{{ route('intakes.workspace.options.infeasible', [$intake, $option]) }}" class="mt-3 space-y-2">
                                                        @csrf
                                                        <label class="block text-xs font-medium text-gray-700" for="infeasible-reason-{{ $option->id }}">Waarom niet?</label>
                                                        <textarea id="infeasible-reason-{{ $option->id }}" name="infeasibility_reason" rows="2" class="block w-full rounded-xl border-gray-300 text-sm" required placeholder="Bijv. te lange koelroute of geen geschikte buitenplek."></textarea>
                                                        <button class="min-h-10 rounded-lg bg-rose-600 px-3 py-2 text-xs font-semibold text-white">Opslaan als niet haalbaar</button>
                                                    </form>
                                                </details>
                                            @endif
                                        </div>
                                    </div>

                                    <ul class="mt-4 flex flex-wrap gap-2">
                                        @foreach ($option->placements as $placement)
                                            <li class="rounded bg-white px-3 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-200">
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
                                            <div id="connection-{{ $connection->id }}" class="scroll-mt-36 rounded-2xl border border-gray-200 bg-white p-4">
                                                <div class="flex flex-wrap items-start justify-between gap-3">
                                                    <div>
                                                        <p class="text-xs font-extrabold uppercase tracking-[0.06em] text-gray-600">{{ $connection->type->label() }}</p>
                                                        <h5 class="mt-1 font-semibold text-gray-950">{{ $connection->label }}</h5>
                                                        <p class="mt-1 text-xs text-gray-500">
                                                            {{ $connection->fromPlacement?->label ?? 'Beginpunt open' }}
                                                            →
                                                            {{ $connection->toPlacement?->label ?? 'Eindpunt open' }}
                                                            @if ($connection->length_class)
                                                                · {{ \App\Domains\Intake\Support\InstallerDisplayLabels::lengthClass($connection->length_class) }}
                                                            @endif
                                                        </p>
                                                    </div>
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <span class="rounded bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700">{{ $connection->status->label() }}</span>
                                                        @if ($connectionCustomerAsk !== null)
                                                            <x-ask-customer-button :intake="$intake" :ask="$connectionCustomerAsk" label="Vraag de klant" class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-900 hover:bg-gray-50" />
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
                                        <summary class="-my-3 cursor-pointer py-3 text-sm font-semibold text-gray-900">Koel-, condens- of stroomroute toevoegen</summary>
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

                        @if (($preferenceState['available'] ?? false) === true)
                            <div id="demo-preference" class="mt-5 rounded-2xl border border-indigo-200 bg-indigo-50/60 p-4">
                                <h4 class="text-sm font-semibold text-indigo-950">Klantvoorkeur vragen</h4>
                                <p class="mt-1 text-sm text-indigo-900/80">Er zijn {{ $preferenceState['feasible_count'] }} haalbare keuzes. Controleer de taak en stuur die naar de klant. Jij blijft eindverantwoordelijk voor de keuze.</p>
                                <p class="mt-3 rounded-xl bg-white/80 px-3 py-2 text-sm text-gray-800">{{ $preferenceState['prompt'] }}</p>
                                <ul class="mt-3 space-y-1 text-sm text-gray-700">
                                    @foreach ($preferenceState['choices'] as $choice)
                                        <li>· {{ $choice['label'] }}</li>
                                    @endforeach
                                </ul>
                                <form method="POST" action="{{ route('intakes.workspace.options.preference', $intake) }}" class="mt-4">
                                    @csrf
                                    <button class="min-h-11 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Voorkeurstaak versturen</button>
                                </form>
                            </div>
                        @elseif (($preferenceState['feasible_count'] ?? 0) === 1)
                            <p class="mt-5 text-sm text-gray-500">Eén haalbare keuze: geen voorkeurvraag nodig. Selecteer die keuze zelf.</p>
                        @elseif (($preferenceState['feasible_count'] ?? 0) === 0 && $intake->aircoInstallationOptions->isNotEmpty())
                            <p class="mt-5 text-sm text-gray-500">Nog geen klantvoorkeur: markeer eerst minstens twee keuzes als haalbaar.</p>
                        @endif

                        <details class="mt-5 rounded-2xl border border-gray-200 bg-gray-50 p-4">
                            <summary class="-my-3 cursor-pointer py-3 text-sm font-semibold text-gray-900">Kies multi-split of singles</summary>
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

                    {{-- Conceptlijst: Vraag de klant voegt toe; versturen activeert één ronde (max 5). --}}
                    <section id="demo-customer-task" @class([
                        'scroll-mt-36 rounded-3xl border border-gray-200 bg-white p-5 shadow-sm',
                        'hidden target:block' => $proposedCustomerTasks->isEmpty() && ! $hasCustomerTaskDraft,
                    ])>
                        <h3 class="font-semibold text-gray-950">Taak voor de klant</h3>
                        <p class="mt-1 text-sm text-gray-500">
                            @if ($hasCustomerTaskDraft)
                                Conceptlijst met {{ count($customerTaskDrafts) }} opdracht{{ count($customerTaskDrafts) === 1 ? '' : 'en' }}. Controleer de klanttekst en verstuur daarna één ronde.
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
                                        <p class="eyebrow">AI-voorstel · {{ $task->type->label() }}</p>
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
                        <details class="mt-4 rounded-2xl border border-gray-200 bg-gray-50 p-4" @if ($hasCustomerTaskDraft) open @endif>
                            <summary class="-my-3 cursor-pointer py-3 text-sm font-semibold text-gray-900">
                                {{ $hasCustomerTaskDraft ? 'Conceptlijst controleren en versturen' : 'Klanttaak maken' }}
                            </summary>
                            @if ($hasCustomerTaskDraft)
                                <div class="mt-3 space-y-2" data-testid="customer-task-draft-list">
                                    @foreach ($customerTaskDrafts as $previewIndex => $previewDraft)
                                        <article class="rounded-xl border border-emerald-200 bg-white px-3 py-2" data-testid="customer-task-draft-preview" data-draft-index="{{ $previewIndex }}">
                                            <p class="text-xs font-semibold text-emerald-800">Opdracht {{ $previewIndex + 1 }} · klaar om te controleren</p>
                                            <p class="mt-1 text-sm text-gray-950">{{ $previewDraft['prompt'] }}</p>
                                        </article>
                                    @endforeach
                                </div>
                            @endif
                            <form method="POST" action="{{ route('intakes.workspace.tasks.store', $intake) }}" class="mt-4 space-y-4" data-testid="customer-task-draft-form">
                                @csrf
                                @for ($index = 0; $index < $customerTaskDraftSlotCount; $index++)
                                    @php
                                        $slotDraft = $customerTaskDrafts[$index] ?? null;
                                        $draftType = is_array($slotDraft) ? ($slotDraft['type'] ?? null) : null;
                                        $draftPrompt = is_array($slotDraft) ? ($slotDraft['prompt'] ?? '') : '';
                                        $draftArea = is_array($slotDraft) ? ($slotDraft['decision_area_key'] ?? '') : '';
                                        $draftSubjectId = is_array($slotDraft) ? ($slotDraft['dossier_subject_id'] ?? null) : null;
                                    @endphp
                                    <fieldset class="rounded-2xl border border-gray-200 bg-white p-3" @if ($draftPrompt !== '') data-testid="customer-task-draft-slot" @endif>
                                        <legend class="px-1 text-xs font-semibold text-gray-500">Opdracht {{ $index + 1 }}{{ $index > 0 && $draftPrompt === '' ? ' (optioneel)' : '' }}</legend>
                                        <select name="contribution_items[{{ $index }}][type]" class="mt-1 block min-h-11 w-full rounded-xl border-gray-300 text-sm">
                                            @foreach ($followUpTypes as $type)
                                                <option value="{{ $type->value }}" @selected($draftType === $type->value)>{{ $type->label() }}</option>
                                            @endforeach
                                        </select>
                                        <textarea name="contribution_items[{{ $index }}][prompt]" rows="3" class="mt-2 block w-full rounded-xl border-gray-300 text-sm" placeholder="{{ $index === 0 ? 'Bijv. Maak een leesbare foto van de volledige meterkast.' : 'Nog een concrete opdracht' }}" @if ($draftPrompt !== '') data-testid="customer-task-draft-prompt" @endif>{{ $draftPrompt }}</textarea>
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
                                <x-primary-button class="w-full justify-center" data-testid="customer-task-draft-send">
                                    {{ $intake->is_demo ? 'Klantweergave activeren' : 'Klanttaak maken en mailen' }}
                                </x-primary-button>
                            </form>
                        </details>
                    </section>

                    <section id="workspace-complete" class="scroll-mt-36 rounded-3xl border border-gray-200 bg-white p-5 shadow-sm">
                        <h3 class="font-semibold text-gray-950">Voorstel afronden</h3>
                        @if ($proposalAlreadyApproved)
                            <div class="mt-3 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
                                Goedgekeurd. Leg hieronder de uitkomst vast als je wilt.
                            </div>
                            <a href="#workspace-outcome" class="mt-3 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-emerald-300 bg-white px-4 text-sm font-semibold text-emerald-900 hover:bg-emerald-50">
                                Uitkomst vastleggen
                            </a>
                        @elseif ($canApproveProposal)
                            <p class="mt-1 text-sm text-gray-500">Keurt je keuze en de routes in één keer goed. Open onzekerheden en niet-bedekte ruimtes blijven blokkeren.</p>
                            <form method="POST" action="{{ route('intakes.workspace.complete', $intake) }}" class="mt-4">
                                @csrf
                                <button class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-marketing-green-dark px-4 py-2 text-sm font-semibold text-white hover:bg-marketing-green" data-testid="approve-proposal">
                                    Voorstel goedkeuren
                                </button>
                            </form>
                        @elseif ($showBulkApprovalPanel)
                            {{-- Visible for AI Candidate options AND when synthesis left only attention/placement proposals (no option). --}}
                            <p class="mt-1 text-sm text-gray-500">Los eerst de open punten op. Daarna kun je goedkeuren. Een locatiebezoek als uitkomst blijft mogelijk. Losse AI-voorstellen kun je hierboven per stuk accepteren of verwijderen.</p>
                            @if ($approvalBlockers !== [])
                                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-amber-900" data-testid="approval-blockers">
                                    @foreach ($approvalBlockers as $blocker)
                                        <li>{{ $blocker }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            <button type="button" disabled class="mt-4 inline-flex min-h-11 w-full cursor-not-allowed items-center justify-center rounded-xl bg-gray-200 px-4 py-2 text-sm font-semibold text-gray-500" data-testid="approval-not-ready">
                                Nog niet klaar om goed te keuren
                            </button>
                        @else
                            <p class="mt-1 text-sm text-gray-500">Kies eerst multi-split of singles met koel-, condens- en stroomroute.</p>
                            <a href="#demo-proposal" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-800 hover:bg-gray-50">
                                Naar multi-split of singles
                            </a>
                        @endif
                    </section>

                    <section id="demo-ai" class="scroll-mt-36 rounded-3xl border border-indigo-100 bg-indigo-50/50 shadow-sm">
                        <details class="group" @if ($aiSectionOpen) open @endif>
                            <summary class="cursor-pointer list-none px-5 py-4 sm:px-6 [&::-webkit-details-marker]:hidden">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <p class="eyebrow">AI-opnameassistent</p>
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
                                                        @php
                                                            $exceptionEvidenceRefs = is_array($exception['evidence_references'] ?? null)
                                                                ? $exception['evidence_references']
                                                                : [];
                                                            $exceptionEvidence = $exceptionEvidenceRefs !== []
                                                                ? app(\App\Domains\Intake\Support\InstallerEvidencePresenter::class)
                                                                    ->presentSynthesisReferences($intake, $exceptionEvidenceRefs)
                                                                : [];
                                                        @endphp
                                                        @if ($exceptionEvidence !== [])
                                                            <div class="mt-2" data-testid="synthesis-exception-evidence">
                                                                <x-evidence-citations :citations="$exceptionEvidence" />
                                                            </div>
                                                        @endif
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

                    <section id="demo-context" class="scroll-mt-36 rounded-3xl border border-gray-200 bg-white shadow-sm">
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
                                @if ($rootSubject)
                                    @include('installer.intakes._subject-tools', [
                                        'intake' => $intake,
                                        'subject' => $rootSubject,
                                        'connection' => null,
                                    ])
                                @endif
                                @if ($externalData['aerial_image'])
                                    <details class="mt-4 overflow-hidden rounded-2xl border border-gray-200 bg-gray-50">
                                        <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-gray-800">Luchtfoto van de omgeving bekijken</summary>
                                        <figure class="border-t border-gray-200 bg-white">
                                            <img src="{{ route('intakes.aerial.show', $intake) }}" alt="Luchtfoto van de woningomgeving" class="aspect-[3/2] w-full object-cover" loading="lazy">
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

                    <section id="demo-evidence" class="scroll-mt-36 rounded-3xl border border-gray-200 bg-white shadow-sm">
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
                                                <li @class(['opacity-60' => ($item['superseded'] ?? false) === true]) data-testid="gallery-upload-{{ $item['upload']->id }}" @if (($item['superseded'] ?? false) === true) data-superseded="1" @endif>
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
                                                        @if (($item['superseded'] ?? false) === true && ! empty($item['supersession_label']))
                                                            <span class="block px-3 pb-2 text-[11px] font-medium text-gray-500" data-testid="gallery-superseded">{{ $item['supersession_label'] }}</span>
                                                        @endif
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

                <aside class="space-y-6">
                    {{-- Voorlopig verborgen (producteigenaar); blijft bereikbaar via de CTA-link #workspace-outcome. --}}
                    <section id="workspace-outcome" class="hidden scroll-mt-36 rounded-3xl border border-gray-200 bg-white shadow-sm target:block" data-testid="workspace-outcome">
                        <details @if ($intake->outcome) open @endif>
                            <summary class="cursor-pointer list-none px-5 py-4 [&::-webkit-details-marker]:hidden">
                                <h3 class="text-base font-semibold text-gray-950">Uitkomst na offerte of plaatsing</h3>
                                @if ($intake->outcome)
                                    <p class="mt-1 text-sm text-gray-500" data-testid="outcome-summary-label">
                                        Opgeslagen
                                        @if ($intake->outcome->active_installer_minutes === null && $intake->outcome->customer_minutes === null)
                                            · minuten later invullen
                                        @endif
                                        · tik om te wijzigen
                                    </p>
                                @else
                                    <p class="mt-1 text-sm text-gray-500" data-testid="outcome-summary-label">Later invullen · tik om te openen</p>
                                @endif
                            </summary>
                            <div class="border-t border-gray-100 px-5 py-4">
                        @php
                            $recordedVisitReasons = old('site_visit_reasons', $intake->outcome?->site_visit_reasons ?? []);
                            $recordedProposalDeltas = old('proposal_delta_codes', $intake->outcome?->proposal_delta['codes'] ?? []);
                            $recordedSiteVisitOccurred = old('site_visit_occurred', $intake->outcome?->site_visit_occurred);
                        @endphp
                        <form method="POST" action="{{ route('intakes.workspace.outcome', $intake) }}" class="mt-4 space-y-3" data-testid="outcome-form">
                            @csrf
                            <select name="result" class="block min-h-11 w-full rounded-xl border-gray-300" required data-testid="outcome-result">
                                <option value="remote_quote" @selected(old('result', $intake->outcome?->result) === 'remote_quote')>Op afstand geoffreerd</option>
                                <option value="estimate" @selected(old('result', $intake->outcome?->result) === 'estimate')>Prijsindicatie</option>
                                <option value="site_visit" @selected(old('result', $intake->outcome?->result) === 'site_visit')>Locatiebezoek</option>
                                <option value="installed" @selected(old('result', $intake->outcome?->result) === 'installed')>Geplaatst</option>
                                <option value="rejected" @selected(old('result', $intake->outcome?->result) === 'rejected')>Afgewezen</option>
                            </select>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <x-input-label value="Installateur min." />
                                    <x-text-input name="active_installer_minutes" type="number" min="0" class="mt-1 block w-full" :value="old('active_installer_minutes', $intake->outcome?->active_installer_minutes)" data-testid="outcome-installer-minutes" />
                                </div>
                                <div>
                                    <x-input-label value="Klant min." />
                                    <x-text-input name="customer_minutes" type="number" min="0" class="mt-1 block w-full" :value="old('customer_minutes', $intake->outcome?->customer_minutes)" data-testid="outcome-customer-minutes" />
                                </div>
                            </div>
                            <p class="text-xs text-gray-500">Resultaat “Locatiebezoek” betekent dat een bezoek nodig is. Vink hieronder apart aan of het bezoek al is uitgevoerd.</p>
                            <label class="flex min-h-11 items-center gap-3 rounded-xl border border-gray-200 px-3 text-sm">
                                <input type="checkbox" name="site_visit_occurred" value="1" class="rounded border-gray-300" data-testid="outcome-site-visit-occurred" @checked($recordedSiteVisitOccurred)>
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

            // Warn when leaving room forms with unsaved typed values (BL-110).
            window.addEventListener('beforeunload', function (event) {
                const dirty = Array.from(document.querySelectorAll('[data-warn-unsaved]')).some(function (form) {
                    if (typeof Alpine === 'undefined' || !Alpine.$data) {
                        return false;
                    }
                    try {
                        return Boolean(Alpine.$data(form)?.dirty);
                    } catch (e) {
                        return false;
                    }
                });
                if (!dirty) {
                    return;
                }
                event.preventDefault();
                event.returnValue = '';
            });
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
