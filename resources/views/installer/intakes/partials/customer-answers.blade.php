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
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $block['heading'] }}</p>
                    <dl class="mt-2 space-y-2 text-sm">
                        @foreach ($block['items'] as $item)
                            <div>
                                <dt class="text-gray-500">{{ $item['label'] }}</dt>
                                <dd class="text-gray-900">{{ $item['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endforeach
        </div>
    </div>
@endif
