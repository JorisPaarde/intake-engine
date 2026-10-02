<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Dev-admin — AI-traces</h2>
        <p class="mt-1 text-sm text-gray-500">Volledige keten per AI-call: request → response → parse → dossier/restvragen. Geen API-keys of base64.</p>
    </x-slot>

    @include('dev._nav')

    <div class="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
        <form method="GET" class="flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4 text-sm">
            <label class="flex flex-col gap-1">
                <span class="text-xs text-gray-500">Call-type</span>
                <select name="call_type" class="rounded border-gray-300 text-sm">
                    <option value="">Alle</option>
                    @foreach ($callTypes as $type)
                        <option value="{{ $type->value }}" @selected(($filters['call_type'] ?? '') === $type->value)>{{ $type->value }}</option>
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
                <span class="text-xs text-gray-500">Intake-ID</span>
                <input type="number" name="intake_id" value="{{ $filters['intake_id'] ?? '' }}" class="rounded border-gray-300 text-sm" placeholder="bijv. 80">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-gray-500">Trace-ID</span>
                <input type="text" name="trace_id" value="{{ $filters['trace_id'] ?? '' }}" class="rounded border-gray-300 text-sm" placeholder="uuid">
            </label>
            <button type="submit" class="rounded bg-marketing-green-dark px-3 py-2 text-white">Filter</button>
            <a href="{{ route('dev.ai-traces') }}" class="px-2 py-2 text-gray-500 hover:underline">Reset</a>
        </form>

        <div class="space-y-3">
            @forelse ($traces as $trace)
                <a href="{{ route('dev.ai-traces.show', $trace) }}" class="block rounded-lg border border-gray-200 bg-white px-4 py-3 hover:border-marketing-green">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                        <span class="font-medium text-gray-900">{{ $trace->call_type->value }}</span>
                        <span @class([
                            'rounded px-2 py-0.5 text-xs font-medium',
                            'bg-green-100 text-green-800' => $trace->status->value === 'succeeded',
                            'bg-red-100 text-red-800' => $trace->status->value === 'failed',
                            'bg-gray-100 text-gray-600' => $trace->status->value === 'pending',
                        ])>{{ $trace->status->value }}</span>
                        <span class="text-gray-500">{{ $trace->provider }}{{ $trace->model ? ' · '.$trace->model : '' }}</span>
                        <span class="font-mono text-xs text-gray-400">{{ \Illuminate\Support\Str::limit($trace->trace_id, 13, '…') }}</span>
                        @if ($trace->intake)
                            <span class="text-indigo-600">opname #{{ $trace->intake_id }}</span>
                        @endif
                        <span class="ml-auto text-xs text-gray-400">
                            up {{ $trace->upload_ms ?? '—' }} · prep {{ $trace->preprocess_ms ?? '—' }} · prov {{ $trace->provider_ms ?? '—' }} · proc {{ $trace->process_ms ?? '—' }} ms
                        </span>
                    </div>
                    <div class="mt-1 text-xs text-gray-500">
                        stappen: {{ $trace->steps->pluck('step_key')->implode(' → ') ?: '—' }}
                    </div>
                </a>
            @empty
                <p class="rounded-lg border border-gray-200 bg-white px-4 py-6 text-center text-gray-400">Geen AI-traces gevonden.</p>
            @endforelse
        </div>

        <div>{{ $traces->links() }}</div>
    </div>
</x-app-layout>
