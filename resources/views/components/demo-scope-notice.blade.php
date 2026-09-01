@props([
    'variant' => 'banner',
    'demoAiSummary' => null,
    'demoAttentionPoints' => [],
    'shortCustomer' => false,
    'installerReturnUrl' => null,
])

@if ($variant === 'banner')
    <aside {{ $attributes->merge(['class' => 'mb-4 rounded-md border border-brand-ember/40 bg-brand-ember/10 px-4 py-3 text-left text-sm text-brand-ink', 'role' => 'status']) }}>
        <p class="font-semibold text-brand-ember">
            {{ $shortCustomer ? 'Demo — wat de klant ziet' : 'Demo — aanvulling door de klant' }}
        </p>
        <p class="mt-1 leading-relaxed text-brand-ink/75">
            @if ($shortCustomer)
                Je vult in wat de klant invult na jouw link. Geen echte klant, er gaat geen mail uit.
            @else
                Je bekijkt één opdracht uit de tijdelijke opname. Geen echte klant, er gaat geen mail uit. De gegevens verdwijnen vanzelf.
            @endif
        </p>
    </aside>
@elseif ($variant === 'complete')
    <div {{ $attributes->merge(['class' => 'mt-5 border-t border-brand-fog/80 pt-5 text-sm text-brand-ink/80']) }}>
        <p class="font-semibold text-brand-ink">Wat je net hebt gedaan</p>
        <p class="mt-1 leading-relaxed">
            @if ($shortCustomer)
                Je hebt afgerond wat de klant na de link invult. Geen echte klant, er ging geen mail uit. De gegevens verdwijnen vanzelf; een PDF gaat alleen als je die aanvraagt.
            @else
                Je hebt één aanvulling afgerond. Geen echte klant, er ging geen mail uit. De gegevens verdwijnen vanzelf; een PDF gaat alleen als je die aanvraagt.
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
