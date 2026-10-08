@php
    $copy = match ($reason ?? 'unavailable') {
        'used' => [
            'title' => 'Deze link is al gebruikt',
            'body' => 'Je hebt deze opname al afgerond. Je installateur bekijkt de gegevens. Je hoeft niets meer te doen.',
        ],
        'replaced' => [
            'title' => 'Deze link werkt niet meer',
            'body' => 'Je installateur heeft je een nieuwere link gestuurd. Gebruik de link uit het laatste bericht.',
        ],
        'revoked', 'disabled' => [
            'title' => 'Deze link werkt niet meer',
            'body' => 'De installateur heeft deze link uitgeschakeld. Vraag om een nieuwe link als er nog iets openstaat.',
        ],
        'expired' => [
            'title' => 'Deze link is verlopen',
            'body' => 'De geldigheid van deze link is voorbij. Vraag je installateur om een nieuwe link.',
        ],
        default => [
            'title' => 'Deze link is niet meer geldig',
            'body' => 'Je kunt deze pagina niet meer openen. Vraag je installateur om een nieuwe link als dat nodig is.',
        ],
    };
@endphp
<!DOCTYPE html>
<html lang="nl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $copy['title'] }} — Digitale Opname</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-[#eef1ec] font-sans text-[#18201d] antialiased">
        <main class="mx-auto flex min-h-screen max-w-lg flex-col justify-center px-5 py-12">
            <p class="eyebrow">Digitale Opname</p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight text-gray-950">{{ $copy['title'] }}</h1>
            <p class="mt-3 text-base leading-relaxed text-gray-600">
                {{ $copy['body'] }}
            </p>
            <div class="mt-8">
                <a
                    href="{{ url('/') }}"
                    class="inline-flex min-h-11 items-center justify-center rounded-xl bg-marketing-green-dark px-5 text-sm font-semibold text-white hover:bg-marketing-green"
                >
                    Naar de homepage
                </a>
            </div>
        </main>
    </body>
</html>
