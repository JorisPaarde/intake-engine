<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center gap-3">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Opname #{{ $intake->id }}</h2>
            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">{{ $intake->status->label() }}</span>
            @if ($intake->is_demo)<span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs text-indigo-700">demo</span>@endif
            @if ($intake->trashed())<span class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700">verwijderd</span>@endif
            <a href="{{ route('dev.intakes') }}" class="ml-auto text-sm text-indigo-600 hover:underline">← Terug</a>
        </div>
    </x-slot>

    @include('dev._nav')

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        {{-- Kerngegevens --}}
        <section class="rounded-lg border border-gray-200 bg-white p-4">
            <h3 class="mb-3 font-semibold text-gray-800">Kerngegevens</h3>
            <dl class="grid grid-cols-1 gap-x-8 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    'uuid' => $intake->uuid,
                    'template' => optional($intake->templateVersion?->template)->key.' v'.optional($intake->templateVersion)->version,
                    'aangemaakt door' => optional($intake->creator)->name ?? '—',
                    'klant' => $intake->customer_name,
                    'e-mail' => $intake->customer_email,
                    'telefoon' => $intake->customer_phone,
                    'adres' => $intake->fullAddress(),
                    'voortgang' => $intake->progress_percent.'%',
                    'gestart' => $intake->started_at,
                    'afgerond' => $intake->completed_at,
                    'beoordeeld' => $intake->reviewed_at,
                    'aangemaakt' => $intake->created_at,
                ] as $label => $value)
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-400">{{ $label }}</dt>
                        <dd class="break-words text-gray-900">{{ $value ?: '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        {{-- Externe feiten --}}
        <section class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-4 py-3"><h3 class="font-semibold text-gray-800">Externe feiten ({{ $intake->externalFacts->count() }})</h3></div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr><th class="px-4 py-2">Key</th><th class="px-4 py-2">Waarde</th><th class="px-4 py-2">Bron</th><th class="px-4 py-2">Zekerheid</th><th class="px-4 py-2">Wanneer</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($intake->externalFacts as $fact)
                            <tr>
                                <td class="px-4 py-2 font-medium text-gray-900">{{ $fact->label ?: $fact->fact_key }}<div class="text-xs text-gray-400">{{ $fact->fact_key }}</div></td>
                                <td class="px-4 py-2 text-xs text-gray-700"><code class="break-all">{{ json_encode($fact->value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></td>
                                <td class="px-4 py-2 text-gray-600">{{ $fact->source }}@if ($fact->source_url)<a href="{{ $fact->source_url }}" class="ml-1 text-indigo-600 hover:underline" target="_blank" rel="noopener">↗</a>@endif</td>
                                <td class="px-4 py-2 text-gray-600">{{ $fact->confidence }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-gray-500" title="{{ $fact->captured_at }}">{{ $fact->captured_at?->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-4 text-center text-gray-400">Geen externe feiten (PDOK/BAG/EP-Online/3DBAG).</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        {{-- AI-runs --}}
        <section class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-4 py-3"><h3 class="font-semibold text-gray-800">AI-runs ({{ $intake->aiRuns->count() }})</h3></div>
            <div class="divide-y divide-gray-100">
                @forelse ($intake->aiRuns->sortByDesc('started_at') as $run)
                    <details class="px-4 py-3">
                        <summary class="flex cursor-pointer flex-wrap items-center gap-x-3 text-sm">
                            <span class="font-medium text-gray-900">{{ $run->type->value }}</span>
                            <span @class([
                                'rounded-full px-2 py-0.5 text-xs font-medium',
                                'bg-green-100 text-green-800' => $run->status->value === 'succeeded',
                                'bg-red-100 text-red-800' => $run->status->value === 'failed',
                                'bg-gray-100 text-gray-600' => $run->status->value === 'pending',
                            ])>{{ $run->status->value }}</span>
                            <span class="text-gray-500">{{ $run->provider }}{{ $run->model ? ' · '.$run->model : '' }}</span>
                            <span class="ml-auto text-xs text-gray-400">{{ $run->started_at?->diffForHumans() }}</span>
                        </summary>
                        <div class="mt-2 space-y-2 text-xs text-gray-600">
                            <div>prompt-versie: {{ $run->prompt_version ?? '—' }} · input-hash: <code>{{ \Illuminate\Support\Str::limit((string) $run->input_hash, 16, '…') }}</code></div>
                            @if ($run->error_message)<div class="rounded bg-red-50 p-2 text-red-700">{{ $run->error_message }}</div>@endif
                            @if ($run->output)<pre class="overflow-x-auto rounded bg-gray-50 p-2">{{ json_encode($run->output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>@endif
                        </div>
                    </details>
                @empty
                    <p class="px-4 py-4 text-center text-sm text-gray-400">Geen AI-runs.</p>
                @endforelse
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Antwoorden --}}
            <section class="rounded-lg border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-4 py-3"><h3 class="font-semibold text-gray-800">Antwoorden ({{ $intake->answers->count() }})</h3></div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($intake->answers as $answer)
                                <tr>
                                    <td class="px-4 py-2 align-top font-medium text-gray-900">{{ $answer->question_key }}@if ($answer->section_instance_key)<span class="text-xs text-gray-400"> [{{ $answer->section_instance_key }}]</span>@endif</td>
                                    <td class="px-4 py-2 align-top text-xs text-gray-700"><code class="break-all">{{ json_encode($answer->value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code>@if ($answer->prefill_source)<div class="text-gray-400">bron: {{ $answer->prefill_source }}</div>@endif</td>
                                </tr>
                            @empty
                                <tr><td class="px-4 py-4 text-center text-gray-400">Geen antwoorden.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Uploads --}}
            <section class="rounded-lg border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-4 py-3"><h3 class="font-semibold text-gray-800">Uploads ({{ $intake->uploads->count() }})</h3></div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($intake->uploads as $upload)
                                <tr>
                                    <td class="px-4 py-2 align-top">
                                        <div class="font-medium text-gray-900">{{ $upload->question_key }}</div>
                                        <div class="text-xs text-gray-500">{{ $upload->original_filename }} · {{ $upload->mime_type }} · {{ number_format($upload->size_bytes / 1024, 0) }} kB</div>
                                        @if ($upload->usability_verdict)<div class="text-xs text-gray-400">bruikbaarheid: {{ $upload->usability_verdict->value }}</div>@endif
                                    </td>
                                    <td class="px-4 py-2 align-top text-right">
                                        <a href="{{ route('installer.uploads.show', [$intake, $upload]) }}" class="text-indigo-600 hover:underline" target="_blank" rel="noopener">bekijk</a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="px-4 py-4 text-center text-gray-400">Geen uploads.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        {{-- Aandachtspunten + review --}}
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-lg border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-4 py-3"><h3 class="font-semibold text-gray-800">Aandachtspunten ({{ $intake->attentionPoints->count() }})</h3></div>
                <ul class="divide-y divide-gray-100 text-sm">
                    @forelse ($intake->attentionPoints as $point)
                        <li class="flex items-center justify-between px-4 py-2">
                            <span class="text-gray-900">{{ $point->label }} <span class="text-xs text-gray-400">({{ $point->source->value }}{{ $point->code ? ' · '.$point->code : '' }})</span></span>
                            <span class="text-xs {{ $point->is_resolved ? 'text-green-600' : 'text-gray-400' }}">{{ $point->is_resolved ? 'opgelost' : 'open' }}</span>
                        </li>
                    @empty
                        <li class="px-4 py-4 text-center text-gray-400">Geen aandachtspunten.</li>
                    @endforelse
                </ul>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-4 py-3"><h3 class="font-semibold text-gray-800">Beoordeling</h3></div>
                <div class="px-4 py-3 text-sm">
                    @if ($intake->review)
                        <dl class="space-y-1">
                            <div><dt class="inline text-gray-400">beslissing:</dt> <dd class="inline text-gray-900">{{ $intake->review->decision->value }}</dd></div>
                            <div><dt class="inline text-gray-400">bezoek nodig:</dt> <dd class="inline text-gray-900">{{ $intake->review->site_visit_needed ? 'ja' : 'nee' }}</dd></div>
                            <div><dt class="inline text-gray-400">genoeg info:</dt> <dd class="inline text-gray-900">{{ $intake->review->enough_information ? 'ja' : 'nee' }}</dd></div>
                            @if ($intake->review->summary)<div class="text-gray-700">{{ $intake->review->summary }}</div>@endif
                        </dl>
                    @else
                        <p class="text-gray-400">Nog niet beoordeeld.</p>
                    @endif
                </div>
            </section>
        </div>

        {{-- Activiteiten-tijdlijn --}}
        <section class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-4 py-3"><h3 class="font-semibold text-gray-800">Activiteiten-tijdlijn ({{ $intake->activityEvents->count() }})</h3></div>
            <ul class="divide-y divide-gray-100 text-sm">
                @forelse ($intake->activityEvents->sortByDesc('created_at') as $event)
                    <li class="flex flex-wrap items-center gap-x-3 px-4 py-2">
                        <span class="text-xs text-gray-400" title="{{ $event->created_at }}">{{ $event->created_at?->format('d-m H:i:s') }}</span>
                        <span class="font-medium text-gray-900">{{ $event->event }}</span>
                        <span class="text-xs text-gray-500">{{ $event->actor_type }}</span>
                        @if (! empty($event->properties))<code class="text-xs text-gray-400">{{ json_encode($event->properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code>@endif
                    </li>
                @empty
                    <li class="px-4 py-4 text-center text-gray-400">Geen activiteit.</li>
                @endforelse
            </ul>
        </section>
    </div>
</x-app-layout>
