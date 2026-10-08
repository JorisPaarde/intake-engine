<!DOCTYPE html>
<html lang="nl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Foto te groot — Digitale Opname</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-[#eef1ec] font-sans text-[#18201d] antialiased">
        <main class="mx-auto flex min-h-screen max-w-lg flex-col justify-center px-5 py-12">
            <p class="eyebrow">Digitale Opname</p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight text-gray-950">Foto te groot voor één upload</h1>
            <p class="mt-3 text-base leading-relaxed text-gray-600">
                {{ $message }}
            </p>
            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <a
                    href="{{ $backUrl }}"
                    class="inline-flex min-h-11 items-center justify-center rounded-xl bg-marketing-green-dark px-5 text-sm font-semibold text-white hover:bg-marketing-green"
                    data-testid="post-too-large-back"
                >
                    Terug naar opname
                </a>
            </div>
        </main>
    </body>
</html>
