<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="robots" content="noindex,nofollow">

        <title>{{ $title ?? 'Digitale opname' }}</title>

        @vite(['resources/css/app.css'])
        @livewireStyles
    </head>
    @php
        $customerIntake = request()->attributes->get('customer_intake');
        $customerCompany = $customerIntake instanceof \App\Domains\Intake\Models\Intake
            ? $customerIntake->company()->first()
            : null;
        $tokens = $customerCompany?->themeTokens() ?? [
            'primary' => \App\Models\Company::DEFAULT_PRIMARY,
            'accent' => \App\Models\Company::DEFAULT_ACCENT,
            'on_primary' => \App\Models\Company::DEFAULT_ON_PRIMARY,
        ];
    @endphp
    <body class="min-h-[100svh] bg-[#F5F5F7] font-sans text-[#1D1D1F] antialiased" style="--tenant-primary: {{ $tokens['primary'] }}; --tenant-accent: {{ $tokens['accent'] }}; --tenant-on-primary: {{ $tokens['on_primary'] }};">
        {{ $slot }}
        @livewireScripts
    </body>
</html>
