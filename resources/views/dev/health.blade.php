<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Dev-admin — systeem/health</h2>
        <p class="mt-1 text-sm text-gray-500">Uitgebreide health-weergave (superset van <code>/health</code>).</p>
    </x-slot>

    @include('dev._nav')

    <div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        @foreach ($health as $section => $data)
            <section class="rounded-lg border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-4 py-3">
                    <h3 class="font-semibold capitalize text-gray-800">{{ str_replace('_', ' ', $section) }}</h3>
                </div>
                <dl class="divide-y divide-gray-100 text-sm">
                    @foreach ($data as $key => $value)
                        <div class="flex items-start justify-between gap-4 px-4 py-2">
                            <dt class="text-gray-500">{{ str_replace('_', ' ', (string) $key) }}</dt>
                            <dd class="text-right font-medium text-gray-900">
                                @if (is_bool($value))
                                    <span @class([
                                        'rounded-full px-2 py-0.5 text-xs',
                                        'bg-green-100 text-green-800' => $value,
                                        'bg-red-100 text-red-800' => ! $value,
                                    ])>{{ $value ? 'ja' : 'nee' }}</span>
                                @elseif (is_array($value))
                                    <pre class="whitespace-pre-wrap break-all text-left text-xs text-gray-700">{{ json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                @elseif (is_null($value))
                                    <span class="text-gray-400">—</span>
                                @else
                                    {{ $value }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endforeach
    </div>
</x-app-layout>
