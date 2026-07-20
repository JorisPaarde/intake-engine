<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Resultaten</h2>
                <p class="mt-1 text-sm text-gray-500">{{ $metrics['summary']['created_count'] }} opnames in deze periode</p>
            </div>

            <nav class="inline-flex w-fit overflow-hidden rounded-md border border-gray-300 bg-white" aria-label="Periode">
                @foreach (['30' => '30 dagen', '90' => '90 dagen', 'all' => 'Alles'] as $value => $label)
                    <a
                        href="{{ route('metrics', ['period' => $value]) }}"
                        @class([
                            'px-3 py-2 text-sm font-medium transition',
                            'bg-gray-800 text-white' => $period === (string) $value,
                            'text-gray-600 hover:bg-gray-50 hover:text-gray-900' => $period !== (string) $value,
                            'border-l border-gray-300' => ! $loop->first,
                        ])
                        @if ($period === (string) $value) aria-current="page" @endif
                    >{{ $label }}</a>
                @endforeach
            </nav>
        </div>
    </x-slot>

    @php
        $duration = static function (?int $seconds): string {
            if ($seconds === null) {
                return '—';
            }

            if ($seconds < 60) {
                return $seconds.' sec';
            }

            if ($seconds < 3600) {
                return (string) round($seconds / 60).' min';
            }

            if ($seconds < 86400) {
                $hours = intdiv($seconds, 3600);
                $minutes = intdiv($seconds % 3600, 60);

                return $minutes > 0 ? $hours.' u '.$minutes.' min' : $hours.' u';
            }

            $days = intdiv($seconds, 86400);
            $hours = intdiv($seconds % 86400, 3600);

            return $hours > 0 ? $days.' d '.$hours.' u' : $days.' d';
        };

        $percentage = static fn (?float $value): string => $value === null
            ? '—'
            : number_format($value, 1, ',', '.').'%';
    @endphp

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-8 px-4 sm:px-6 lg:px-8">
            <section aria-labelledby="summary-heading">
                <h3 id="summary-heading" class="sr-only">Samenvatting</h3>
                <div class="grid gap-px overflow-hidden rounded-lg border border-gray-200 bg-gray-200 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="bg-white p-5">
                        <p class="text-sm font-medium text-gray-500">Afgerond</p>
                        <p class="mt-2 text-2xl font-semibold text-gray-900">{{ $percentage($metrics['summary']['completion_percent']) }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ $metrics['summary']['completed_count'] }} van {{ $metrics['summary']['started_count'] }} gestart</p>
                    </div>
                    <div class="bg-white p-5">
                        <p class="text-sm font-medium text-gray-500">Mediane invultijd</p>
                        <p class="mt-2 text-2xl font-semibold text-gray-900">{{ $duration($metrics['summary']['median_customer_duration_seconds']) }}</p>
                        <p class="mt-1 text-xs text-gray-500">Gestart tot eerste afronding</p>
                    </div>
                    <div class="bg-white p-5">
                        <p class="text-sm font-medium text-gray-500">Mediane klantacties</p>
                        <p class="mt-2 text-2xl font-semibold text-gray-900">{{ $metrics['summary']['median_customer_actions'] ?? '—' }}</p>
                        <p class="mt-1 text-xs text-gray-500">Opslaan, uploaden en afronden</p>
                    </div>
                    <div class="bg-white p-5">
                        <p class="text-sm font-medium text-gray-500">Aanvullende rondes</p>
                        <p class="mt-2 text-2xl font-semibold text-gray-900">{{ $metrics['summary']['follow_up_rounds'] }}</p>
                        <p class="mt-1 text-xs text-gray-500">Gemiddeld {{ number_format($metrics['summary']['average_follow_up_rounds'] ?? 0, 1, ',', '.') }} per gestarte opname</p>
                    </div>
                    <div class="bg-white p-5">
                        <p class="text-sm font-medium text-gray-500">Direct genoeg informatie</p>
                        <p class="mt-2 text-2xl font-semibold text-gray-900">{{ $percentage($metrics['summary']['enough_information_percent']) }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ $metrics['summary']['enough_information_count'] }} van {{ $metrics['summary']['reviewed_count'] }} beoordeeld</p>
                    </div>
                    <div class="bg-white p-5">
                        <p class="text-sm font-medium text-gray-500">Mediane tijd tot besluit</p>
                        <p class="mt-2 text-2xl font-semibold text-gray-900">{{ $duration($metrics['summary']['median_decision_seconds']) }}</p>
                        <p class="mt-1 text-xs text-gray-500">Aanmaak tot eerste beoordeling</p>
                    </div>
                </div>
            </section>

            <section aria-labelledby="dropoff-heading">
                <div class="mb-3 flex items-end justify-between gap-4">
                    <div>
                        <h3 id="dropoff-heading" class="text-base font-semibold text-gray-900">Uitvalpunten</h3>
                        <p class="mt-1 text-sm text-gray-500">Gestarte opnames die nog niet zijn afgerond</p>
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    @forelse ($metrics['dropoffs'] as $dropoff)
                        <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-4 py-3 last:border-b-0">
                            <span class="text-sm text-gray-700">{{ $dropoff['label'] }}</span>
                            <span class="text-sm font-semibold tabular-nums text-gray-900">{{ $dropoff['count'] }}</span>
                        </div>
                    @empty
                        <p class="px-4 py-6 text-sm text-gray-500">Geen uitval in deze periode.</p>
                    @endforelse
                </div>
            </section>

            <section aria-labelledby="intake-metrics-heading">
                <div class="mb-3">
                    <h3 id="intake-metrics-heading" class="text-base font-semibold text-gray-900">Per opname</h3>
                </div>

                <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Opname</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Voortgang</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Invultijd</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Acties</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Rondes</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Genoeg info</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-600">Besluit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($metrics['intakes'] as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <a href="{{ route('intakes.show', $row['id']) }}" class="font-medium text-indigo-600 hover:text-indigo-800">
                                            {{ $row['reference'] }}
                                        </a>
                                        <span class="ml-2 text-xs text-gray-500">{{ $row['status_label'] }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row['progress_percent'] }}%</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $duration($row['customer_duration_seconds']) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row['customer_actions'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $row['follow_up_rounds'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">
                                        {{ $row['enough_information'] === null ? '—' : ($row['enough_information'] ? 'Ja' : 'Nee') }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $duration($row['decision_seconds']) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-10 text-center text-gray-500">Geen opnames in deze periode.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
