@props([
    'citations' => [],
])

@if ($citations !== [])
    <ul {{ $attributes->merge(['class' => 'mt-1 space-y-0.5 text-xs text-gray-500', 'data-testid' => 'evidence-citations']) }}>
        @foreach ($citations as $citation)
            <li
                @class([
                    'flex flex-wrap items-baseline gap-x-2 gap-y-0.5',
                    'opacity-60' => ($citation['superseded'] ?? false) === true,
                ])
                data-testid="evidence-citation"
                @if (($citation['superseded'] ?? false) === true) data-superseded="1" @endif
            >
                @if (! empty($citation['url']))
                    <a
                        href="{{ $citation['url'] }}"
                        target="_blank"
                        rel="noopener"
                        class="font-medium text-indigo-700 underline decoration-indigo-200 underline-offset-2 hover:text-indigo-900"
                        data-testid="evidence-citation-link"
                    >{{ $citation['label'] }}</a>
                @else
                    <span class="font-medium text-gray-700" data-testid="evidence-citation-label">{{ $citation['label'] }}</span>
                @endif
                @if (($citation['superseded'] ?? false) === true && ! empty($citation['supersession_label']))
                    <span class="text-gray-500" data-testid="evidence-citation-superseded">{{ $citation['supersession_label'] }}</span>
                @endif
            </li>
        @endforeach
    </ul>
@endif
