@props([
    'intake',
])

@php
    $hasPdf = (bool) ($intake->report?->hasPdf());
@endphp

<section
    id="demo-pdf-request"
    {{ $attributes->merge(['class' => 'rounded-3xl border border-sky-200 bg-sky-50 p-5 shadow-sm sm:p-6']) }}
    data-demo-anchor="pdf-request"
>
    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-sky-700">Afronden · demorapport</p>
    <h3 class="mt-2 text-xl font-semibold text-gray-950">Wil je het demorapport als PDF?</h3>
    <p class="mt-2 max-w-2xl text-sm leading-relaxed text-gray-700">
        Vul je e-mailadres in. We sturen het rapport van deze demosessie. We noteren dit adres ook als
        kennismakingsaanvraag — zonder klantgegevens uit de demo.
    </p>

    <form method="POST" action="{{ route('demo.report-pdf', $intake) }}" class="mt-4 space-y-3">
        @csrf
        <div class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
            <div>
                <x-input-label for="demo_pdf_email" value="E-mailadres" />
                <x-text-input
                    id="demo_pdf_email"
                    name="email"
                    type="email"
                    class="mt-1 block w-full"
                    :value="old('email')"
                    required
                    autocomplete="email"
                    placeholder="jij@bedrijf.nl"
                />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>
            <x-primary-button class="min-h-11 justify-center whitespace-nowrap">
                Stuur demorapport
            </x-primary-button>
        </div>
        <div class="absolute -left-[9999px] h-0 w-0 overflow-hidden" aria-hidden="true">
            <label for="demo_pdf_website">Website</label>
            <input id="demo_pdf_website" type="text" name="website" tabindex="-1" autocomplete="off">
        </div>
    </form>

    @if ($hasPdf)
        <p class="mt-4 text-sm text-gray-700">
            <a href="{{ route('intakes.pdf', $intake) }}" class="font-semibold text-sky-800 underline hover:text-sky-950">
                Download het demorapport nu
            </a>
        </p>
    @endif
</section>
