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
            'Verkorte demoroute met dezelfde klantvragen als in de app',
            'Antwoorden landen in hetzelfde tijdelijke installateursdossier',
        ]
        : [
            'Dezelfde afgeschermde klanttaak en uploadflow als in de app',
            'De aanvulling wordt direct aan hetzelfde technische dossier gekoppeld',
        ];
    $hidden = [
        'E-mail en herinneringen naar een echte klant',
        'Live AI-aanroepen',
        'PDF-export van het rapport',
        ...($shortCustomer ? ['De volledige productievragenlijst (adaptief en langer)'] : []),
    ];
@endphp

@if ($variant === 'banner')
    <aside {{ $attributes->merge(['class' => 'mb-4 rounded-md border border-brand-ember/40 bg-brand-ember/10 px-4 py-3 text-left text-sm text-brand-ink', 'role' => 'status']) }}>
        <p class="font-semibold text-brand-ember">
            {{ $shortCustomer ? 'Demo — verkorte klantroute' : 'Demo — gerichte klantaanvulling' }}
        </p>
        <p class="mt-1 leading-relaxed text-brand-ink/75">
            @if ($shortCustomer)
                Je bekijkt wat de klant ziet na het versturen van de link. Deze demoroute is verkort tot een paar representatieve stappen; er is geen echte klant of woning.
            @else
                Je bekijkt één specifieke opdracht vanuit het fictieve installateursdossier. Er is geen echte klant of woning; de tijdelijke gegevens verdwijnen automatisch.
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
        <p class="font-semibold text-brand-ink">Wat je net hebt gedaan</p>
        <p class="mt-1 leading-relaxed">
            @if ($shortCustomer)
                De verkorte klantroute is afgerond. In productie zou de installateur nu een afrondingsmail krijgen.
            @else
                Eén gerichte klantaanvulling afgerond. De foto of het antwoord is aan hetzelfde fictieve installateursdossier gekoppeld en de klanttoegang is weer gesloten.
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
                <a href="{{ $installerReturnUrl }}" class="font-semibold text-brand-sea underline">Terug naar het installateursdossier</a>
                of ga
                <a href="{{ url('/') }}" class="font-semibold text-brand-sea underline">terug naar de website</a>.
            @else
                Ga terug naar het andere tabblad om de bijgewerkte installateurswerkplek te bekijken, of ga
                <a href="{{ url('/') }}" class="font-semibold text-brand-sea underline">terug naar de website</a>.
            @endif
        </p>
    </div>
@endif
