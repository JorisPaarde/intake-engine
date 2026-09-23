<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="robots" content="noindex,nofollow">

        <title>{{ $title ?? 'Digitale opname' }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css'])
        @livewireStyles
    </head>
    @php
        $customerIntake = request()->attributes->get('customer_intake');
        $customerCompany = $customerIntake instanceof \App\Domains\Intake\Models\Intake
            ? $customerIntake->company
            : null;
        $tokens = $customerCompany?->themeTokens() ?? \App\Models\Company::defaultThemeTokens();
    @endphp
    <body class="min-h-[100svh] bg-[#eef1ec] font-sans text-[#18201d] antialiased" style="--tenant-primary: {{ $tokens['primary'] }}; --tenant-accent: {{ $tokens['accent'] }}; --tenant-on-primary: {{ $tokens['on_primary'] }};">
        {{ $slot }}
        @livewireScripts
    </body>
</html>
