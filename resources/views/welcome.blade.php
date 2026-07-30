<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta
            name="description"
            content="Laat klanten precies de juiste foto’s maken en zie sneller of je een airco-offerte kunt maken. Minder heen-en-weer, minder onnodige voorbezoeken."
        >
        <meta property="og:type" content="website">
        <meta property="og:locale" content="nl_NL">
        <meta property="og:title" content="Digitale Opname voor airco-installateurs">
        <meta
            property="og:description"
            content="Geen voorbezoek voor één ontbrekende foto. De klant krijgt duidelijke opdrachten; jij ziet wat er kan."
        >
        <meta property="og:url" content="{{ route('home') }}">

        <title>Digitale Opname voor airco-installateurs</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            .home-hero {
                background:
                    radial-gradient(ellipse 72% 58% at 76% 28%, rgba(0, 113, 227, 0.23), transparent 58%),
                    radial-gradient(ellipse 46% 42% at 8% 86%, rgba(180, 35, 24, 0.15), transparent 56%),
                    linear-gradient(152deg, #101d25 0%, #0b3540 52%, #125766 100%);
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
    <body class="bg-white font-sans text-brand-ink antialiased">
        <header class="absolute inset-x-0 top-0 z-30">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-5 py-5 sm:px-8">
                <a
                    href="{{ route('home') }}"
                    class="font-display text-lg font-semibold tracking-tight text-white sm:text-xl"
                    aria-label="Digitale Opname homepage"
                >
                    Digitale Opname
                </a>

                <nav class="hidden items-center gap-7 lg:flex" aria-label="Hoofdnavigatie">
                    <a class="text-sm font-medium text-white/75 transition hover:text-white" href="#werkwijze">Zo werkt het</a>
                    <a class="text-sm font-medium text-white/75 transition hover:text-white" href="#voordelen">Voordelen</a>
                    <a class="text-sm font-medium text-white/75 transition hover:text-white" href="#product">Bekijk de app</a>
                    <a class="text-sm font-medium text-white/75 transition hover:text-white" href="#vragen">Vragen</a>
                </nav>

                <div class="flex items-center gap-2 sm:gap-3">
                    @auth
                        <a
                            href="{{ route('dashboard') }}"
                            class="inline-flex min-h-10 items-center justify-center rounded-lg bg-white px-4 text-sm font-semibold text-brand-deep transition hover:bg-brand-mist"
                        >
                            Naar opnames
                        </a>
                    @else
                        <a
                            href="{{ route('login') }}"
                            class="hidden rounded-lg px-3 py-2 text-sm font-medium text-white/80 transition hover:text-white sm:inline-flex"
                        >
                            Inloggen
                        </a>
                        <a
                            href="#interesse"
                            class="inline-flex min-h-10 items-center justify-center rounded-lg bg-white px-4 text-sm font-semibold text-brand-deep transition hover:bg-brand-mist"
                        >
                            Ik wil een pilot
                        </a>
                    @endauth
                </div>
            </div>
        </header>

        <main>
            <section class="home-hero relative overflow-hidden text-white">
                <div class="home-grid absolute inset-0 opacity-60" aria-hidden="true"></div>
                <div class="relative mx-auto grid min-h-[760px] max-w-7xl items-center gap-16 px-5 pb-20 pt-32 sm:px-8 lg:grid-cols-[0.9fr_1.1fr] lg:py-32">
                    <div class="max-w-2xl">
                        <p class="home-reveal text-sm font-semibold uppercase tracking-[0.2em] text-cyan-200">
                            Voor airco-installateurs
                        </p>
                        <h1 class="home-reveal home-reveal-delay mt-5 font-display text-4xl font-semibold leading-[1.08] tracking-[-0.035em] text-white sm:text-5xl lg:text-6xl">
                            Geen voorbezoek voor één ontbrekende foto.
                        </h1>
                        <p class="home-reveal home-reveal-delay-2 mt-6 max-w-xl text-lg leading-relaxed text-white/75">
                            De klant krijgt duidelijke foto-opdrachten. Jij ziet alles op één plek en weet sneller: offreren, nog één vraag stellen of toch langsgaan.
                        </p>

                        <div class="home-reveal home-reveal-delay-2 mt-9 flex flex-col gap-3 sm:flex-row sm:items-center">
                            @guest
                                @if (config('intake.demo.enabled'))
                                    <form method="POST" action="{{ route('demo.start') }}">
                                        @csrf
                                        <button
                                            type="submit"
                                            class="inline-flex min-h-[52px] w-full items-center justify-center rounded-lg bg-brand-ember px-6 text-base font-semibold text-white shadow-lg shadow-black/20 transition hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-white sm:w-auto"
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
                                    class="inline-flex min-h-[52px] items-center justify-center rounded-lg border border-white/35 px-6 text-base font-semibold text-white transition hover:border-white/70 hover:bg-white/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                                >
                                    Ik wil een pilot
                                </a>
                            @else
                                <a
                                    href="{{ route('dashboard') }}"
                                    class="inline-flex min-h-13 items-center justify-center rounded-lg bg-brand-ember px-6 text-base font-semibold text-white transition hover:brightness-110"
                                >
                                    Open dashboard
                                </a>
                            @endguest
                        </div>

                        @guest
                            @if (config('intake.demo.enabled'))
                                <div class="home-reveal home-reveal-delay-2 mt-6 flex flex-wrap gap-x-5 gap-y-2 text-sm text-white/60">
                                    @foreach (['Geen account nodig', 'Fictieve aanvraag', 'Jij houdt de regie'] as $item)
                                        <span class="inline-flex items-center gap-2">
                                            <svg class="h-4 w-4 text-cyan-200" viewBox="0 0 16 16" fill="none" aria-hidden="true">
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
                        <div class="absolute -left-6 top-12 hidden h-28 w-28 rounded-full border border-white/10 lg:block" aria-hidden="true"></div>
                        <div class="absolute -right-8 -top-8 hidden h-40 w-40 rounded-full border border-white/10 lg:block" aria-hidden="true"></div>

                        <div class="relative overflow-hidden rounded-2xl border border-white/20 bg-white shadow-2xl shadow-black/30">
                            <div class="flex h-11 items-center gap-2 border-b border-gray-200 bg-gray-50 px-4">
                                <span class="h-2.5 w-2.5 rounded-full bg-red-400"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-amber-400"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                                <span class="ml-3 hidden rounded-md bg-white px-4 py-1 text-[10px] font-medium text-gray-400 sm:block">intake-engine.nl/opname</span>
                                <span class="ml-auto rounded-full bg-cyan-50 px-2.5 py-1 text-[10px] font-semibold text-brand-sea">Fictieve demo</span>
                            </div>

                            <div class="grid min-h-[420px] bg-brand-mist sm:grid-cols-[150px_1fr]">
                                <aside class="hidden border-r border-gray-200 bg-white p-4 sm:block">
                                    <p class="text-xs font-semibold text-brand-ink">Voorbeeldstraat 12</p>
                                    <p class="mt-1 text-[10px] text-gray-400">Airco-opname</p>
                                    <div class="mt-6 space-y-2">
                                        @foreach ([
                                            ['Overzicht', true],
                                            ['Ruimtes', false],
                                            ['Posities', false],
                                            ['Verbindingen', false],
                                            ['Klanttaak', false],
                                        ] as [$label, $active])
                                            <div class="rounded-md px-2.5 py-2 text-[10px] font-medium {{ $active ? 'bg-blue-50 text-brand-sea' : 'text-gray-400' }}">
                                                {{ $label }}
                                            </div>
                                        @endforeach
                                    </div>
                                </aside>

                                <div class="p-4 sm:p-6">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-sea">Opname</p>
                                            <h2 class="mt-1 text-base font-semibold text-brand-ink sm:text-lg">Bijna klaar</h2>
                                        </div>
                                        <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-[10px] font-semibold text-emerald-700">6 klaar · 1 check</span>
                                    </div>

                                    <div class="mt-5 grid gap-3 md:grid-cols-2">
                                        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                                            <div class="landing-photo-bedroom h-28" role="img" aria-label="Fictieve slaapkamerfoto"></div>
                                            <div class="p-3">
                                                <p class="text-[10px] font-semibold text-brand-ink">Slaapkamer ouders</p>
                                                <p class="mt-1 text-[10px] leading-relaxed text-gray-500">Binnenplek en muurdoorgang zichtbaar</p>
                                            </div>
                                        </div>
                                        <div class="space-y-2">
                                            @foreach ([
                                                ['Koelleiding', 'Lijkt goed', 'bg-sky-500'],
                                                ['Condensafvoer', 'Lijkt goed', 'bg-cyan-500'],
                                                ['Stroomtoevoer', 'Nog checken', 'bg-amber-500'],
                                            ] as [$label, $status, $color])
                                                <div class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2.5">
                                                    <span class="h-2 w-2 rounded-full {{ $color }}"></span>
                                                    <span class="flex-1 text-[10px] font-semibold text-brand-ink">{{ $label }}</span>
                                                    <span class="text-[9px] text-gray-400">{{ $status }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="mt-3 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3">
                                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-amber-100 text-xs font-bold text-amber-700">!</span>
                                        <div>
                                            <p class="text-[10px] font-semibold text-amber-900">Nog één ding nodig</p>
                                            <p class="mt-1 text-[10px] leading-relaxed text-amber-800/70">Vraag een scherpe foto van de groepsaanduiding. De rest van het voorstel blijft staan.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="absolute -bottom-8 -left-3 hidden w-48 rounded-xl border border-gray-200 bg-white p-3 shadow-xl sm:block lg:-left-10">
                            <p class="text-[9px] font-semibold uppercase tracking-[0.12em] text-gray-400">Staat al klaar</p>
                            <div class="mt-2 grid grid-cols-3 gap-2 text-center">
                                @foreach ([['1996', 'Bouwjaar'], ['118 m²', 'Oppervlak'], ['B', 'Label']] as [$value, $label])
                                    <div class="rounded-lg bg-brand-mist px-2 py-2">
                                        <p class="text-[11px] font-semibold text-brand-ink">{{ $value }}</p>
                                        <p class="mt-0.5 text-[8px] text-gray-400">{{ $label }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="border-b border-brand-fog bg-white px-5 py-8 sm:px-8">
                <div class="mx-auto flex max-w-7xl flex-col gap-5 text-center sm:flex-row sm:items-center sm:justify-between sm:text-left">
                    <p class="text-sm font-semibold text-brand-ink">De app doet het voorwerk. Jij geeft het vakmatige oordeel.</p>
                    <div class="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm text-brand-ink/55 sm:justify-end">
                        <span>Klant maakt foto’s</span>
                        <span>Alles op één plek</span>
                        <span>Jij beslist</span>
                    </div>
                </div>
            </section>

            <section class="bg-brand-mist px-5 py-16 sm:px-8 sm:py-20">
                <div class="mx-auto max-w-7xl">
                    <div class="grid gap-12 lg:grid-cols-[0.85fr_1.15fr] lg:items-end">
                        <div class="max-w-xl">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-sea">Herkenbaar?</p>
                            <h2 class="mt-4 font-display text-3xl font-semibold tracking-[-0.025em] text-brand-ink sm:text-4xl">
                                Niet ieder voorbezoek is vakwerk.
                            </h2>
                        </div>
                        <p class="max-w-2xl text-lg leading-relaxed text-brand-ink/65">
                            Soms is het vooral een dure manier om één ontbrekende foto te krijgen. Je belt, appt en rijdt alsnog — terwijl de klant het juiste beeld vaak prima zelf kan maken.
                        </p>
                    </div>

                    <div class="mt-10 grid gap-5 md:grid-cols-3">
                        @foreach ([
                            ['01', 'Losse foto’s', 'Jij moet uitzoeken wat je precies ziet.'],
                            ['02', 'Nog een appje', 'Jij legt opnieuw uit wat er ontbreekt.'],
                            ['03', 'Toch in de bus', 'Twijfel maakt een rit al snel de veilige keuze.'],
                        ] as [$number, $title, $copy])
                            <article class="rounded-2xl border border-brand-fog bg-white p-6">
                                <span class="text-xs font-semibold tracking-[0.18em] text-brand-sea">{{ $number }}</span>
                                <h3 class="mt-4 text-xl font-semibold text-brand-ink">{{ $title }}</h3>
                                <p class="mt-2 text-base leading-relaxed text-brand-ink/60">{{ $copy }}</p>
                            </article>
                        @endforeach
                    </div>

                    <div class="mt-8 rounded-2xl bg-brand-deep px-6 py-6 text-white sm:flex sm:items-center sm:justify-between sm:gap-8 sm:px-8">
                        <p class="font-display text-xl font-semibold sm:text-2xl">Vraag eerder het juiste.</p>
                        <p class="mt-2 max-w-xl text-sm leading-relaxed text-white/65 sm:mt-0 sm:text-right">In plaats van later nóg iets.</p>
                    </div>
                </div>
            </section>

            <section id="werkwijze" class="scroll-mt-6 bg-white px-5 py-16 sm:px-8 sm:py-20">
                <div class="mx-auto max-w-7xl">
                    <div class="max-w-2xl">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-sea">Zo werkt het</p>
                        <h2 class="mt-4 font-display text-3xl font-semibold tracking-[-0.025em] text-brand-ink sm:text-4xl">
                            Van aanvraag naar duidelijkheid in drie stappen
                        </h2>
                        <p class="mt-4 text-lg leading-relaxed text-brand-ink/65">
                            Laat de klant de opname doen, doe hem zelf of combineer beide. Alles komt in hetzelfde overzicht.
                        </p>
                    </div>

                    <ol class="mt-10 grid gap-px overflow-hidden rounded-2xl border border-brand-fog bg-brand-fog lg:grid-cols-3">
                        @foreach ([
                            ['1', 'De klant maakt de juiste foto’s', 'Geen open vraag als “stuur wat foto’s”, maar één duidelijke opdracht per keer.'],
                            ['2', 'Alles staat op één plek', 'Foto’s, woninggegevens en mogelijke leidingroutes staan netjes bij elkaar.'],
                            ['3', 'Jij ziet wat de volgende stap is', 'Offreren, één detail opvragen of toch langsgaan. Jij houdt de regie.'],
                        ] as [$number, $title, $copy])
                            <li class="bg-white p-6 sm:p-7">
                                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-sea text-sm font-semibold text-white">{{ $number }}</span>
                                <h3 class="mt-6 text-lg font-semibold text-brand-ink">{{ $title }}</h3>
                                <p class="mt-3 text-sm leading-relaxed text-brand-ink/60">{{ $copy }}</p>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </section>

            <section id="voordelen" class="scroll-mt-6 bg-[#EAF3F5] px-5 py-16 sm:px-8 sm:py-20">
                <div class="mx-auto max-w-7xl">
                    <div class="max-w-2xl">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-sea">Wat levert het op?</p>
                        <h2 class="mt-4 font-display text-3xl font-semibold tracking-[-0.025em] text-brand-ink sm:text-4xl">
                            Rust in je planning. Duidelijkheid voor je klant.
                        </h2>
                    </div>

                    <div class="mt-10 grid gap-6 lg:grid-cols-2">
                        <article class="rounded-3xl bg-brand-deep p-7 text-white sm:p-10">
                            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-cyan-200">Voor jou</p>
                            <h3 class="mt-4 font-display text-2xl font-semibold sm:text-3xl">Minder gedoe vóór je kunt offreren</h3>
                            <ul class="mt-8 space-y-4">
                                @foreach ([
                                    'Minder nabellen en appen voor ontbrekende informatie',
                                    'Sneller zien of je veilig kunt offreren',
                                    'Beter voorbereid naar de montage',
                                ] as $item)
                                    <li class="flex gap-4">
                                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-cyan-200 text-brand-deep">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                                <path d="m3 8.2 3.1 3.1L13 4.8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        </span>
                                        <p class="font-semibold leading-relaxed">{{ $item }}</p>
                                    </li>
                                @endforeach
                            </ul>
                        </article>

                        <article class="rounded-3xl border border-white bg-white p-7 sm:p-10">
                            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-brand-ember">Voor je klant</p>
                            <h3 class="mt-4 font-display text-2xl font-semibold text-brand-ink sm:text-3xl">Precies weten wat er nodig is</h3>
                            <ul class="mt-8 space-y-4">
                                @foreach ([
                                    'Eén simpele foto-opdracht tegelijk',
                                    'Geen technische keuzes hoeven maken',
                                    'Sneller duidelijkheid en minder afspraken',
                                ] as $item)
                                    <li class="flex gap-4">
                                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-red-50 text-brand-ember">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                                <path d="m3 8.2 3.1 3.1L13 4.8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        </span>
                                        <p class="font-semibold leading-relaxed text-brand-ink">{{ $item }}</p>
                                    </li>
                                @endforeach
                            </ul>
                        </article>
                    </div>
                </div>
            </section>

            <section id="product" class="scroll-mt-6 overflow-hidden bg-white px-5 py-16 sm:px-8 sm:py-20">
                <div class="mx-auto max-w-7xl">
                    <div class="grid gap-10 lg:grid-cols-[0.72fr_1.28fr] lg:items-end">
                        <div class="max-w-xl">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-sea">In één oogopslag</p>
                            <h2 class="mt-4 font-display text-3xl font-semibold tracking-[-0.025em] text-brand-ink sm:text-4xl">
                                Zie wat klaar is — en wat nog mist
                            </h2>
                        </div>
                        <p class="max-w-2xl text-lg leading-relaxed text-brand-ink/65">
                            Geen speurtocht door WhatsApp, e-mail en losse notities. Je opent de aanvraag en ziet meteen waar je aan toe bent.
                        </p>
                    </div>

                    <div class="mt-12 grid gap-8 lg:grid-cols-[1fr_320px] lg:items-center">
                        <div class="overflow-hidden rounded-2xl border border-brand-fog bg-brand-mist shadow-xl shadow-gray-200/70">
                            <div class="flex items-center gap-2 border-b border-brand-fog bg-white px-4 py-3">
                                <span class="h-2.5 w-2.5 rounded-full bg-red-400"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-amber-400"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                                <span class="ml-3 text-xs font-medium text-brand-ink/40">Opname · Voorbeeldstraat 12</span>
                            </div>

                            <div class="p-4 sm:p-7">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-sea">Voorstel</p>
                                        <h3 class="mt-1 text-xl font-semibold text-brand-ink">Optie A · één multi-split</h3>
                                    </div>
                                    <span class="w-fit rounded-full bg-emerald-100 px-3 py-1.5 text-xs font-semibold text-emerald-700">Voorkeursoptie</span>
                                </div>

                                <div class="mt-6 grid gap-4 md:grid-cols-2">
                                    @foreach ([
                                        ['Koelleiding slaapkamer ouders', 'Binnenwand → overloop → achtergevel', 'Lijkt goed', 'sky'],
                                        ['Condensafvoer slaapkamer ouders', 'Binnenpositie → bestaande afvoer', 'Lijkt goed', 'cyan'],
                                        ['Koelleiding werkkamer', 'Binnenwand → achtergevel', 'Lijkt goed', 'sky'],
                                        ['Stroom naar buitenunit', 'Meterkast → kruipruimte → achteraanbouw', 'Nog checken', 'amber'],
                                    ] as [$title, $route, $status, $tone])
                                        <article class="rounded-xl border {{ $tone === 'amber' ? 'border-amber-200 bg-amber-50' : 'border-gray-200 bg-white' }} p-4">
                                            <div class="flex items-start justify-between gap-3">
                                                <p class="text-sm font-semibold text-brand-ink">{{ $title }}</p>
                                                <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $tone === 'amber' ? 'bg-amber-500' : ($tone === 'cyan' ? 'bg-cyan-500' : 'bg-sky-500') }}"></span>
                                            </div>
                                            <p class="mt-2 text-xs leading-relaxed text-brand-ink/55">{{ $route }}</p>
                                            <p class="mt-4 text-[11px] font-semibold {{ $tone === 'amber' ? 'text-amber-700' : 'text-emerald-700' }}">{{ $status }}</p>
                                        </article>
                                    @endforeach
                                </div>

                                <div class="mt-4 grid gap-4 sm:grid-cols-[180px_1fr]">
                                    <div class="landing-photo-fusebox min-h-40 rounded-xl" role="img" aria-label="Fictieve foto van een meterkast"></div>
                                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-5">
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-amber-700">Nog nodig</p>
                                        <h4 class="mt-2 font-semibold text-brand-ink">Eén scherpere foto</h4>
                                        <p class="mt-2 text-sm leading-relaxed text-brand-ink/60">
                                            De groepenkast staat erop, maar het label is niet goed leesbaar. Vraag alleen dit detail opnieuw.
                                        </p>
                                        <div class="mt-4 inline-flex rounded-lg bg-brand-deep px-4 py-2.5 text-xs font-semibold text-white">Vraag deze foto opnieuw</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mx-auto w-full max-w-[300px] rounded-[2.4rem] border-[8px] border-brand-deep bg-brand-deep p-1 shadow-xl shadow-gray-300/70">
                            <div class="overflow-hidden rounded-[1.85rem] bg-white">
                                <div class="flex h-8 items-center justify-center bg-brand-deep">
                                    <span class="h-1.5 w-16 rounded-full bg-white/25"></span>
                                </div>
                                <div class="px-5 pb-6 pt-5">
                                    <div class="flex items-center justify-between">
                                        <p class="text-xs font-semibold text-brand-deep">Digitale Opname</p>
                                        <span class="text-[10px] text-brand-ink/40">1 van 1</span>
                                    </div>
                                    <div class="mt-5 h-1.5 overflow-hidden rounded-full bg-gray-100">
                                        <div class="h-full w-2/3 rounded-full bg-brand-sea"></div>
                                    </div>
                                    <p class="mt-6 text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-sea">Meterkast</p>
                                    <h3 class="mt-2 text-lg font-semibold leading-snug text-brand-ink">Maak één frontale foto van alle groepslabels</h3>
                                    <p class="mt-3 text-xs leading-relaxed text-brand-ink/55">
                                        Houd de telefoon recht en zorg dat de tekst scherp leesbaar is. Je hoeft niets open te maken.
                                    </p>
                                    <div class="landing-photo-fusebox mt-5 h-40 rounded-xl" role="img" aria-label="Fictief fotovoorbeeld van een meterkast"></div>
                                    <div class="mt-4 rounded-lg bg-brand-sea px-4 py-3 text-center text-xs font-semibold text-white">Foto toevoegen</div>
                                    <p class="mt-3 text-center text-[10px] leading-relaxed text-brand-ink/40">Geen technische keuze nodig</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <p class="mt-5 text-xs leading-relaxed text-brand-ink/45">
                        Fictieve voorbeeldopname. De demo gebruikt geen echte klantdata.
                    </p>
                </div>
            </section>

            <section id="demo" class="bg-brand-deep px-5 py-16 text-white sm:px-8 sm:py-20">
                <div class="mx-auto flex max-w-7xl flex-col gap-8 lg:flex-row lg:items-center lg:justify-between">
                    <div class="max-w-2xl">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-cyan-200">Zelf even proberen</p>
                        <h2 class="mt-4 font-display text-3xl font-semibold tracking-[-0.025em] sm:text-4xl">
                            Klik door een voorbeeldopname
                        </h2>
                        <p class="mt-4 text-lg leading-relaxed text-white/65">
                            Bekijk wat al klaarstaat, vind het ene ontbrekende detail en open de foto-opdracht voor de klant. Zonder account.
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col gap-3 sm:flex-row">
                        @guest
                            @if (config('intake.demo.enabled'))
                                <form method="POST" action="{{ route('demo.start') }}">
                                    @csrf
                                    <button
                                        type="submit"
                                        class="inline-flex min-h-[52px] w-full items-center justify-center rounded-lg bg-brand-ember px-6 text-base font-semibold text-white transition hover:brightness-110 sm:w-auto"
                                    >
                                        Probeer de demo
                                    </button>
                                </form>
                            @endif
                            <a
                                href="#interesse"
                                class="inline-flex min-h-[52px] items-center justify-center rounded-lg border border-white/35 px-6 text-base font-semibold text-white transition hover:border-white/70"
                            >
                                Ik wil een pilot
                            </a>
                        @else
                            <a
                                href="{{ route('dashboard') }}"
                                class="inline-flex min-h-[52px] items-center justify-center rounded-lg bg-brand-ember px-6 text-base font-semibold text-white transition hover:brightness-110"
                            >
                                Open dashboard
                            </a>
                        @endguest
                    </div>
                </div>
            </section>

            <section id="vragen" class="scroll-mt-6 bg-white px-5 py-16 sm:px-8 sm:py-20">
                <div class="mx-auto grid max-w-7xl gap-12 lg:grid-cols-[0.62fr_1.38fr]">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-sea">Goed om te weten</p>
                        <h2 class="mt-4 font-display text-3xl font-semibold tracking-[-0.025em] text-brand-ink sm:text-4xl">
                            De app doet het voorwerk. Niet jouw vakwerk.
                        </h2>
                    </div>

                    <div class="divide-y divide-brand-fog border-y border-brand-fog">
                        @foreach ([
                            ['Vervangt dit elk voorbezoek?', 'Nee. Is iets niet veilig op afstand te beoordelen? Dan ga je gewoon langs. De app helpt vooral de ritten voorkomen die alleen nodig zijn om informatie te verzamelen.'],
                            ['Beslist de app of AI wat er moet komen?', 'Nee. De app zet de informatie op een rij en laat zien waar nog twijfel zit. Jij kiest de oplossing en geeft groen licht.'],
                            ['Wat als de klant een verkeerde foto maakt?', 'Dan krijgt de klant één nieuwe, duidelijke opdracht. Niet de hele opname opnieuw.'],
                            ['Kan ik de opname ook zelf doen?', 'Ja. Laat de klant foto’s maken, doe het zelf of combineer beide. Alles blijft in dezelfde aanvraag staan.'],
                        ] as [$question, $answer])
                            <details class="group py-5">
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-5 text-base font-semibold text-brand-ink">
                                    {{ $question }}
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-mist text-brand-sea transition group-open:rotate-45" aria-hidden="true">+</span>
                                </summary>
                                <p class="max-w-3xl pr-10 pt-3 text-sm leading-relaxed text-brand-ink/60">{{ $answer }}</p>
                            </details>
                        @endforeach
                    </div>
                </div>
            </section>

            <section id="interesse" class="scroll-mt-6 bg-[#EAF3F5] px-5 py-16 sm:px-8 sm:py-20">
                <div class="mx-auto grid max-w-7xl gap-12 lg:grid-cols-[0.78fr_1.22fr] lg:gap-20">
                    <div class="max-w-xl">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-sea">Klein beginnen</p>
                        <h2 class="mt-4 font-display text-3xl font-semibold tracking-[-0.025em] text-brand-ink sm:text-4xl">
                            Probeer het met een paar echte aanvragen
                        </h2>
                        <p class="mt-5 text-lg leading-relaxed text-brand-ink/65">
                            We kijken waar jij nu tijd verliest en maken daar een kleine pilot van. Daarna beslis je pas of het iets voor je is.
                        </p>

                        <ul class="mt-8 space-y-4 text-sm text-brand-ink/65">
                            @foreach ([
                                'Aansluiten op hoe je nu werkt',
                                'Beginnen met een paar aanvragen',
                                'Alleen doorgaan als het echt tijd scheelt',
                            ] as $item)
                                <li class="flex items-start gap-3">
                                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-brand-sea text-white">
                                        <svg class="h-3 w-3" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                            <path d="m3 8.2 3.1 3.1L13 4.8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </span>
                                    {{ $item }}
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div class="rounded-3xl border border-white bg-white p-6 shadow-sm sm:p-8">
                        @if (session('interest_submitted'))
                            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5" role="status">
                                <div class="flex items-start gap-3">
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white">
                                        <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                            <path d="m3 8.2 3.1 3.1L13 4.8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </span>
                                    <div>
                                        <p class="font-semibold text-emerald-900">Je interesse is ontvangen.</p>
                                        <p class="mt-1 text-sm leading-relaxed text-emerald-800/75">Bedankt. We nemen contact met je op om te kijken of een pilot past.</p>
                                    </div>
                                </div>
                            </div>
                        @else
                            <div>
                                <p class="text-sm font-semibold text-brand-sea">Interesse?</p>
                                <h3 class="mt-1 text-2xl font-semibold text-brand-ink">Dan nemen we contact op</h3>
                            </div>

                            @if ($errors->any())
                                <div class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4" role="alert">
                                    <p class="text-sm font-semibold text-red-800">Controleer de gemarkeerde velden.</p>
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
                                    <label for="company_name" class="block text-sm font-semibold text-brand-ink">Bedrijfsnaam</label>
                                    <input
                                        id="company_name"
                                        name="company_name"
                                        type="text"
                                        value="{{ old('company_name') }}"
                                        autocomplete="organization"
                                        required
                                        maxlength="120"
                                        class="mt-2 block w-full rounded-lg border-brand-fog px-3.5 py-3 text-sm shadow-none focus:border-brand-sea focus:ring-brand-sea"
                                    >
                                    @error('company_name')
                                        <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <label for="contact_name" class="block text-sm font-semibold text-brand-ink">Naam</label>
                                    <input
                                        id="contact_name"
                                        name="contact_name"
                                        type="text"
                                        value="{{ old('contact_name') }}"
                                        autocomplete="name"
                                        required
                                        maxlength="120"
                                        class="mt-2 block w-full rounded-lg border-brand-fog px-3.5 py-3 text-sm shadow-none focus:border-brand-sea focus:ring-brand-sea"
                                    >
                                    @error('contact_name')
                                        <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="email" class="block text-sm font-semibold text-brand-ink">E-mailadres</label>
                                    <input
                                        id="email"
                                        name="email"
                                        type="email"
                                        value="{{ old('email') }}"
                                        autocomplete="email"
                                        required
                                        maxlength="254"
                                        class="mt-2 block w-full rounded-lg border-brand-fog px-3.5 py-3 text-sm shadow-none focus:border-brand-sea focus:ring-brand-sea"
                                    >
                                    @error('email')
                                        <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <label for="phone" class="block text-sm font-semibold text-brand-ink">
                                        Telefoon <span class="font-normal text-brand-ink/45">(optioneel)</span>
                                    </label>
                                    <input
                                        id="phone"
                                        name="phone"
                                        type="tel"
                                        value="{{ old('phone') }}"
                                        autocomplete="tel"
                                        maxlength="40"
                                        class="mt-2 block w-full rounded-lg border-brand-fog px-3.5 py-3 text-sm shadow-none focus:border-brand-sea focus:ring-brand-sea"
                                    >
                                    @error('phone')
                                        <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div>
                                <label for="message" class="block text-sm font-semibold text-brand-ink">
                                    Waar loopt het nu vooral vast? <span class="font-normal text-brand-ink/45">(optioneel)</span>
                                </label>
                                <textarea
                                    id="message"
                                    name="message"
                                    rows="3"
                                    maxlength="1500"
                                    class="mt-2 block w-full rounded-lg border-brand-fog px-3.5 py-3 text-sm shadow-none focus:border-brand-sea focus:ring-brand-sea"
                                >{{ old('message') }}</textarea>
                                @error('message')
                                    <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p>
                                @enderror
                            </div>

                                <button
                                    type="submit"
                                    class="inline-flex min-h-[52px] w-full items-center justify-center rounded-lg bg-brand-ember px-6 text-base font-semibold text-white transition hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-sea focus-visible:ring-offset-2"
                                >
                                    Ik wil een pilot proberen
                                </button>
                                <p class="text-xs leading-relaxed text-brand-ink/45">
                                    We gebruiken je gegevens alleen om contact op te nemen over Digitale Opname en verwijderen de inzending uiterlijk na twaalf maanden.
                                </p>
                            </form>
                        @endif
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-brand-fog bg-white px-5 py-8 sm:px-8">
            <div class="mx-auto flex max-w-7xl flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="font-display text-sm font-semibold text-brand-deep">Digitale Opname</p>
                    <p class="mt-1 text-xs text-brand-ink/45">Minder heen-en-weer. Meer tijd voor installeren.</p>
                </div>
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-brand-ink/55">
                    <a href="#werkwijze" class="hover:text-brand-ink">Zo werkt het</a>
                    <a href="#product" class="hover:text-brand-ink">Bekijk de app</a>
                    <a href="#interesse" class="hover:text-brand-ink">Pilot</a>
                    @guest
                        <a href="{{ route('login') }}" class="hover:text-brand-ink">Inloggen</a>
                    @endguest
                </div>
            </div>
        </footer>
    </body>
</html>
