<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Dev-admin — opname-inspector</h2>
        <p class="mt-1 text-sm text-gray-500">Kies een opname om alle ruwe binnengekomen data te bekijken.</p>
    </x-slot>

    @include('dev._nav')

    <div class="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
        <form method="GET" class="flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4 text-sm">
            <label class="flex flex-1 flex-col gap-1">
                <span class="text-xs text-gray-500">Zoek (uuid, naam, e-mail, adres)</span>
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="rounded border-gray-300 text-sm" placeholder="bijv. Damrak of demo@…">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-gray-500">Status</span>
                <select name="status" class="rounded border-gray-300 text-sm">
                    <option value="">Alle</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" class="rounded bg-gray-800 px-3 py-2 text-white">Zoek</button>
            <a href="{{ route('dev.intakes') }}" class="px-2 py-2 text-gray-500 hover:underline">Reset</a>
        </form>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-2">#</th>
                        <th class="px-4 py-2">Adres / klant</th>
                        <th class="px-4 py-2">Status</th>
                        <th class="px-4 py-2">Data</th>
                        <th class="px-4 py-2">Aangemaakt</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($intakes as $intake)
                        <tr class="@if ($intake->trashed()) bg-red-50/50 @endif">
                            <td class="px-4 py-2 align-top">
                                <a href="{{ route('dev.intakes.show', $intake) }}" class="font-medium text-indigo-600 hover:underline">#{{ $intake->id }}</a>
                                @if ($intake->is_demo)<span class="ml-1 rounded bg-indigo-100 px-1 text-xs text-indigo-700">demo</span>@endif
                                @if ($intake->trashed())<span class="ml-1 rounded bg-red-100 px-1 text-xs text-red-700">verwijderd</span>@endif
                            </td>
                            <td class="px-4 py-2 align-top">
                                <div class="text-gray-900">{{ $intake->address_line ?: '—' }}</div>
                                <div class="text-xs text-gray-500">{{ trim(($intake->address_postal_code ?? '').' '.($intake->address_city ?? '')) ?: $intake->customer_name }}</div>
                            </td>
                            <td class="px-4 py-2 align-top">{{ $intake->status->label() }}</td>
                            <td class="px-4 py-2 align-top text-xs text-gray-500">
                                {{ $intake->answers_count }} antw · {{ $intake->uploads_count }} foto's · {{ $intake->external_facts_count }} feiten · {{ $intake->ai_runs_count }} AI · {{ $intake->activity_events_count }} events
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 align-top text-gray-500" title="{{ $intake->created_at }}">{{ $intake->created_at?->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">Geen opnames gevonden.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $intakes->links() }}
    </div>
</x-app-layout>
