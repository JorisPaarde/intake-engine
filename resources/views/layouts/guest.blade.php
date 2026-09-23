<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-[#eef1ec] font-sans text-[#18201d] antialiased">
        <div class="flex min-h-screen flex-col items-center px-4 pt-10 sm:justify-center sm:pt-0">
            <a href="/" class="flex items-center gap-3">
                <x-application-logo class="h-11 w-11 text-marketing-green-dark" />
                <span class="text-lg font-extrabold tracking-tight">Digitale Opname</span>
            </a>

            <div class="mt-8 w-full overflow-hidden border border-[#dde2da] bg-white px-6 py-7 sm:max-w-md sm:px-8">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
