@props([
    'variant' => 'banner',
])

@php
    $hidden = [
        'E-mail met de persoonlijke klantlink (en herinneringen)',
        'Afrondingsmail naar de installateur',
        'AI-samenvatting en aandachtspunten op het dossier',
        'PDF-export van het rapport',
        'Beoordeling en aanvullingsronde in het installateursdashboard',
    ];
@endphp

@if ($variant === 'banner')
    <aside {{ $attributes->merge(['class' => 'mb-4 rounded-md border border-brand-ember/40 bg-brand-ember/10 px-4 py-3 text-left text-sm text-brand-ink', 'role' => 'status']) }}>
        <p class="font-semibold text-brand-ember">Demo — je ervaart de klantflow</p>
        <p class="mt-1 leading-relaxed text-brand-ink/75">
            Dezelfde vragen en foto’s als bij een echte opname. Geen echte offerte; gegevens verdwijnen automatisch.
        </p>
        <p class="mt-2 font-medium text-brand-ink/85">In de volledige app gebeurt daarna ook (hier uitgeschakeld):</p>
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
            De begeleide klantintake: vragen beantwoorden, foto’s uploaden en afronden — het kernpad van aanvraag naar dossier.
        </p>
        <p class="mt-4 font-semibold text-brand-ink">Wat de volledige app daarna nog doet</p>
        <ul class="mt-1.5 list-disc space-y-1 pl-5 leading-relaxed">
            @foreach ($hidden as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
        <p class="mt-4 leading-relaxed">
            Wil je dat zelf proberen?
            <a href="{{ route('register') }}" class="font-semibold text-brand-sea underline">Maak een account</a>
            of ga
            <a href="{{ url('/') }}" class="font-semibold text-brand-sea underline">terug naar de homepage</a>.
        </p>
    </div>
@endif
