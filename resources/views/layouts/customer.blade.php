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

        @vite(['resources/css/app.css', 'resources/js/customer.js'])
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
        <script>
            (function () {
                const messageForStatus = function (status) {
                    if (status === 419) return 'Je sessie is verlopen. Vernieuw de pagina en probeer opnieuw.';
                    if (status === 503 || status === 502 || status === 504) return 'De server is even druk. Probeer het zo opnieuw.';
                    if (status >= 500) return 'De server is even druk. Probeer het zo opnieuw.';
                    if (status === 0) return 'Geen verbinding. Controleer je netwerk en probeer opnieuw.';
                    return 'De aanvraag lukte niet. Probeer het opnieuw.';
                };

                const bind = function () {
                    if (typeof Livewire === 'undefined' || typeof Livewire.hook !== 'function') return;
                    if (window.__intakeCustomerLayoutRequestBound) return;
                    window.__intakeCustomerLayoutRequestBound = true;
                    Livewire.hook('request', function ({ fail }) {
                        fail(function ({ status, preventDefault }) {
                            const code = typeof status === 'number' ? status : 0;
                            // Retryable 5xx/network: app.js livewire-resilience owns auto-retry (BL-143).
                            if (code === 0 || code === 408 || code === 429 || (code >= 500 && code <= 599)) {
                                return;
                            }
                            if (typeof preventDefault === 'function') preventDefault();
                            document.dispatchEvent(new CustomEvent('intake:livewire-request-failed', {
                                detail: {
                                    status: code,
                                    message: messageForStatus(code),
                                },
                            }));
                        });
                    });
                };

                document.addEventListener('livewire:init', bind);
                if (typeof Livewire !== 'undefined') bind();
            })();
        </script>
    </body>
</html>
