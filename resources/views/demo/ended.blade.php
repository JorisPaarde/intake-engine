<!DOCTYPE html>
<html lang="nl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Demo beëindigd — Digitale Opname</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-[#F5F5F7] font-sans text-[#1D1D1F] antialiased">
        <main class="mx-auto flex min-h-screen max-w-lg flex-col justify-center px-5 py-12">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sky-700">Digitale Opname</p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight text-gray-950">
                {{ ($reason ?? 'ended') === 'expired' ? 'Deze demo is verlopen' : 'Demo beëindigd' }}
            </h1>
            <p class="mt-3 text-base leading-relaxed text-gray-600">
                @if (($reason ?? 'ended') === 'expired')
                    De demosessie is verlopen. Demogegevens verdwijnen automatisch. U kunt opnieuw beginnen met een schone demo.
                @else
                    U heeft de demosessie afgesloten. Demogegevens verdwijnen. U kunt opnieuw beginnen wanneer u wilt.
                @endif
            </p>
            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <a
                    href="{{ url('/') }}"
                    class="inline-flex min-h-11 items-center justify-center rounded-xl bg-gray-950 px-5 text-sm font-semibold text-white hover:bg-gray-800"
                >
                    Naar de homepage
                </a>
                @if (config('intake.demo.enabled', true))
                    <form method="POST" action="{{ route('demo.start') }}">
                        @csrf
                        <button
                            type="submit"
                            class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-sky-300 bg-white px-5 text-sm font-semibold text-sky-900 hover:bg-sky-50"
                        >
                            Nieuwe demo starten
                        </button>
                    </form>
                @endif
            </div>
        </main>
    </body>
</html>
