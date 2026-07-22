<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Dev-admin — AI-runs</h2>
        <p class="mt-1 text-sm text-gray-500">Alle AI-calls: provider, model, status, timing en ruwe output/fout.</p>
    </x-slot>

    @include('dev._nav')

    <div class="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
        {{-- Filters --}}
        <form method="GET" class="flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4 text-sm">
            <label class="flex flex-col gap-1">
                <span class="text-xs text-gray-500">Type</span>
                <select name="type" class="rounded border-gray-300 text-sm">
                    <option value="">Alle</option>
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}" @selected(($filters['type'] ?? '') === $type->value)>{{ $type->value }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-gray-500">Status</span>
                <select name="status" class="rounded border-gray-300 text-sm">
                    <option value="">Alle</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->value }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-gray-500">Provider</span>
                <select name="provider" class="rounded border-gray-300 text-sm">
                    <option value="">Alle</option>
                    @foreach ($providers as $provider)
                        <option value="{{ $provider }}" @selected(($filters['provider'] ?? '') === $provider)>{{ $provider }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" class="rounded bg-gray-800 px-3 py-2 text-white">Filter</button>
            <a href="{{ route('dev.ai-runs') }}" class="px-2 py-2 text-gray-500 hover:underline">Reset</a>
        </form>

        <div class="space-y-3">
            @forelse ($runs as $run)
                <details class="rounded-lg border border-gray-200 bg-white">
                    <summary class="flex cursor-pointer flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-sm">
                        <span class="font-medium text-gray-900">{{ $run->type->value }}</span>
                        <span @class([
                            'rounded-full px-2 py-0.5 text-xs font-medium',
                            'bg-green-100 text-green-800' => $run->status->value === 'succeeded',
                            'bg-red-100 text-red-800' => $run->status->value === 'failed',
                            'bg-gray-100 text-gray-600' => $run->status->value === 'pending',
                        ])>{{ $run->status->value }}</span>
                        <span class="text-gray-500">{{ $run->provider }}{{ $run->model ? ' · '.$run->model : '' }}</span>
                        @if ($run->intake)
                            <a href="{{ route('dev.intakes.show', $run->intake) }}" class="text-indigo-600 hover:underline">opname #{{ $run->intake_id }}</a>
                        @endif
                        <span class="ml-auto text-xs text-gray-400" title="{{ $run->started_at }}">{{ $run->started_at?->diffForHumans() }}</span>
                    </summary>
                    <div class="space-y-2 border-t border-gray-100 px-4 py-3 text-xs text-gray-600">
                        <div>prompt-versie: {{ $run->prompt_version ?? '—' }} · input-hash: <code>{{ \Illuminate\Support\Str::limit((string) $run->input_hash, 16, '…') }}</code></div>
                        <div>gestart: {{ $run->started_at }} · klaar: {{ $run->finished_at ?? '—' }}</div>
                        @if ($run->error_message)
                            <div class="rounded bg-red-50 p-2 text-red-700">{{ $run->error_message }}</div>
                        @endif
                        @if ($run->output)
                            <pre class="overflow-x-auto rounded bg-gray-50 p-2">{{ json_encode($run->output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        @endif
                    </div>
                </details>
            @empty
                <p class="rounded-lg border border-gray-200 bg-white px-4 py-6 text-center text-gray-400">Geen AI-runs gevonden.</p>
            @endforelse
        </div>

        {{ $runs->links() }}
    </div>
</x-app-layout>
