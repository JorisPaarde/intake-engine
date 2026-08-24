@props([
    'variant' => 'banner',
    'demoAiSummary' => null,
    'demoAttentionPoints' => [],
    'shortCustomer' => false,
    'installerReturnUrl' => null,
])

@php
    $included = $shortCustomer
        ? [
            'Korte demoroute met dezelfde klantvragen als in de app',
            'Antwoorden en foto-AI komen in dezelfde tijdelijke opname',
            'Adresinvulling en AI-tekst zoals in productie',
        ]
        : [
            'Dezelfde afgeschermde klanttaak en uploadflow als in de app',
            'De aanvulling wordt direct aan dezelfde opname gekoppeld',
            'Foto-AI mag meedraaien wanneer die in de omgeving aan staat',
        ];
    $hidden = [
        'E-mail en herinneringen naar een echte klant',
        'Automatische PDF-export (wel op aanvraag met e-mail in de opname)',
        ...($shortCustomer ? ['De volledige productielijst (langer en past zich aan)'] : []),
    ];
@endphp

@if ($variant === 'banner')
    <aside {{ $attributes->merge(['class' => 'mb-4 rounded-md border border-brand-ember/40 bg-brand-ember/10 px-4 py-3 text-left text-sm text-brand-ink', 'role' => 'status']) }}>
        <p class="font-semibold text-brand-ember">
            {{ $shortCustomer ? 'Demo — korte klantroute' : 'Demo — aanvulling door de klant' }}
        </p>
        <p class="mt-1 leading-relaxed text-brand-ink/75">
            @if ($shortCustomer)
                U bekijkt wat de klant ziet na het versturen van de link. Deze demoroute is kort gehouden. Er is geen echte klant.
            @else
                U bekijkt één opdracht vanuit de tijdelijke opname. Er is geen echte klant. De gegevens verdwijnen automatisch.
            @endif
        </p>
        <p class="mt-2 font-medium text-brand-ink/85">Wel aan in deze demo:</p>
        <ul class="mt-1.5 list-disc space-y-0.5 pl-5 text-brand-ink/70">
            @foreach ($included as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
        <p class="mt-2 font-medium text-brand-ink/85">Bewust uitgeschakeld:</p>
        <ul class="mt-1.5 list-disc space-y-0.5 pl-5 text-brand-ink/70">
            @foreach ($hidden as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
    </aside>
@elseif ($variant === 'complete')
    <div {{ $attributes->merge(['class' => 'mt-5 border-t border-brand-fog/80 pt-5 text-sm text-brand-ink/80']) }}>
        <p class="font-semibold text-brand-ink">Wat u net heeft gedaan</p>
        <p class="mt-1 leading-relaxed">
            @if ($shortCustomer)
                De korte klantroute is afgerond. In productie zou de installateur nu een mail krijgen.
            @else
                Eén klantaanvulling is afgerond. De foto of het antwoord hoort bij dezelfde opname.
            @endif
        </p>

        @if (! empty($demoAiSummary) && is_array($demoAiSummary))
            <div class="mt-4 rounded-md border border-brand-sea/30 bg-brand-sea/5 px-4 py-3 text-brand-ink">
                <p class="font-semibold text-brand-sea">AI-voorstel (niet bindend)</p>
                <p class="mt-1 leading-relaxed">{{ $demoAiSummary['summary'] ?? '' }}</p>
                @if (! empty($demoAiSummary['highlights']) && is_array($demoAiSummary['highlights']))
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-brand-ink/75">
                        @foreach ($demoAiSummary['highlights'] as $highlight)
                            <li>{{ $highlight }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        @if (! empty($demoAttentionPoints))
            <div class="mt-3">
                <p class="font-medium text-brand-ink">Voorgestelde aandachtspunten</p>
                <ul class="mt-1.5 list-disc space-y-1 pl-5 leading-relaxed">
                    @foreach ($demoAttentionPoints as $point)
                        <li>{{ $point->label }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="mt-4 font-semibold text-brand-ink">Bewust uitgeschakeld in de demo</p>
        <ul class="mt-1.5 list-disc space-y-1 pl-5 leading-relaxed">
            @foreach ($hidden as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
        <p class="mt-4 leading-relaxed">
            @if ($installerReturnUrl)
                <a href="{{ $installerReturnUrl }}" class="font-semibold text-brand-sea underline">Terug naar de opname</a>
                of ga
                <a href="{{ url('/') }}" class="font-semibold text-brand-sea underline">terug naar de website</a>.
            @else
                Ga terug naar het andere tabblad om de bijgewerkte werkplek te bekijken, of ga
                <a href="{{ url('/') }}" class="font-semibold text-brand-sea underline">terug naar de website</a>.
            @endif
        </p>
    </div>
@endif
