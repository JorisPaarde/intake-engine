<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    @php
        $company = Auth::user()?->company;
        $tokens = $company?->themeTokens() ?? \App\Models\Company::defaultThemeTokens();
    @endphp
    <body class="bg-[#eef1ec] font-sans text-[#18201d] antialiased" style="--tenant-primary: {{ $tokens['primary'] }}; --tenant-accent: {{ $tokens['accent'] }}; --tenant-on-primary: {{ $tokens['on_primary'] }};">
        <div class="min-h-screen bg-[#eef1ec]">
            @include('layouts.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header class="border-b border-[#dde2da] bg-[#f7f8f5]">
                    <div class="mx-auto {{ $header->attributes->get('width', 'max-w-7xl') }} px-4 py-6 sm:px-6 sm:py-8 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
