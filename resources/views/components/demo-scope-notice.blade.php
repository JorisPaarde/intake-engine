@props([
    'variant' => 'banner',
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
                Je hebt afgerond wat de klant na de link invult. Geen echte klant, er ging geen mail uit. De gegevens verdwijnen vanzelf.
            @else
                Je hebt als klant een aanvulling verstuurd. Geen echte klant, er ging geen mail uit. De gegevens verdwijnen vanzelf.
            @endif
        </p>

        {{-- Aandachtspunten en AI-debug blijven bij de installateur; niet tonen aan de klant. --}}

        {{-- Altijd een zichtbare knop, geen tekstlink midden in een zin (BL-147, UX #16.2). --}}
        <div class="mt-4">
            @if ($installerReturnUrl)
                <a href="{{ $installerReturnUrl }}" class="inline-flex min-h-11 items-center justify-center rounded-md bg-brand-sea px-4 text-sm font-semibold text-white hover:bg-brand-sea/90" data-testid="demo-return-to-workspace">Terug naar de opname</a>
            @else
                <a href="{{ url('/') }}" class="inline-flex min-h-11 items-center justify-center rounded-md border border-brand-fog bg-white px-4 text-sm font-semibold text-brand-ink hover:bg-brand-mist/40" data-testid="demo-return-home">Naar de homepage</a>
            @endif
        </div>
    </div>
@endif
