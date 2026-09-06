<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Dev-admin — AI-invoer testen</h2>
        <p class="mt-1 text-sm text-gray-500">
            Dry-run van het veld <span class="font-medium">Beschrijf wat de klant wil</span> op Nieuwe opname.
            Zelfde lokale parser → catalogus-AI → normalisatie → validatie. Geen opname, antwoorden, AI-runs of mail.
        </p>
    </x-slot>

    @include('dev._nav')

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('dev.ai-input-test.evaluate') }}" class="space-y-4 rounded-lg border border-amber-200 bg-amber-50/60 p-4">
            @csrf
            <div>
                <label for="request_reason" class="block text-sm font-medium text-gray-800">Proeftekst</label>
                <p class="mt-1 text-xs text-gray-600">
                    Actieve template zoals bij Nieuwe opname (airco). Maximaal {{ $maxLength }} tekens.
                    De tekst wordt niet bewaard of gelogd.
                </p>
                <textarea
                    id="request_reason"
                    name="request_reason"
                    rows="4"
                    maxlength="{{ $maxLength }}"
                    required
                    class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500"
                    placeholder="{{ $example }}"
                >{{ $requestReason }}</textarea>
                <x-input-error :messages="$errors->get('request_reason')" class="mt-2" />
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="rounded bg-amber-800 px-4 py-2 text-sm font-medium text-white hover:bg-amber-900">
                    Evaluatie starten
                </button>
                <button
                    type="button"
                    class="text-sm text-amber-900 underline"
                    onclick="document.getElementById('request_reason').value = {{ json_encode($example) }}"
                >
                    Voorbeeldtekst invullen
                </button>
            </div>
        </form>

        @if (is_array($evaluation))
            @php
                $versions = $evaluation['versions'] ?? [];
                $candidates = $evaluation['candidates'] ?? [];
                $fills = array_values(array_filter($candidates, fn ($c) => ($c['disposition'] ?? '') === 'fill'));
                $suggestions = array_values(array_filter($candidates, fn ($c) => ($c['disposition'] ?? '') === 'suggestion'));
                $rejected = array_values(array_filter($candidates, fn ($c) => ($c['disposition'] ?? '') === 'rejected'));
                $composite = static function (array $item): string {
                    $instance = $item['section_instance_key'] ?? null;

                    return $instance ? $item['question_key'].'@'.$instance : $item['question_key'];
                };
            @endphp

            <section class="rounded-lg border border-gray-200 bg-white p-4">
                <h3 class="font-semibold text-gray-900">Versies en gates</h3>
                <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="text-xs text-gray-500">Template</dt><dd class="font-medium">{{ $versions['template_key'] ?? '—' }} v{{ $versions['template_version'] ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Lokale parser</dt><dd class="font-medium">{{ $versions['parser_version'] ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Prompt</dt><dd class="font-medium">{{ $versions['prompt_version'] ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Provider</dt><dd class="font-medium">{{ $versions['provider'] ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Model</dt><dd class="font-medium">{{ $versions['model'] ?? '—' }}</dd></div>
                    <div>
                        <dt class="text-xs text-gray-500">Tekst-AI</dt>
                        <dd class="font-medium">{{ ! empty($evaluation['text_inference_enabled']) ? 'aan' : 'uit' }}</dd>
                    </div>
                </dl>

                @if (! empty($evaluation['text_inference_gate_reason']))
                    <p class="mt-3 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        {{ $evaluation['text_inference_gate_reason'] }}
                    </p>
                @endif

                @if (! empty($evaluation['ai_error']))
                    <p class="mt-3 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                        {{ $evaluation['ai_error'] }}
                        @if (! empty($evaluation['local_output']))
                            — lokale uitkomst blijft zichtbaar hieronder.
                        @endif
                    </p>
                @endif

                @if (! empty($evaluation['ai_evidence']))
                    <p class="mt-3 text-sm text-gray-600"><span class="font-medium text-gray-800">AI-evidence:</span> {{ $evaluation['ai_evidence'] }}</p>
                @endif
            </section>

            @if (! empty($evaluation['local_output']))
                <section class="rounded-lg border border-gray-200 bg-white p-4">
                    <h3 class="font-semibold text-gray-900">Lokale parser</h3>
                    <pre class="mt-2 overflow-x-auto rounded bg-gray-50 p-3 text-xs text-gray-700">{{ json_encode($evaluation['local_output'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </section>
            @endif

            <div class="grid gap-4 lg:grid-cols-3">
                <section class="rounded-lg border border-green-200 bg-white p-4">
                    <h3 class="font-semibold text-green-900">Zou invullen ({{ count($fills) }})</h3>
                    <ul class="mt-3 space-y-2 text-sm">
                        @forelse ($fills as $item)
                            <li class="rounded border border-green-100 bg-green-50 px-3 py-2">
                                <div class="font-medium text-gray-900">{{ $item['label'] }}</div>
                                <div class="text-xs text-gray-500">{{ $composite($item) }} · {{ $item['source'] }} · {{ $item['confidence'] }}</div>
                                <pre class="mt-1 text-xs text-gray-700">{{ json_encode($item['value'], JSON_UNESCAPED_UNICODE) }}</pre>
                                @if (! empty($item['evidence']))
                                    <div class="mt-1 text-xs text-gray-500">{{ $item['evidence'] }}</div>
                                @endif
                            </li>
                        @empty
                            <li class="text-gray-400">Geen automatische invullingen.</li>
                        @endforelse
                    </ul>
                </section>

                <section class="rounded-lg border border-blue-200 bg-white p-4">
                    <h3 class="font-semibold text-blue-900">Voorzet ({{ count($suggestions) }})</h3>
                    <ul class="mt-3 space-y-2 text-sm">
                        @forelse ($suggestions as $item)
                            <li class="rounded border border-blue-100 bg-blue-50 px-3 py-2">
                                <div class="font-medium text-gray-900">{{ $item['label'] }}</div>
                                <div class="text-xs text-gray-500">{{ $composite($item) }} · {{ $item['source'] }} · {{ $item['confidence'] }}</div>
                                <pre class="mt-1 text-xs text-gray-700">{{ json_encode($item['value'], JSON_UNESCAPED_UNICODE) }}</pre>
                                @if (! empty($item['reason']))
                                    <div class="mt-1 text-xs text-blue-800">{{ $item['reason'] }}</div>
                                @endif
                            </li>
                        @empty
                            <li class="text-gray-400">Geen voorzetten.</li>
                        @endforelse
                    </ul>
                </section>

                <section class="rounded-lg border border-red-200 bg-white p-4">
                    <h3 class="font-semibold text-red-900">Afgewezen ({{ count($rejected) }})</h3>
                    <ul class="mt-3 space-y-2 text-sm">
                        @forelse ($rejected as $item)
                            <li class="rounded border border-red-100 bg-red-50 px-3 py-2">
                                <div class="font-medium text-gray-900">{{ $item['label'] }}</div>
                                <div class="text-xs text-gray-500">{{ $composite($item) }} · {{ $item['source'] }}</div>
                                @if (! empty($item['reason']))
                                    <div class="mt-1 text-xs text-red-800">{{ $item['reason'] }}</div>
                                @endif
                                @if (! empty($item['value']))
                                    <pre class="mt-1 text-xs text-gray-600">{{ json_encode($item['value'], JSON_UNESCAPED_UNICODE) }}</pre>
                                @endif
                            </li>
                        @empty
                            <li class="text-gray-400">Geen afwijzingen.</li>
                        @endforelse
                    </ul>
                </section>
            </div>

            <section class="rounded-lg border border-gray-200 bg-white p-4">
                <h3 class="font-semibold text-gray-900">Hypothetisch formulierbeeld</h3>
                <p class="mt-1 text-sm text-gray-500">Waarden die bij voldoende zekerheid automatisch of als voorzet zouden landen.</p>
                @if (empty($evaluation['hypothetical_answers']))
                    <p class="mt-3 text-sm text-gray-400">Nog geen hypothetische antwoorden.</p>
                @else
                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100 text-sm">
                            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-3 py-2">Vraag</th>
                                    <th class="px-3 py-2">Waarde</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($evaluation['hypothetical_answers'] as $key => $value)
                                    <tr>
                                        <td class="px-3 py-2 font-medium text-gray-800">{{ $key }}</td>
                                        <td class="px-3 py-2 text-gray-700"><code class="text-xs">{{ json_encode($value, JSON_UNESCAPED_UNICODE) }}</code></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-4">
                <h3 class="font-semibold text-gray-900">Zichtbaar / open ({{ count($evaluation['open_questions'] ?? []) }})</h3>
                <p class="mt-1 text-sm text-gray-500">Vragen die na deze prefill nog in de flow zichtbaar zouden blijven.</p>
                <ul class="mt-3 columns-1 gap-2 text-sm sm:columns-2 lg:columns-3">
                    @forelse (($evaluation['open_questions'] ?? []) as $question)
                        <li class="mb-1 break-inside-avoid text-gray-700">
                            {{ $question['label'] }}
                            <span class="text-xs text-gray-400">
                                ({{ $question['question_key'] }}{{ ! empty($question['section_instance_key']) ? '@'.$question['section_instance_key'] : '' }})
                            </span>
                        </li>
                    @empty
                        <li class="text-gray-400">Geen open vragen in de zichtbare set.</li>
                    @endforelse
                </ul>
            </section>
        @endif
    </div>
</x-app-layout>
