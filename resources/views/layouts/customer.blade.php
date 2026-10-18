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
        <div id="customer-session-notice" data-testid="customer-session-notice" role="alert" hidden
            class="fixed inset-x-0 bottom-0 z-50 border-t border-[#d7c48a] bg-[#fff8e1] px-4 py-4 shadow-lg sm:inset-x-auto sm:bottom-6 sm:left-1/2 sm:w-[min(36rem,calc(100%-2rem))] sm:-translate-x-1/2 sm:rounded-2xl sm:border">
            <p data-session-notice-text class="text-sm font-medium text-[#3d3420]"></p>
            <button type="button" onclick="window.location.reload()"
                class="mt-3 inline-flex min-h-11 items-center rounded-xl bg-[#18201d] px-4 text-sm font-semibold text-white">
                Pagina vernieuwen
            </button>
        </div>
        @livewireScripts
        <script>
            (function () {
                const LINK_GONE_MESSAGE = 'Deze opname is niet meer beschikbaar. Deed je een demo? Dan is de demosessie verlopen of beëindigd. Vernieuw de pagina voor uitleg.';
                const messageForStatus = function (status) {
                    if (status === 404 || status === 410) return LINK_GONE_MESSAGE;
                    if (status === 419) return 'Je sessie is verlopen. Vernieuw de pagina en probeer opnieuw.';
                    if (status === 503 || status === 502 || status === 504) return 'De server is even druk. Probeer het zo opnieuw.';
                    if (status >= 500) return 'De server is even druk. Probeer het zo opnieuw.';
                    if (status === 0) return 'Geen verbinding. Controleer je netwerk en probeer opnieuw.';
                    return 'De aanvraag lukte niet. Probeer het opnieuw.';
                };

                const showSessionNotice = function (message) {
                    const notice = document.getElementById('customer-session-notice');
                    if (! notice) return;
                    const text = notice.querySelector('[data-session-notice-text]');
                    if (text) text.textContent = message;
                    notice.hidden = false;
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
                            const message = messageForStatus(code);
                            // N96: a dead link/session (demo verlopen, link ingetrokken, 419) must
                            // never be a silent no-op on Volgende — show a page-level notice.
                            if (code === 404 || code === 410 || code === 419) showSessionNotice(message);
                            document.dispatchEvent(new CustomEvent('intake:livewire-request-failed', {
                                detail: {
                                    status: code,
                                    message: message,
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
