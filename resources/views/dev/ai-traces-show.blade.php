<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">AI-trace {{ $trace->trace_id }}</h2>
        <p class="mt-1 text-sm text-gray-500">
            <a href="{{ route('dev.ai-traces') }}" class="text-indigo-600 hover:underline">← Terug naar lijst</a>
            @if ($trace->intake)
                · <a href="{{ route('dev.intakes.show', $trace->intake) }}" class="text-indigo-600 hover:underline">opname #{{ $trace->intake_id }}</a>
            @endif
        </p>
    </x-slot>

    @include('dev._nav')

    <div class="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8 text-sm">
        <div class="rounded-lg border border-gray-200 bg-white p-4 space-y-2">
            <div class="flex flex-wrap gap-3">
                <span class="font-medium">{{ $trace->call_type->value }}</span>
                <span @class([
                    'rounded px-2 py-0.5 text-xs font-medium',
                    'bg-green-100 text-green-800' => $trace->status->value === 'succeeded',
                    'bg-red-100 text-red-800' => $trace->status->value === 'failed',
                    'bg-gray-100 text-gray-600' => $trace->status->value === 'pending',
                ])>{{ $trace->status->value }}</span>
                <span>{{ $trace->provider }} · {{ $trace->model ?? '—' }}</span>
            </div>
            <div>prompt: {{ $trace->prompt_version ?? '—' }} · correlation: {{ $trace->correlation_id ?? '—' }}</div>
            <div>subject: {{ $trace->subject_type ?? '—' }} {{ $trace->subject_id ?? '' }} · upload #{{ $trace->upload_id ?? '—' }} · ai_run #{{ $trace->ai_run_id ?? '—' }}</div>
            <div>fallback={{ $trace->fallback_used ? 'ja' : 'nee' }} · retries={{ $trace->retry_count }} · finish={{ $trace->finish_reason ?? '—' }}</div>
            <div>
                timings ms — persist: {{ $trace->persist_ms ?? '—' }},
                network: {{ $trace->network_upload_ms ?? '—' }},
                preprocess: {{ $trace->preprocess_ms ?? '—' }},
                provider: {{ $trace->provider_ms ?? '—' }},
                process: {{ $trace->process_ms ?? '—' }}
            </div>
            <div>tokens: in {{ $trace->input_tokens ?? '—' }} · out {{ $trace->output_tokens ?? '—' }} · total {{ $trace->total_tokens ?? '—' }} · kosten {{ $trace->estimated_cost_cents !== null ? $trace->estimated_cost_cents.' cent' : '—' }}</div>
            @if ($trace->error_message)
                <div class="rounded bg-red-50 p-2 text-red-700">{{ $trace->error_message }}</div>
            @endif
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <h3 class="font-medium text-gray-900">Stappen</h3>
            <ol class="mt-2 space-y-2 text-xs">
                @forelse ($trace->steps as $step)
                    <li class="rounded bg-gray-50 p-2">
                        <div class="font-medium">#{{ $step->sequence }} {{ $step->step_key }} @if($step->duration_ms !== null)<span class="text-gray-400">({{ $step->duration_ms }} ms)</span>@endif</div>
                        @if ($step->payload)
                            <pre class="mt-1 overflow-x-auto">{{ json_encode($step->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        @endif
                    </li>
                @empty
                    <li class="text-gray-400">Geen stappen</li>
                @endforelse
            </ol>
        </div>

        @foreach ([
            'model_parameters' => 'Modelparameters',
            'request_snapshot' => 'Request (system/user/context)',
            'photo_refs' => 'Foto-referenties',
            'parsed_response' => 'Geparste response',
            'validation_errors' => 'Validatiefouten',
            'normalizations' => 'Normalisatie/defaults',
            'field_outcomes' => 'Overgenomen / afgewezen velden',
            'dossier_before' => 'Dossier vóór',
            'dossier_after' => 'Dossier ná',
            'remaining_questions_before' => 'Restvragen vóór',
            'remaining_questions_after' => 'Restvragen ná',
        ] as $field => $label)
            @if ($trace->{$field})
                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <h3 class="font-medium text-gray-900">{{ $label }}</h3>
                    <pre class="mt-2 overflow-x-auto rounded bg-gray-50 p-2 text-xs">{{ json_encode($trace->{$field}, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
            @endif
        @endforeach

        @if ($trace->raw_response)
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <h3 class="font-medium text-gray-900">Ruwe AI-response</h3>
                <pre class="mt-2 overflow-x-auto rounded bg-gray-50 p-2 text-xs">{{ $trace->raw_response }}</pre>
            </div>
        @endif
    </div>
</x-app-layout>
