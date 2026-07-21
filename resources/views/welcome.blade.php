<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Digitale Opname helpt installatiebedrijven om aanvragen op afstand te beoordelen via een begeleide intake met foto’s.">

        <title>Digitale Opname — intake op afstand voor installateurs</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700|fraunces:500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            .home-hero {
                background:
                    radial-gradient(ellipse 80% 60% at 70% 20%, rgba(26, 107, 122, 0.22), transparent 55%),
                    radial-gradient(ellipse 50% 40% at 10% 80%, rgba(196, 92, 38, 0.12), transparent 50%),
                    linear-gradient(165deg, #0f1c24 0%, #0d3d47 48%, #1a4f5c 100%);
            }

            .home-grid {
                background-image:
                    linear-gradient(rgba(255, 255, 255, 0.04) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(255, 255, 255, 0.04) 1px, transparent 1px);
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
                .home-reveal {
                    animation: none;
                }
            }
        </style>
    </head>
    <body class="font-sans antialiased text-brand-ink bg-brand-sand">
        <header class="absolute inset-x-0 top-0 z-20">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-5 py-5 sm:px-8">
                <a href="{{ url('/') }}" class="font-display text-lg font-semibold tracking-tight text-white sm:text-xl">
                    Digitale Opname
                </a>

                <nav class="flex items-center gap-2 sm:gap-3" aria-label="Hoofdnavigatie">
                    @auth
                        <a
                            href="{{ route('dashboard') }}"
                            class="rounded-md bg-white px-4 py-2 text-sm font-semibold text-brand-deep transition hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-brand-deep"
                        >
                            Naar opnames
                        </a>
                    @else
                        <a
                            href="{{ route('login') }}"
                            class="rounded-md px-3 py-2 text-sm font-medium text-white/90 transition hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
                        >
                            Inloggen
                        </a>
                        @if (Route::has('register'))
                            <a
                                href="{{ route('register') }}"
                                class="rounded-md bg-white px-4 py-2 text-sm font-semibold text-brand-deep transition hover:bg-brand-mist focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-brand-deep"
                            >
                                Account aanmaken
                            </a>
                        @endif
                    @endauth
                </nav>
            </div>
        </header>

        <main>
            <section class="home-hero relative min-h-[100svh] overflow-hidden text-white">
                <div class="home-grid absolute inset-0 opacity-60" aria-hidden="true"></div>
                <div class="relative mx-auto flex min-h-[100svh] max-w-6xl flex-col justify-end px-5 pb-16 pt-28 sm:px-8 sm:pb-24 lg:justify-center lg:pb-28 lg:pt-24">
                    <p class="home-reveal font-display text-4xl font-semibold tracking-tight text-white sm:text-5xl lg:text-6xl">
                        Digitale Opname
                    </p>
                    <h1 class="home-reveal home-reveal-delay mt-5 max-w-2xl text-2xl font-medium leading-snug text-white/95 sm:text-3xl lg:text-4xl">
                        Beoordeel installatieaanvragen op afstand
                    </h1>
                    <p class="home-reveal home-reveal-delay-2 mt-5 max-w-xl text-base leading-relaxed text-white/75 sm:text-lg">
                        Stuur de klant een persoonlijke link. Zij doorlopen een begeleide intake met gerichte vragen en foto’s. Jij krijgt een helder dossier om te bepalen of een locatiebezoek nog nodig is.
                    </p>

                    <div class="home-reveal home-reveal-delay-2 mt-10 flex flex-col gap-4">
                        <div class="flex flex-wrap items-center gap-4">
                            @guest
                                @if (config('intake.demo.enabled'))
                                    <form method="POST" action="{{ route('demo.start') }}">
                                        @csrf
                                        <button
                                            type="submit"
                                            class="inline-flex min-h-12 items-center justify-center rounded-md bg-brand-ember px-6 text-base font-semibold text-white transition hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                                        >
                                            Start demo
                                        </button>
                                    </form>
                                @endif
                                <a
                                    href="{{ route('login') }}"
                                    class="inline-flex min-h-12 items-center justify-center rounded-md {{ config('intake.demo.enabled') ? 'border border-white/35 px-5 font-medium' : 'bg-brand-ember px-6 font-semibold' }} text-base text-white transition hover:border-white/70 hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                                >
                                    Inloggen
                                </a>
                            @else
                                <a
                                    href="{{ route('dashboard') }}"
                                    class="inline-flex min-h-12 items-center justify-center rounded-md bg-brand-ember px-6 text-base font-semibold text-white transition hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                                >
                                    Open dashboard
                                </a>
                            @endguest
                            <a
                                href="#hoe-het-werkt"
                                class="inline-flex min-h-12 items-center justify-center rounded-md border border-white/35 px-5 text-base font-medium text-white transition hover:border-white/70 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
                            >
                                Hoe het werkt
                            </a>
                        </div>
                        @guest
                            @if (config('intake.demo.enabled'))
                                <p class="max-w-lg text-sm leading-relaxed text-white/60">
                                    Demo van de klantflow (vragen + foto’s + AI-samenvatting) · geen account nodig · verdwijnt na {{ (int) config('intake.demo.ttl_hours', 12) }} uur.
                                    In de demo uitgeschakeld: e-mail, PDF en installateursdashboard — toegelicht in de demo zelf.
                                </p>
                            @endif
                        @endguest
                    </div>
                </div>
            </section>

            <section id="hoe-het-werkt" class="bg-brand-sand px-5 py-20 sm:px-8 sm:py-28">
                <div class="mx-auto max-w-6xl">
                    <h2 class="font-display text-3xl font-semibold tracking-tight text-brand-ink sm:text-4xl">
                        Hoe het werkt
                    </h2>
                    <p class="mt-3 max-w-2xl text-lg text-brand-ink/70">
                        Van aanvraag tot intern rapport — zonder meteen langs te moeten.
                    </p>

                    <ol class="mt-14 space-y-12 border-l border-brand-fog pl-8 sm:space-y-14">
                        <li class="relative">
                            <span class="absolute -left-[2.4rem] flex h-7 w-7 items-center justify-center rounded-full bg-brand-sea text-sm font-semibold text-white" aria-hidden="true">1</span>
                            <h3 class="text-xl font-semibold text-brand-ink">Jij start een opname</h3>
                            <p class="mt-2 max-w-xl text-brand-ink/70">
                                Vul klantgegevens in en krijg een unieke, beveiligde link. Deel die met de klant — automatisch mailen volgt later.
                            </p>
                        </li>
                        <li class="relative">
                            <span class="absolute -left-[2.4rem] flex h-7 w-7 items-center justify-center rounded-full bg-brand-sea text-sm font-semibold text-white" aria-hidden="true">2</span>
                            <h3 class="text-xl font-semibold text-brand-ink">De klant doorloopt de intake</h3>
                            <p class="mt-2 max-w-xl text-brand-ink/70">
                                Stap voor stap op de telefoon: vragen, foto’s van ruimtes, buitenunit en meterkast. Antwoorden worden tussentijds opgeslagen.
                            </p>
                        </li>
                        <li class="relative">
                            <span class="absolute -left-[2.4rem] flex h-7 w-7 items-center justify-center rounded-full bg-brand-sea text-sm font-semibold text-white" aria-hidden="true">3</span>
                            <h3 class="text-xl font-semibold text-brand-ink">Jij beoordeelt het dossier</h3>
                            <p class="mt-2 max-w-xl text-brand-ink/70">
                                Bekijk antwoorden en foto’s, zie wat nog ontbreekt, en bepaal of een offerte of locatiebezoek nodig is.
                            </p>
                        </li>
                    </ol>
                </div>
            </section>
        </main>

        <footer class="border-t border-brand-fog/80 bg-brand-mist px-5 py-8 sm:px-8">
            <div class="mx-auto flex max-w-6xl flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <p class="font-display text-sm font-semibold text-brand-deep">Digitale Opname</p>
                <p class="text-sm text-brand-ink/55">Voor installatiebedrijven · eerste template: airco-opname</p>
            </div>
        </footer>
    </body>
</html>
