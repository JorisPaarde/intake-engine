<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Dev-admin — activiteit</h2>
        <p class="mt-1 text-sm text-gray-500">Activity-events over alle opnames. Properties bevatten bewust alleen keys/codes, geen antwoordwaarden (ADR-0002).</p>
    </x-slot>

    @include('dev._nav')

    <div class="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
        {{-- Filters --}}
        <form method="GET" class="flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4 text-sm">
            <label class="flex flex-col gap-1">
                <span class="text-xs text-gray-500">Event</span>
                <select name="event" class="rounded border-gray-300 text-sm">
                    <option value="">Alle</option>
                    @foreach ($eventNames as $name)
                        <option value="{{ $name }}" @selected(($filters['event'] ?? '') === $name)>{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-gray-500">Actor</span>
                <select name="actor_type" class="rounded border-gray-300 text-sm">
                    <option value="">Alle</option>
                    @foreach ($actorTypes as $type)
                        <option value="{{ $type }}" @selected(($filters['actor_type'] ?? '') === $type)>{{ $type }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" class="rounded bg-gray-800 px-3 py-2 text-white">Filter</button>
            <a href="{{ route('dev.activity') }}" class="px-2 py-2 text-gray-500 hover:underline">Reset</a>
        </form>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-2">Wanneer</th>
                        <th class="px-4 py-2">Event</th>
                        <th class="px-4 py-2">Actor</th>
                        <th class="px-4 py-2">Opname</th>
                        <th class="px-4 py-2">Properties</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($events as $event)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-2 text-gray-500" title="{{ $event->created_at }}">{{ $event->created_at?->diffForHumans() }}</td>
                            <td class="px-4 py-2 font-medium text-gray-900">{{ $event->event }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ $event->actor_type }}{{ $event->actor_id ? ' #'.$event->actor_id : '' }}</td>
                            <td class="px-4 py-2">
                                @if ($event->intake)
                                    <a href="{{ route('dev.intakes.show', $event->intake) }}" class="text-indigo-600 hover:underline">#{{ $event->intake_id }}</a>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-xs text-gray-500">
                                @if (! empty($event->properties))
                                    <code class="break-all">{{ json_encode($event->properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">Geen activiteit gevonden.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $events->links() }}
    </div>
</x-app-layout>
