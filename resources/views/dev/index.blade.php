<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Dev-admin — overzicht</h2>
        <p class="mt-1 text-sm text-gray-500">Status van externe diensten en binnengekomen data op deze omgeving. Geen live calls; afgeleid uit opgeslagen resultaten.</p>
    </x-slot>

    @include('dev._nav')

    <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
        {{-- Kerncijfers --}}
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            @foreach ([
                'Opnames' => $counts['intakes'],
                'AI-runs' => $counts['ai_runs'],
                'Externe feiten' => $counts['external_facts'],
                'Activity-events' => $counts['events'],
            ] as $label => $value)
                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <div class="text-2xl font-semibold text-gray-900">{{ $value }}</div>
                    <div class="text-sm text-gray-500">{{ $label }}</div>
                </div>
            @endforeach
        </div>

        {{-- API/dienst-status --}}
        <section class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-4 py-3">
                <h3 class="font-semibold text-gray-800">Externe diensten</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-2">Dienst</th>
                            <th class="px-4 py-2">Status</th>
                            <th class="px-4 py-2">Laatst</th>
                            <th class="px-4 py-2">Config</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($services as $service)
                            @php
                                if (! $service['enabled']) {
                                    [$tone, $text] = ['gray', 'uit'];
                                } elseif (($service['requires_key'] ?? false) && ($service['configured'] ?? null) === false) {
                                    [$tone, $text] = ['amber', 'geen key'];
                                } elseif (($service['last_status'] ?? null) === 'failed') {
                                    [$tone, $text] = ['red', 'laatste mislukt'];
                                } elseif (! empty($service['last_at'])) {
                                    [$tone, $text] = ['green', 'ok'];
                                } else {
                                    [$tone, $text] = ['gray', 'geen data'];
                                }
                                $toneClasses = [
                                    'green' => 'bg-green-100 text-green-800',
                                    'red' => 'bg-red-100 text-red-800',
                                    'amber' => 'bg-amber-100 text-amber-800',
                                    'gray' => 'bg-gray-100 text-gray-600',
                                ][$tone];
                            @endphp
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900">{{ $service['label'] }}</div>
                                    @if (! empty($service['detail']))
                                        <div class="text-xs text-gray-500">{{ $service['detail'] }}</div>
                                    @endif
                                    @if (! empty($service['last_error']))
                                        <div class="mt-1 text-xs text-red-600">{{ \Illuminate\Support\Str::limit($service['last_error'], 160) }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $toneClasses }}">{{ $text }}</span>
                                    @if (($service['failures'] ?? 0) > 0)
                                        <span class="ml-1 text-xs text-red-600">({{ $service['failures'] }} mislukt)</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-600">
                                    @if (! empty($service['last_at']))
                                        <span title="{{ $service['last_at'] }}">{{ $service['last_at']->diffForHumans() }}</span>
                                        @if (! empty($service['fact_count']))
                                            <span class="text-xs text-gray-400">· {{ $service['fact_count'] }} feiten</span>
                                        @endif
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500">
                                    @if (! empty($service['base_url']))
                                        <div class="break-all">{{ $service['base_url'] }}</div>
                                    @endif
                                    @if (! empty($service['timeout']))
                                        <div>timeout: {{ $service['timeout'] }}s</div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Recente AI-runs --}}
            <section class="rounded-lg border border-gray-200 bg-white">
                <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                    <h3 class="font-semibold text-gray-800">Recente AI-runs</h3>
                    <a href="{{ route('dev.ai-runs') }}" class="text-sm text-indigo-600 hover:underline">Alles</a>
                </div>
                <ul class="divide-y divide-gray-100 text-sm">
                    @forelse ($recentAiRuns as $run)
                        <li class="flex items-center justify-between px-4 py-2">
                            <div>
                                <span class="font-medium text-gray-900">{{ $run->type->value }}</span>
                                <span class="text-gray-400">· {{ $run->provider }}</span>
                                @if ($run->intake)
                                    <a href="{{ route('dev.intakes.show', $run->intake) }}" class="text-indigo-600 hover:underline">#{{ $run->intake_id }}</a>
                                @endif
                            </div>
                            <span @class([
                                'rounded-full px-2 py-0.5 text-xs font-medium',
                                'bg-green-100 text-green-800' => $run->status->value === 'succeeded',
                                'bg-red-100 text-red-800' => $run->status->value === 'failed',
                                'bg-gray-100 text-gray-600' => $run->status->value === 'pending',
                            ])>{{ $run->status->value }}</span>
                        </li>
                    @empty
                        <li class="px-4 py-3 text-gray-400">Nog geen AI-runs.</li>
                    @endforelse
                </ul>
            </section>

            {{-- Recente activiteit --}}
            <section class="rounded-lg border border-gray-200 bg-white">
                <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                    <h3 class="font-semibold text-gray-800">Recente activiteit</h3>
                    <a href="{{ route('dev.activity') }}" class="text-sm text-indigo-600 hover:underline">Alles</a>
                </div>
                <ul class="divide-y divide-gray-100 text-sm">
                    @forelse ($recentActivity as $event)
                        <li class="flex items-center justify-between px-4 py-2">
                            <div>
                                <span class="font-medium text-gray-900">{{ $event->event }}</span>
                                <span class="text-gray-400">· {{ $event->actor_type }}</span>
                                @if ($event->intake)
                                    <a href="{{ route('dev.intakes.show', $event->intake) }}" class="text-indigo-600 hover:underline">#{{ $event->intake_id }}</a>
                                @endif
                            </div>
                            <span class="text-xs text-gray-400" title="{{ $event->created_at }}">{{ $event->created_at?->diffForHumans() }}</span>
                        </li>
                    @empty
                        <li class="px-4 py-3 text-gray-400">Nog geen activiteit.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
</x-app-layout>
