<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Digitale Opname bundelt woningdata, foto’s en technische routes in één beslisdossier voor airco-installateurs.">

        <title>Digitale Opname — sneller van aanvraag naar airco-offerte</title>

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
                        Van foto’s naar een controleerbaar installatievoorstel
                    </h1>
                    <p class="home-reveal home-reveal-delay-2 mt-5 max-w-xl text-base leading-relaxed text-white/75 sm:text-lg">
                        Laat de klant gericht fotograferen of voer de opname zelf uit. Bekende woningdata, beeldbewijs, posities en routes komen samen in één dossier waarmee u sneller kunt offreren en voorbereiden.
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
                                            Probeer de interactieve demo
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
                                    De echte installateurswerkplek, vooraf gevuld met een volledig fictieve opname · geen account nodig · automatisch verwijderd na {{ max(1, (int) config('intake.demo.ttl_hours', 2)) }} uur.
                                </p>
                            @endif
                        @endguest
                    </div>
                </div>
            </section>

            <section id="productvoorbeeld" class="bg-white px-5 py-20 sm:px-8 sm:py-28">
                <div class="mx-auto max-w-6xl">
                    <div class="max-w-2xl">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-sea">In één oogopslag</p>
                        <h2 class="mt-3 font-display text-3xl font-semibold tracking-tight text-brand-ink sm:text-4xl">
                            Geen vragenlijst, maar een beslisdossier
                        </h2>
                        <p class="mt-4 text-lg leading-relaxed text-brand-ink/70">
                            Dit zijn echte onderdelen van de installateurswerkplek. In de interactieve demo kunt u hetzelfde dossier zelf bekijken en aanpassen.
                        </p>
                    </div>

                    <div class="mt-12 grid gap-6 lg:grid-cols-3">
                        <article class="overflow-hidden rounded-3xl border border-brand-fog bg-brand-mist shadow-sm">
                            <div class="border-b border-brand-fog bg-white px-5 py-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sea">Woningcontext</p>
                                <h3 class="mt-1 text-lg font-semibold text-brand-ink">Bekende data staat al klaar</h3>
                            </div>
                            <div class="space-y-3 p-5">
                                <div class="relative aspect-[16/10] overflow-hidden rounded-2xl bg-gradient-to-br from-emerald-100 via-stone-100 to-sky-100">
                                    <div class="absolute left-[18%] top-[20%] h-[52%] w-[58%] rotate-[-6deg] rounded-xl border-2 border-brand-sea bg-white/55 shadow-sm"></div>
                                    <div class="absolute bottom-3 left-3 rounded-full bg-white/90 px-3 py-1 text-xs font-semibold text-brand-deep shadow-sm">Fictieve luchtfoto</div>
                                </div>
                                <dl class="grid grid-cols-2 gap-2 text-sm">
                                    <div class="rounded-xl bg-white px-3 py-2">
                                        <dt class="text-xs text-brand-ink/55">Bouwjaar</dt>
                                        <dd class="font-semibold text-brand-ink">1996</dd>
                                    </div>
                                    <div class="rounded-xl bg-white px-3 py-2">
                                        <dt class="text-xs text-brand-ink/55">Oppervlakte</dt>
                                        <dd class="font-semibold text-brand-ink">118 m²</dd>
                                    </div>
                                    <div class="rounded-xl bg-white px-3 py-2">
                                        <dt class="text-xs text-brand-ink/55">Energielabel</dt>
                                        <dd class="font-semibold text-brand-ink">B</dd>
                                    </div>
                                    <div class="rounded-xl bg-white px-3 py-2">
                                        <dt class="text-xs text-brand-ink/55">Verdiepingen</dt>
                                        <dd class="font-semibold text-brand-ink">3</dd>
                                    </div>
                                </dl>
                            </div>
                        </article>

                        <article class="overflow-hidden rounded-3xl border border-brand-fog bg-brand-mist shadow-sm">
                            <div class="border-b border-brand-fog bg-white px-5 py-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sea">Installatievoorstel</p>
                                <h3 class="mt-1 text-lg font-semibold text-brand-ink">Drie verbindingen, apart beoordeeld</h3>
                            </div>
                            <div class="space-y-3 p-5">
                                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="font-semibold text-brand-ink">Optie A · één multi-split</p>
                                        <span class="rounded-full bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white">Geselecteerd</span>
                                    </div>
                                    <p class="mt-2 text-sm leading-relaxed text-brand-ink/65">Twee bovenruimtes via één buitenunit op de achteraanbouw.</p>
                                </div>
                                @foreach ([
                                    ['Koelleiding', 'Aannemelijk', 'bg-sky-500'],
                                    ['Condensafvoer', 'Aannemelijk', 'bg-cyan-500'],
                                    ['Stroomtoevoer', 'Controleren', 'bg-amber-500'],
                                ] as [$label, $status, $color])
                                    <div class="flex items-center gap-3 rounded-xl bg-white px-3 py-3 text-sm">
                                        <span class="h-2.5 w-2.5 rounded-full {{ $color }}"></span>
                                        <span class="flex-1 font-medium text-brand-ink">{{ $label }}</span>
                                        <span class="text-xs text-brand-ink/55">{{ $status }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </article>

                        <article class="overflow-hidden rounded-3xl border border-brand-fog bg-brand-mist shadow-sm">
                            <div class="border-b border-brand-fog bg-white px-5 py-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sea">Beoordelen op uitzonderingen</p>
                                <h3 class="mt-1 text-lg font-semibold text-brand-ink">Alleen vragen wat nog beslist</h3>
                            </div>
                            <div class="space-y-4 p-5">
                                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">Eén open punt</p>
                                    <p class="mt-2 font-semibold text-brand-ink">Groepsaanduiding deels onleesbaar</p>
                                    <p class="mt-1 text-sm leading-relaxed text-brand-ink/65">De rest van de stroomroute is voldoende onderbouwd.</p>
                                </div>
                                <div class="rounded-2xl bg-white p-4 shadow-sm">
                                    <p class="text-xs font-semibold text-brand-sea">Gerichte klanttaak</p>
                                    <p class="mt-2 text-sm font-medium leading-relaxed text-brand-ink">“Maak één frontale foto waarop alle groepslabels scherp leesbaar zijn.”</p>
                                    <div class="mt-4 rounded-xl bg-brand-deep px-4 py-3 text-center text-sm font-semibold text-white">Klantweergave activeren</div>
                                </div>
                            </div>
                        </article>
                    </div>

                    @guest
                        @if (config('intake.demo.enabled'))
                            <form method="POST" action="{{ route('demo.start') }}" class="mt-10">
                                @csrf
                                <button type="submit" class="inline-flex min-h-12 items-center justify-center rounded-md bg-brand-ember px-6 text-base font-semibold text-white transition hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-sea">
                                    Open de echte werkplek
                                </button>
                            </form>
                        @endif
                    @endguest
                </div>
            </section>

            <section id="hoe-het-werkt" class="bg-brand-sand px-5 py-20 sm:px-8 sm:py-28">
                <div class="mx-auto max-w-6xl">
                    <h2 class="font-display text-3xl font-semibold tracking-tight text-brand-ink sm:text-4xl">
                        Hoe het werkt
                    </h2>
                    <p class="mt-3 max-w-2xl text-lg text-brand-ink/70">
                        De opname bepaalt wat nodig is; klant en installateur kunnen ieder bewijs toevoegen.
                    </p>

                    <ol class="mt-14 space-y-12 border-l border-brand-fog pl-8 sm:space-y-14">
                        <li class="relative">
                            <span class="absolute -left-[2.4rem] flex h-7 w-7 items-center justify-center rounded-full bg-brand-sea text-sm font-semibold text-white" aria-hidden="true">1</span>
                            <h3 class="text-xl font-semibold text-brand-ink">Jij start een opname</h3>
                            <p class="mt-2 max-w-xl text-brand-ink/70">
                                Kies of de klant de opname uitvoert, of open direct de mobiele installateurswerkplek en leg alles zelf vast.
                            </p>
                        </li>
                        <li class="relative">
                            <span class="absolute -left-[2.4rem] flex h-7 w-7 items-center justify-center rounded-full bg-brand-sea text-sm font-semibold text-white" aria-hidden="true">2</span>
                            <h3 class="text-xl font-semibold text-brand-ink">Bekende gegevens en foto’s komen samen</h3>
                            <p class="mt-2 max-w-xl text-brand-ink/70">
                                BAG, luchtfoto, EP-Online en 3DBAG vullen de basis. Met uw hulp kunnen we sneller uw airco plaatsen: de klant laat alleen zien wat niet op afstand bekend is.
                            </p>
                        </li>
                        <li class="relative">
                            <span class="absolute -left-[2.4rem] flex h-7 w-7 items-center justify-center rounded-full bg-brand-sea text-sm font-semibold text-white" aria-hidden="true">3</span>
                            <h3 class="text-xl font-semibold text-brand-ink">Jij controleert en beslist</h3>
                            <p class="mt-2 max-w-xl text-brand-ink/70">
                                Beoordeel posities en koel-, condens- en stroomroutes als geheel. Vraag alleen een beslissend detail na en plan pas bij echte onzekerheid een bezoek.
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
