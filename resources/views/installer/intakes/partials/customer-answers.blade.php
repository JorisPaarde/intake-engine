{{--
  Gedeeld blok «Antwoorden van de klant» voor overzicht (show) en werkplek.
  Verwacht: $intake (Intake), optioneel $wrapperClass voor de buitenste container.
--}}
@php
    $customerAnswerBlocks = \App\Domains\Intake\Support\CustomerAnswerBlocks::forIntake($intake);
    $wrapperClass = $wrapperClass ?? 'border-t border-indigo-100 pt-4';
@endphp
@if ($customerAnswerBlocks !== [])
    <div class="{{ $wrapperClass }}" data-testid="customer-answers-block">
        <h4 class="text-sm font-semibold text-gray-900">Antwoorden van de klant</h4>
        <div class="mt-3 space-y-4">
            @foreach ($customerAnswerBlocks as $block)
                @php
                    $blockHeading = (string) ($block['heading'] ?? '');
                    // Interne sectietitels (“Ruimtes 2”) → neutrale kop.
                    if (preg_match('/^Ruimtes\s+\d+$/u', $blockHeading) === 1) {
                        $blockHeading = 'Ruimte';
                    }
                @endphp
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $blockHeading }}</p>
                    <dl class="mt-2 space-y-2 text-sm">
                        @foreach ($block['items'] as $item)
                            @php
                                $itemValue = (string) ($item['value'] ?? '');
                                $itemValue = preg_replace('/\bm2\b/u', 'm²', $itemValue) ?? $itemValue;
                            @endphp
                            <div>
                                <dt class="text-gray-500">{{ $item['label'] }}</dt>
                                <dd class="text-gray-900">{{ $itemValue }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endforeach
        </div>
    </div>
@endif
