<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    @php
        $company = Auth::user()?->company;
        $tokens = $company?->themeTokens() ?? [
            'primary' => \App\Models\Company::DEFAULT_PRIMARY,
            'accent' => \App\Models\Company::DEFAULT_ACCENT,
            'on_primary' => \App\Models\Company::DEFAULT_ON_PRIMARY,
        ];
    @endphp
    <body class="font-sans antialiased text-[#1D1D1F]" style="--tenant-primary: {{ $tokens['primary'] }}; --tenant-accent: {{ $tokens['accent'] }}; --tenant-on-primary: {{ $tokens['on_primary'] }};">
        <div class="min-h-screen bg-[#F5F5F7]">
            @include('layouts.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header class="border-b border-[#D2D2D7] bg-white">
                    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
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
