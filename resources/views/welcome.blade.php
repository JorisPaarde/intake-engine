<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta
            name="description"
            content="Breng aanvraag, woninggegevens en klantinformatie samen in één duidelijke airco-opname. Zie sneller of je kunt offreren, iets moet aanvullen of toch langsgaat."
        >
        <meta property="og:type" content="website">
        <meta property="og:locale" content="nl_NL">
        <meta property="og:title" content="Digitale Opname voor airco-installateurs">
        <meta
            property="og:description"
            content="Een complete opname vóór je de bus instapt. Alles wat je nodig hebt om te offreren, overzichtelijk bij elkaar."
        >
        <meta property="og:url" content="{{ route('home') }}">

        <title>Digitale Opname voor airco-installateurs</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800,900&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            .home-hero {
                background:
                    linear-gradient(90deg, rgba(14, 28, 24, 0.96) 0%, rgba(14, 28, 24, 0.86) 34%, rgba(14, 28, 24, 0.36) 68%, rgba(14, 28, 24, 0.22) 100%),
                    linear-gradient(0deg, rgba(24, 32, 29, 0.74), rgba(24, 32, 29, 0.08) 56%),
                    linear-gradient(130deg, #12241f 0%, #315f4f 62%, #b8863c 100%);
            }

            .home-grid {
                background-image:
                    linear-gradient(rgba(255, 255, 255, 0.045) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(255, 255, 255, 0.045) 1px, transparent 1px);
                background-size: 48px 48px;
            }

            @keyframes home-fade-up {
                from { opacity: 0; transform: translateY(14px); }
                to { opacity: 1; transform: translateY(0); }
            }

            .home-reveal {
                animation: home-fade-up 0.7s ease-out both;
            }

            .home-reveal-delay {
                animation-delay: 0.12s;
            }

            .home-reveal-delay-2 {
                animation-delay: 0.24s;
            }

            @media (prefers-reduced-motion: reduce) {
                html {
                    scroll-behavior: auto;
                }

                .home-reveal {
                    animation: none;
                }
            }
        </style>
    </head>
    <body class="bg-marketing-paper font-marketing text-marketing-ink antialiased">
        <header class="absolute inset-x-0 top-0 z-30">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-5 py-5 sm:px-8">
                <a
                    href="{{ route('home') }}"
                    class="text-lg font-extrabold tracking-tight text-white sm:text-xl"
                    aria-label="Digitale Opname homepage"
                >
                    Digitale Opname
                </a>

                <nav class="hidden items-center gap-7 lg:flex" aria-label="Hoofdnavigatie">
                    <a class="text-sm font-bold text-white/90 transition hover:text-white" href="#werkwijze">Zo werkt het</a>
                    <a class="text-sm font-bold text-white/90 transition hover:text-white" href="#voordelen">Voordelen</a>
                    <a class="text-sm font-bold text-white/90 transition hover:text-white" href="#product">Bekijk de app</a>
                    <a class="text-sm font-bold text-white/90 transition hover:text-white" href="#vragen">Vragen</a>
                </nav>

                <div class="flex items-center gap-2 sm:gap-3">
                    @auth
                        <a
                            href="{{ route('dashboard') }}"
                            class="inline-flex min-h-10 items-center justify-center bg-marketing-amber px-4 text-sm font-extrabold text-marketing-ink transition hover:brightness-105"
                        >
                            Naar opnames
                        </a>
                    @else
                        <a
                            href="{{ route('login') }}"
                            class="hidden px-3 py-2 text-sm font-bold text-white/90 transition hover:text-white sm:inline-flex"
                        >
                            Inloggen
                        </a>
                        <a
                            href="#interesse"
                            class="inline-flex min-h-10 items-center justify-center bg-marketing-amber px-4 text-sm font-extrabold text-marketing-ink transition hover:brightness-105"
                        >
                            Ik wil een pilot
                        </a>
                    @endauth
                </div>
            </div>
        </header>

        <main>
            <section class="home-hero relative overflow-hidden text-white">
                <div class="home-grid absolute inset-0 opacity-40" aria-hidden="true"></div>
                <div class="relative mx-auto grid min-h-[760px] max-w-7xl items-center gap-16 px-5 pb-20 pt-32 sm:px-8 lg:grid-cols-[0.9fr_1.1fr] lg:py-32">
                    <div class="max-w-2xl">
                        <p class="home-reveal text-[0.78rem] font-black uppercase text-marketing-amber">
                            Voor airco-installateurs
                        </p>
                        <h1 class="home-reveal home-reveal-delay mt-4 text-4xl font-extrabold leading-[0.94] text-white sm:text-5xl lg:text-6xl">
                            Een complete opname vóór je de bus instapt.
                        </h1>
                        <p class="home-reveal home-reveal-delay-2 mt-6 max-w-xl text-lg leading-relaxed text-white/88">
                            De app zet bij elkaar wat al bekend is, wat de klant aanlevert en wat jij zelf toevoegt. Zo zie je sneller: offreren, gericht aanvullen of toch langsgaan.
                        </p>

                        <div class="home-reveal home-reveal-delay-2 mt-9 flex flex-col gap-3 sm:flex-row sm:items-center">
                            @guest
                                @if (config('intake.demo.enabled'))
                                    <form method="POST" action="{{ route('demo.start') }}">
                                        @csrf
                                        <button
                                            type="submit"
                                            class="inline-flex min-h-[52px] w-full items-center justify-center bg-marketing-amber px-6 text-base font-extrabold text-marketing-ink transition hover:brightness-105 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-marketing-amber sm:w-auto"
                                        >
                                            Probeer de demo
                                            <svg class="ml-2 h-4 w-4" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                                <path d="M3.5 8h9m-3.5-3.5L12.5 8 9 11.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        </button>
                                    </form>
                                @endif
                                <a
                                    href="#interesse"
                                    class="inline-flex min-h-[52px] items-center justify-center border border-white/55 px-6 text-base font-extrabold text-white transition hover:border-white hover:bg-white/5 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-marketing-amber"
                                >
                                    Ik wil een pilot
                                </a>
                            @else
                                <a
                                    href="{{ route('dashboard') }}"
                                    class="inline-flex min-h-13 items-center justify-center bg-marketing-amber px-6 text-base font-extrabold text-marketing-ink transition hover:brightness-105"
                                >
                                    Open dashboard
                                </a>
                            @endguest
                        </div>

                        @guest
                            @if (config('intake.demo.enabled'))
                                <div class="home-reveal home-reveal-delay-2 mt-6 flex flex-wrap gap-x-5 gap-y-2 text-sm text-white/70">
                                    @foreach (['Bekende gegevens staan klaar', 'Klant, zelf of samen', 'Jij houdt de regie'] as $item)
                                        <span class="inline-flex items-center gap-2">
                                            <svg class="h-4 w-4 text-marketing-amber" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                                <path d="m3 8.2 3.1 3.1L13 4.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                            {{ $item }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        @endguest
                    </div>

                    <div class="home-reveal home-reveal-delay-2 relative mx-auto w-full max-w-2xl" aria-label="Productweergave met fictieve demo-inhoud">
                        <div class="relative overflow-hidden border border-white/28 bg-white shadow-[0_30px_90px_rgba(6,17,14,0.48)]">
                            <div class="flex h-11 items-center gap-2 border-b border-marketing-line bg-marketing-mist px-4">
                                <span class="h-2.5 w-2.5 rounded-full bg-marketing-coral"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-marketing-amber"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-marketing-leaf"></span>
                                <span class="ml-3 hidden bg-white px-4 py-1 text-[10px] font-medium text-marketing-muted sm:block">intake-engine.nl/opname</span>
                                <span class="ml-auto bg-marketing-mist px-2.5 py-1 text-[10px] font-extrabold text-marketing-green-dark">Fictieve demo</span>
                            </div>

                            <div class="grid min-h-[420px] bg-marketing-mist sm:grid-cols-[150px_1fr]">
                                <aside class="hidden border-r border-marketing-line bg-white p-4 sm:block">
                                    <p class="text-xs font-extrabold text-marketing-ink">Voorbeeldstraat 12</p>
                                    <p class="mt-1 text-[10px] text-marketing-muted">Airco-opname</p>
                                    <div class="mt-6 space-y-2">
                                        @foreach ([
                                            ['Overzicht', true],
                                            ['Ruimtes', false],
                                            ['Posities', false],
                                            ['Verbindingen', false],
                                            ['Klanttaak', false],
                                        ] as [$label, $active])
                                            <div class="px-2.5 py-2 text-[10px] font-bold {{ $active ? 'bg-[#e5f0ec] text-marketing-green-dark' : 'text-marketing-muted' }}">
                                                {{ $label }}
                                            </div>
                                        @endforeach
                                    </div>
                                </aside>

                                <div class="p-4 sm:p-6">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p class="text-[10px] font-black uppercase text-marketing-coral">Opname</p>
                                            <h2 class="mt-1 text-base font-extrabold text-marketing-ink sm:text-lg">Bijna klaar</h2>
                                        </div>
                                        <span class="bg-[#e5f0ec] px-2.5 py-1 text-[10px] font-extrabold text-marketing-green">6 klaar · 1 check</span>
                                    </div>

                                    <div class="mt-5 grid gap-3 md:grid-cols-2">
                                        <div class="overflow-hidden border border-marketing-line bg-white">
                                            <div class="landing-photo-bedroom h-28" role="img" aria-label="Fictieve slaapkamerfoto"></div>
                                            <div class="p-3">
                                                <p class="text-[10px] font-extrabold text-marketing-ink">Slaapkamer ouders</p>
                                                <p class="mt-1 text-[10px] leading-relaxed text-marketing-muted">Binnenplek en muurdoorgang zichtbaar</p>
                                            </div>
                                        </div>
                                        <div class="space-y-2">
                                            @foreach ([
                                                ['Koelleiding', 'Lijkt goed', 'bg-marketing-leaf'],
                                                ['Condensafvoer', 'Lijkt goed', 'bg-marketing-green'],
                                                ['Stroomtoevoer', 'Nog checken', 'bg-marketing-amber'],
                                            ] as [$label, $status, $color])
                                                <div class="flex items-center gap-2 border border-marketing-line bg-white px-3 py-2.5">
                                                    <span class="h-2 w-2 rounded-full {{ $color }}"></span>
                                                    <span class="flex-1 text-[10px] font-extrabold text-marketing-ink">{{ $label }}</span>
                                                    <span class="text-[9px] text-marketing-muted">{{ $status }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="mt-3 flex items-start gap-3 border border-marketing-amber/40 bg-[#fbf3e4] p-3">
                                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center bg-marketing-amber text-xs font-black text-marketing-ink">!</span>
                                        <div>
                                            <p class="text-[10px] font-extrabold text-marketing-ink">Nog één ding nodig</p>
                                            <p class="mt-1 text-[10px] leading-relaxed text-marketing-muted">De groepsaanduiding is nog niet zeker. Vraag dit ene detail na; de rest van het voorstel blijft staan.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="absolute -bottom-8 -left-3 hidden w-48 border border-marketing-line bg-white p-3 shadow-[0_22px_70px_rgba(21,57,47,0.18)] sm:block lg:-left-10">
                            <p class="text-[9px] font-black uppercase text-marketing-muted">Staat al klaar</p>
                            <div class="mt-2 grid grid-cols-3 gap-2 text-center">
                                @foreach ([['1996', 'Bouwjaar'], ['118 m²', 'Oppervlak'], ['B', 'Label']] as [$value, $label])
                                    <div class="bg-marketing-mist px-2 py-2">
                                        <p class="text-[11px] font-extrabold text-marketing-ink">{{ $value }}</p>
                                        <p class="mt-0.5 text-[8px] text-marketing-muted">{{ $label }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="border-b border-marketing-line bg-marketing-paper px-5 py-8 sm:px-8">
                <div class="mx-auto flex max-w-7xl flex-col gap-5 text-center sm:flex-row sm:items-center sm:justify-between sm:text-left">
                    <p class="text-sm font-extrabold text-marketing-ink">De app doet het voorwerk. Jij geeft het vakmatige oordeel.</p>
                    <div class="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm text-marketing-muted sm:justify-end">
                        <span>Bekende gegevens erbij</span>
                        <span>Klant of zelf opnemen</span>
                        <span>Jij beslist</span>
                    </div>
                </div>
            </section>

            <section class="bg-marketing-mist px-5 py-16 sm:px-8 sm:py-20">
                <div class="mx-auto max-w-7xl">
                    <div class="grid gap-12 lg:grid-cols-[0.85fr_1.15fr] lg:items-end">
                        <div class="max-w-xl">
                            <p class="text-[0.78rem] font-black uppercase text-marketing-coral">Herkenbaar?</p>
                            <h2 class="mt-3 text-3xl font-extrabold leading-none text-marketing-ink sm:text-4xl">
                                Een busrit is een dure manier om informatie te verzamelen.
                            </h2>
                        </div>
                        <p class="max-w-2xl text-lg leading-relaxed text-marketing-muted">
                            Soms is een voorbezoek echt nodig. Maar soms ga je vooral omdat gegevens ontbreken, berichten verspreid staan en niemand zeker weet of de situatie compleet is.
                        </p>
                    </div>

                    <div class="mt-10 grid gap-0 border-t border-marketing-line md:grid-cols-3">
                        @foreach ([
                            ['01', 'Alles verspreid', 'Aanvraag, foto’s en notities staan op verschillende plekken.'],
                            ['02', 'Pas laat gezien', 'Een ontbrekende maat of onduidelijke situatie valt pas op als je wilt offreren.'],
                            ['03', 'Voor de zekerheid langs', 'Zonder compleet overzicht voelt een rit al snel als de veiligste keuze.'],
                        ] as [$number, $title, $copy])
                            <article class="border-b border-marketing-line bg-transparent p-6 md:border-b-0 md:border-r md:last:border-r-0">
                                <span class="text-xs font-black text-marketing-coral">{{ $number }}</span>
                                <h3 class="mt-4 text-xl font-extrabold text-marketing-ink">{{ $title }}</h3>
                                <p class="mt-2 text-base leading-relaxed text-marketing-muted">{{ $copy }}</p>
                            </article>
                        @endforeach
                    </div>

                    <div class="mt-8 bg-marketing-green-dark px-6 py-6 text-white sm:flex sm:items-center sm:justify-between sm:gap-8 sm:px-8">
                        <p class="text-xl font-extrabold sm:text-2xl">Laat de app het verzamelwerk doen.</p>
                        <p class="mt-2 max-w-xl text-sm leading-relaxed text-white/75 sm:mt-0 sm:text-right">Dan houd jij tijd over voor beoordelen, offreren en installeren.</p>
                    </div>
                </div>
            </section>

            <section id="werkwijze" class="scroll-mt-6 border-t border-marketing-line bg-gradient-to-b from-white to-marketing-paper px-5 py-16 sm:px-8 sm:py-20">
                <div class="mx-auto max-w-7xl">
                    <div class="max-w-2xl">
                        <p class="text-[0.78rem] font-black uppercase text-marketing-coral">Zo werkt het</p>
                        <h2 class="mt-3 text-3xl font-extrabold leading-none text-marketing-ink sm:text-4xl">
                            Van aanvraag naar een duidelijk besluit
                        </h2>
                        <p class="mt-4 text-lg leading-relaxed text-marketing-muted">
                            Laat de klant aanvullen, doe de opname zelf of combineer beide. Alles groeit mee in hetzelfde overzicht.
                        </p>
                    </div>

                    <ol class="mt-10 grid gap-0 border-t border-marketing-line lg:grid-cols-3">
                        @foreach ([
                            ['1', 'Wat al bekend is, staat klaar', 'De aanvraag en bekende woninggegevens worden meteen bij elkaar gezet.'],
                            ['2', 'Alleen aanvullen wat nodig is', 'De klant doet dat stap voor stap, jij doet het zelf of jullie combineren het.'],
                            ['3', 'Jij ziet waar je aan toe bent', 'Offreren, gericht aanvullen of toch langsgaan. Jij houdt de regie.'],
                        ] as [$number, $title, $copy])
                            <li class="border-b border-marketing-line bg-transparent p-6 sm:p-7 lg:border-b-0 lg:border-r lg:last:border-r-0">
                                <span class="flex h-9 w-9 items-center justify-center bg-marketing-green-dark text-sm font-extrabold text-white">{{ $number }}</span>
                                <h3 class="mt-6 text-lg font-extrabold text-marketing-ink">{{ $title }}</h3>
                                <p class="mt-3 text-sm leading-relaxed text-marketing-muted">{{ $copy }}</p>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </section>

            <section id="voordelen" class="scroll-mt-6 border-t border-marketing-line bg-marketing-mist px-5 py-16 sm:px-8 sm:py-20">
                <div class="mx-auto max-w-7xl">
                    <div class="max-w-2xl">
                        <p class="text-[0.78rem] font-black uppercase text-marketing-coral">Wat levert het op?</p>
                        <h2 class="mt-3 text-3xl font-extrabold leading-none text-marketing-ink sm:text-4xl">
                            Rust in je planning. Duidelijkheid voor je klant.
                        </h2>
                    </div>

                    <div class="mt-10 grid gap-6 lg:grid-cols-2">
                        <article class="bg-marketing-green-dark p-7 text-white sm:p-10">
                            <p class="text-[0.78rem] font-black uppercase text-marketing-amber">Voor jou</p>
                            <h3 class="mt-4 text-2xl font-extrabold sm:text-3xl">Minder voorwerk per aanvraag</h3>
                            <ul class="mt-8 space-y-4">
                                @foreach ([
                                    'Minder zoeken, bellen en appen',
                                    'Sneller van aanvraag naar onderbouwde offerte',
                                    'Met meer zekerheid naar de montage',
                                ] as $item)
                                    <li class="flex gap-4">
                                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center bg-marketing-amber text-marketing-ink">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                                <path d="m3 8.2 3.1 3.1L13 4.8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        </span>
                                        <p class="font-bold leading-relaxed">{{ $item }}</p>
                                    </li>
                                @endforeach
                            </ul>
                        </article>

                        <article class="border border-marketing-line bg-white p-7 sm:p-10">
                            <p class="text-[0.78rem] font-black uppercase text-marketing-coral">Voor je klant</p>
                            <h3 class="mt-4 text-2xl font-extrabold text-marketing-ink sm:text-3xl">Een makkelijke opname zonder vakkennis</h3>
                            <ul class="mt-8 space-y-4">
                                @foreach ([
                                    'Alleen doen wat op dat moment nodig is',
                                    'Duidelijke vragen en opdrachten',
                                    'Minder herhaling en onnodige afspraken',
                                ] as $item)
                                    <li class="flex gap-4">
                                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center bg-[#f6ebe7] text-marketing-coral">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                                <path d="m3 8.2 3.1 3.1L13 4.8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        </span>
                                        <p class="font-bold leading-relaxed text-marketing-ink">{{ $item }}</p>
                                    </li>
                                @endforeach
                            </ul>
                        </article>
                    </div>
                </div>
            </section>

            <section id="product" class="scroll-mt-6 overflow-hidden border-t border-marketing-line bg-white px-5 py-16 sm:px-8 sm:py-20">
                <div class="mx-auto max-w-7xl">
                    <div class="grid gap-10 lg:grid-cols-[0.72fr_1.28fr] lg:items-end">
                        <div class="max-w-xl">
                            <p class="text-[0.78rem] font-black uppercase text-marketing-coral">In één oogopslag</p>
                            <h2 class="mt-3 text-3xl font-extrabold leading-none text-marketing-ink sm:text-4xl">
                                Van woninggegevens tot installatievoorstel
                            </h2>
                        </div>
                        <p class="max-w-2xl text-lg leading-relaxed text-marketing-muted">
                            De woning, gewenste ruimtes, mogelijke opstelling, routes en open punten staan bij elkaar. De app houdt het overzicht; jij geeft het vakmatige oordeel.
                        </p>
                    </div>

                    <div class="mt-12 grid gap-8 lg:grid-cols-[1fr_320px] lg:items-center">
                        <div class="overflow-hidden border border-marketing-line bg-marketing-mist shadow-[0_22px_70px_rgba(21,57,47,0.12)]">
                            <div class="flex items-center gap-2 border-b border-marketing-line bg-white px-4 py-3">
                                <span class="h-2.5 w-2.5 rounded-full bg-marketing-coral"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-marketing-amber"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-marketing-leaf"></span>
                                <span class="ml-3 text-xs font-medium text-marketing-muted">Opname · Voorbeeldstraat 12</span>
                            </div>

                            <div class="p-4 sm:p-7">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="text-xs font-black uppercase text-marketing-coral">Voorstel</p>
                                        <h3 class="mt-1 text-xl font-extrabold text-marketing-ink">Optie A · één multi-split</h3>
                                    </div>
                                    <span class="w-fit bg-[#e5f0ec] px-3 py-1.5 text-xs font-extrabold text-marketing-green">Voorkeursoptie</span>
                                </div>

                                <div class="mt-6 grid gap-4 md:grid-cols-2">
                                    @foreach ([
                                        ['Koelleiding slaapkamer ouders', 'Binnenwand → overloop → achtergevel', 'Lijkt goed', 'ok'],
                                        ['Condensafvoer slaapkamer ouders', 'Binnenpositie → bestaande afvoer', 'Lijkt goed', 'ok'],
                                        ['Koelleiding werkkamer', 'Binnenwand → achtergevel', 'Lijkt goed', 'ok'],
                                        ['Stroom naar buitenunit', 'Meterkast → kruipruimte → achteraanbouw', 'Nog checken', 'amber'],
                                    ] as [$title, $route, $status, $tone])
                                        <article class="border {{ $tone === 'amber' ? 'border-marketing-amber/40 bg-[#fbf3e4]' : 'border-marketing-line bg-white' }} p-4">
                                            <div class="flex items-start justify-between gap-3">
                                                <p class="text-sm font-extrabold text-marketing-ink">{{ $title }}</p>
                                                <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $tone === 'amber' ? 'bg-marketing-amber' : 'bg-marketing-leaf' }}"></span>
                                            </div>
                                            <p class="mt-2 text-xs leading-relaxed text-marketing-muted">{{ $route }}</p>
                                            <p class="mt-4 text-[11px] font-extrabold {{ $tone === 'amber' ? 'text-marketing-coral' : 'text-marketing-green' }}">{{ $status }}</p>
                                        </article>
                                    @endforeach
                                </div>

                                <div class="mt-4 grid gap-4 sm:grid-cols-[180px_1fr]">
                                    <div class="landing-photo-fusebox min-h-40" role="img" aria-label="Fictieve foto van een meterkast"></div>
                                    <div class="border border-marketing-amber/40 bg-[#fbf3e4] p-5">
                                        <p class="text-xs font-black uppercase text-marketing-coral">Nog één controle</p>
                                        <h4 class="mt-2 font-extrabold text-marketing-ink">Groepsaanduiding controleren</h4>
                                        <p class="mt-2 text-sm leading-relaxed text-marketing-muted">
                                            De stroomroute is nog niet zeker. Vraag alleen het ontbrekende detail op of controleer het zelf.
                                        </p>
                                        <div class="mt-4 inline-flex bg-marketing-green-dark px-4 py-2.5 text-xs font-extrabold text-white">Vraag klant om aanvulling</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mx-auto w-full max-w-[300px] border-[8px] border-marketing-green-dark bg-marketing-green-dark p-1 shadow-[0_22px_70px_rgba(21,57,47,0.18)]">
                            <div class="overflow-hidden bg-white">
                                <div class="flex h-8 items-center justify-center bg-marketing-green-dark">
                                    <span class="h-1.5 w-16 rounded-full bg-white/25"></span>
                                </div>
                                <div class="px-5 pb-6 pt-5">
                                    <div class="flex items-center justify-between">
                                        <p class="text-xs font-extrabold text-marketing-green-dark">Digitale Opname</p>
                                        <span class="text-[10px] text-marketing-muted">1 van 1</span>
                                    </div>
                                    <div class="mt-5 h-1.5 overflow-hidden bg-marketing-mist">
                                        <div class="h-full w-2/3 bg-marketing-green"></div>
                                    </div>
                                    <p class="mt-6 text-[10px] font-black uppercase text-marketing-coral">Meterkast</p>
                                    <h3 class="mt-2 text-lg font-extrabold leading-snug text-marketing-ink">Maak één frontale foto van alle groepslabels</h3>
                                    <p class="mt-3 text-xs leading-relaxed text-marketing-muted">
                                        Houd de telefoon recht en zorg dat de tekst scherp leesbaar is. Je hoeft niets open te maken.
                                    </p>
                                    <div class="landing-photo-fusebox mt-5 h-40" role="img" aria-label="Fictief fotovoorbeeld van een meterkast"></div>
                                    <div class="mt-4 bg-marketing-amber px-4 py-3 text-center text-xs font-extrabold text-marketing-ink">Foto toevoegen</div>
                                    <p class="mt-3 text-center text-[10px] leading-relaxed text-marketing-muted">Geen technische keuze nodig</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <p class="mt-5 text-xs leading-relaxed text-marketing-muted">
                        Fictieve voorbeeldopname. De demo gebruikt geen echte klantdata.
                    </p>
                </div>
            </section>

            <section id="demo" class="bg-marketing-green-dark px-5 py-16 text-white sm:px-8 sm:py-20">
                <div class="mx-auto flex max-w-7xl flex-col gap-8 lg:flex-row lg:items-center lg:justify-between">
                    <div class="max-w-2xl">
                        <p class="text-[0.78rem] font-black uppercase text-marketing-amber">Zelf even proberen</p>
                        <h2 class="mt-3 text-3xl font-extrabold leading-none sm:text-4xl">
                            Start zoals een installateur
                        </h2>
                        <p class="mt-4 text-lg leading-relaxed text-white/75">
                            Zelfde start als na een aanvraag, begeleid in pop-ups. Kies daarna of je doorgaat als klant of zelf de opname doet. Zonder account, in ongeveer drie minuten.
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col gap-3 sm:flex-row">
                        @guest
                            @if (config('intake.demo.enabled'))
                                <form method="POST" action="{{ route('demo.start') }}">
                                    @csrf
                                    <button
                                        type="submit"
                                        class="inline-flex min-h-[52px] w-full items-center justify-center bg-marketing-amber px-6 text-base font-extrabold text-marketing-ink transition hover:brightness-105 sm:w-auto"
                                    >
                                        Probeer de demo
                                    </button>
                                </form>
                            @endif
                            <a
                                href="#interesse"
                                class="inline-flex min-h-[52px] items-center justify-center border border-white/55 px-6 text-base font-extrabold text-white transition hover:border-white"
                            >
                                Ik wil een pilot
                            </a>
                        @else
                            <a
                                href="{{ route('dashboard') }}"
                                class="inline-flex min-h-[52px] items-center justify-center bg-marketing-amber px-6 text-base font-extrabold text-marketing-ink transition hover:brightness-105"
                            >
                                Open dashboard
                            </a>
                        @endguest
                    </div>
                </div>
            </section>

            <section id="vragen" class="scroll-mt-6 border-t border-marketing-line bg-white px-5 py-16 sm:px-8 sm:py-20">
                <div class="mx-auto grid max-w-7xl gap-12 lg:grid-cols-[0.62fr_1.38fr]">
                    <div>
                        <p class="text-[0.78rem] font-black uppercase text-marketing-coral">Goed om te weten</p>
                        <h2 class="mt-3 text-3xl font-extrabold leading-none text-marketing-ink sm:text-4xl">
                            De app doet het voorwerk. Niet jouw vakwerk.
                        </h2>
                    </div>

                    <div class="divide-y divide-marketing-line border-y border-marketing-line">
                        @foreach ([
                            ['Vervangt dit elk voorbezoek?', 'Nee. Is iets niet veilig op afstand te beoordelen? Dan ga je gewoon langs. De app helpt vooral de ritten voorkomen die alleen nodig zijn om informatie te verzamelen.'],
                            ['Beslist de app of AI wat er moet komen?', 'Nee. De app zet de informatie op een rij en laat zien waar nog twijfel zit. Jij kiest de oplossing en geeft groen licht.'],
                            ['Wat staat er in zo’n opname?', 'Alles wat je nodig hebt om de situatie te beoordelen: bekende woninggegevens, de wens van de klant, ruimtes, mogelijke plekken, routes en wat nog gecontroleerd moet worden.'],
                            ['Kan ik de opname ook zelf doen?', 'Ja. Laat de klant aanvullen, doe de hele opname zelf of combineer beide. Alles blijft in hetzelfde overzicht staan.'],
                        ] as [$question, $answer])
                            <details class="group py-5">
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-5 text-base font-extrabold text-marketing-ink group-open:text-marketing-green">
                                    {{ $question }}
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center bg-marketing-mist text-marketing-green-dark transition group-open:rotate-45" aria-hidden="true">+</span>
                                </summary>
                                <p class="max-w-3xl pr-10 pt-3 text-sm leading-relaxed text-marketing-muted">{{ $answer }}</p>
                            </details>
                        @endforeach
                    </div>
                </div>
            </section>

            <section id="interesse" class="scroll-mt-6 border-t border-marketing-line bg-marketing-mist px-5 py-16 sm:px-8 sm:py-20">
                <div class="mx-auto grid max-w-7xl gap-12 lg:grid-cols-[0.78fr_1.22fr] lg:gap-20">
                    <div class="max-w-xl">
                        <p class="text-[0.78rem] font-black uppercase text-marketing-coral">Klein beginnen</p>
                        <h2 class="mt-3 text-3xl font-extrabold leading-none text-marketing-ink sm:text-4xl">
                            Probeer het met een paar echte aanvragen
                        </h2>
                        <p class="mt-5 text-lg leading-relaxed text-marketing-muted">
                            We kijken waar jij nu tijd verliest en maken daar een kleine pilot van. Daarna beslis je pas of het iets voor je is.
                        </p>

                        <ul class="mt-8 space-y-4 text-sm text-marketing-muted">
                            @foreach ([
                                'Aansluiten op hoe je nu werkt',
                                'Beginnen met een paar aanvragen',
                                'Alleen doorgaan als het echt tijd scheelt',
                            ] as $item)
                                <li class="flex items-start gap-3">
                                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center bg-marketing-green-dark text-white">
                                        <svg class="h-3 w-3" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                            <path d="m3 8.2 3.1 3.1L13 4.8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </span>
                                    {{ $item }}
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div class="border border-marketing-line bg-white p-6 shadow-[0_10px_40px_rgba(24,32,29,0.05)] sm:p-8">
                        @if (session('interest_submitted'))
                            <div class="border border-marketing-leaf/40 bg-[#e5f0ec] p-5" role="status">
                                <div class="flex items-start gap-3">
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center bg-marketing-green text-white">
                                        <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                            <path d="m3 8.2 3.1 3.1L13 4.8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </span>
                                    <div>
                                        <p class="font-extrabold text-marketing-green-dark">Je interesse is ontvangen.</p>
                                        <p class="mt-1 text-sm leading-relaxed text-marketing-muted">Bedankt. We nemen contact met je op om te kijken of een pilot past.</p>
                                    </div>
                                </div>
                            </div>
                        @else
                            <div>
                                <p class="text-sm font-extrabold text-marketing-green">Interesse?</p>
                                <h3 class="mt-1 text-2xl font-extrabold text-marketing-ink">Dan nemen we contact op</h3>
                            </div>

                            @if ($errors->any())
                                <div class="mt-5 border border-marketing-coral/30 bg-[#f6ebe7] p-4" role="alert">
                                    <p class="text-sm font-extrabold text-marketing-coral">Controleer de gemarkeerde velden.</p>
                                </div>
                            @endif

                            <form method="POST" action="{{ route('product-interest.store') }}" class="mt-6 space-y-5">
                                @csrf

                                <div class="absolute left-[-10000px] top-auto h-px w-px overflow-hidden" aria-hidden="true">
                                    <label for="website">Website</label>
                                    <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
                                </div>

                            <div class="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="company_name" class="block text-sm font-extrabold text-marketing-ink">Bedrijfsnaam</label>
                                    <input
                                        id="company_name"
                                        name="company_name"
                                        type="text"
                                        value="{{ old('company_name') }}"
                                        autocomplete="organization"
                                        required
                                        maxlength="120"
                                        class="mt-2 block w-full border-marketing-line px-3.5 py-3 text-sm shadow-none focus:border-marketing-green-dark focus:ring-marketing-green-dark"
                                    >
                                    @error('company_name')
                                        <p class="mt-1.5 text-sm text-marketing-coral">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <label for="contact_name" class="block text-sm font-extrabold text-marketing-ink">Naam</label>
                                    <input
                                        id="contact_name"
                                        name="contact_name"
                                        type="text"
                                        value="{{ old('contact_name') }}"
                                        autocomplete="name"
                                        required
                                        maxlength="120"
                                        class="mt-2 block w-full border-marketing-line px-3.5 py-3 text-sm shadow-none focus:border-marketing-green-dark focus:ring-marketing-green-dark"
                                    >
                                    @error('contact_name')
                                        <p class="mt-1.5 text-sm text-marketing-coral">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="email" class="block text-sm font-extrabold text-marketing-ink">E-mailadres</label>
                                    <input
                                        id="email"
                                        name="email"
                                        type="email"
                                        value="{{ old('email') }}"
                                        autocomplete="email"
                                        required
                                        maxlength="254"
                                        class="mt-2 block w-full border-marketing-line px-3.5 py-3 text-sm shadow-none focus:border-marketing-green-dark focus:ring-marketing-green-dark"
                                    >
                                    @error('email')
                                        <p class="mt-1.5 text-sm text-marketing-coral">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <label for="phone" class="block text-sm font-extrabold text-marketing-ink">
                                        Telefoon <span class="font-normal text-marketing-muted">(optioneel)</span>
                                    </label>
                                    <input
                                        id="phone"
                                        name="phone"
                                        type="tel"
                                        value="{{ old('phone') }}"
                                        autocomplete="tel"
                                        maxlength="40"
                                        class="mt-2 block w-full border-marketing-line px-3.5 py-3 text-sm shadow-none focus:border-marketing-green-dark focus:ring-marketing-green-dark"
                                    >
                                    @error('phone')
                                        <p class="mt-1.5 text-sm text-marketing-coral">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div>
                                <label for="message" class="block text-sm font-extrabold text-marketing-ink">
                                    Waar loopt het nu vooral vast? <span class="font-normal text-marketing-muted">(optioneel)</span>
                                </label>
                                <textarea
                                    id="message"
                                    name="message"
                                    rows="3"
                                    maxlength="1500"
                                    class="mt-2 block w-full border-marketing-line px-3.5 py-3 text-sm shadow-none focus:border-marketing-green-dark focus:ring-marketing-green-dark"
                                >{{ old('message') }}</textarea>
                                @error('message')
                                    <p class="mt-1.5 text-sm text-marketing-coral">{{ $message }}</p>
                                @enderror
                            </div>

                                <button
                                    type="submit"
                                    class="inline-flex min-h-[52px] w-full items-center justify-center bg-marketing-amber px-6 text-base font-extrabold text-marketing-ink transition hover:brightness-105 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-marketing-green-dark"
                                >
                                    Ik wil een pilot proberen
                                </button>
                                <p class="text-xs leading-relaxed text-marketing-muted">
                                    We gebruiken je gegevens alleen om contact op te nemen over Digitale Opname en verwijderen de inzending uiterlijk na twaalf maanden.
                                </p>
                            </form>
                        @endif
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-marketing-line bg-marketing-paper px-5 py-8 sm:px-8">
            <div class="mx-auto flex max-w-7xl flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-extrabold text-marketing-green-dark">Digitale Opname</p>
                    <p class="mt-1 text-xs text-marketing-muted">Minder heen-en-weer. Meer tijd voor installeren.</p>
                </div>
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm font-bold text-marketing-muted">
                    <a href="#werkwijze" class="hover:text-marketing-ink">Zo werkt het</a>
                    <a href="#product" class="hover:text-marketing-ink">Bekijk de app</a>
                    <a href="#interesse" class="hover:text-marketing-ink">Pilot</a>
                    @guest
                        <a href="{{ route('login') }}" class="hover:text-marketing-ink">Inloggen</a>
                    @endguest
                </div>
            </div>
        </footer>
    </body>
</html>
