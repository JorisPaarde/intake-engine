<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Adres bevestigen — {{ $company->name }}</title>
    <style>
        :root {
            --embed-bg: #ffffff;
            --embed-card: #ffffff;
            --embed-border: #dde2da;
            --embed-text: #18201d;
            --embed-muted: #5e6862;
            --embed-primary: {{ $company->themeTokens()['primary'] }};
            --embed-on-primary: {{ $company->themeTokens()['on_primary'] }};
            --embed-soft: #f7f8f6;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html, body {
            margin: 0;
            max-width: 100%;
            overflow-x: hidden;
            height: auto;
            min-height: 0;
        }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
            background: var(--embed-bg);
            color: var(--embed-text);
            line-height: 1.45;
        }
        main {
            max-width: 32rem;
            width: 100%;
            margin: 0 auto;
            padding: 1rem 1rem 1.25rem;
            min-width: 0;
        }
        .card {
            background: transparent;
            border: 0;
            border-radius: 0;
            padding: 0;
            box-shadow: none;
            min-width: 0;
            max-width: 100%;
        }
        .eyebrow { margin: 0; font-size: 0.875rem; font-weight: 600; color: var(--embed-muted); }
        h1 { margin: 0.25rem 0 0; font-size: 1.25rem; font-weight: 700; letter-spacing: -0.01em; }
        .lead { margin: 0.75rem 0 0; font-size: 0.95rem; color: var(--embed-text); }
        .address {
            margin: 1rem 0 0;
            padding: 0.9rem 1rem;
            border-radius: 0.75rem;
            border: 1px solid var(--embed-border);
            background: var(--embed-soft);
            font-size: 1rem;
            font-weight: 600;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .actions {
            margin-top: 1.25rem;
            display: grid;
            gap: 0.75rem;
            min-width: 0;
        }
        button {
            min-height: 2.75rem;
            width: 100%;
            max-width: 100%;
            min-width: 0;
            border-radius: 0.75rem;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        button.primary {
            border: 0;
            background: var(--embed-primary);
            color: var(--embed-on-primary);
        }
        button.primary:hover { filter: brightness(1.05); }
        button.secondary {
            border: 1px solid var(--embed-border);
            background: #fff;
            color: var(--embed-text);
        }
        button:focus {
            outline: 2px solid color-mix(in srgb, var(--embed-primary) 45%, white);
            outline-offset: 2px;
        }
    </style>
</head>
<body>
    @php
        $old = is_array($oldInput ?? null) ? $oldInput : [];
        $oldValue = static function (string $key, mixed $default = '') use ($old): mixed {
            return array_key_exists($key, $old) && $old[$key] !== null ? $old[$key] : $default;
        };
    @endphp
    <main>
        <div class="card" data-testid="embed-address-confirm">
            <p class="eyebrow">{{ $company->name }}</p>
            <h1>Is dit je adres?</h1>
            <p class="lead">Controleer of we het juiste adres hebben gevonden.</p>
            <p class="address" data-testid="embed-address-summary">{{ $addressSummary }}</p>

            <div class="actions">
                <form
                    method="POST"
                    action="{{ route('embed.store', ['public_key' => $publicKey]) }}"
                    target="_top"
                >
                    <input type="hidden" name="embed_form_token" value="{{ $embedFormToken }}">
                    <input type="hidden" name="address_confirmed" value="1">
                    <input type="hidden" name="customer_name" value="{{ $oldValue('customer_name') }}">
                    <input type="hidden" name="customer_email" value="{{ $oldValue('customer_email') }}">
                    <input type="hidden" name="customer_phone" value="{{ $oldValue('customer_phone') }}">
                    <input type="hidden" name="address_postal_code" value="{{ $oldValue('address_postal_code') }}">
                    <input type="hidden" name="address_house_number" value="{{ $oldValue('address_house_number') }}">
                    <input type="hidden" name="address_house_number_addition" value="{{ $oldValue('address_house_number_addition') }}">
                    <input type="hidden" name="privacy_consent" value="1">
                    <input type="hidden" name="website" value="">
                    <button type="submit" class="primary" data-testid="embed-address-confirm-yes">Ja, klopt</button>
                </form>

                <form
                    method="POST"
                    action="{{ route('embed.store', ['public_key' => $publicKey]) }}"
                >
                    <input type="hidden" name="embed_form_token" value="{{ $embedFormToken }}">
                    <input type="hidden" name="embed_action" value="edit">
                    <input type="hidden" name="customer_name" value="{{ $oldValue('customer_name') }}">
                    <input type="hidden" name="customer_email" value="{{ $oldValue('customer_email') }}">
                    <input type="hidden" name="customer_phone" value="{{ $oldValue('customer_phone') }}">
                    <input type="hidden" name="address_postal_code" value="{{ $oldValue('address_postal_code') }}">
                    <input type="hidden" name="address_house_number" value="{{ $oldValue('address_house_number') }}">
                    <input type="hidden" name="address_house_number_addition" value="{{ $oldValue('address_house_number_addition') }}">
                    <input type="hidden" name="privacy_consent" value="1">
                    <input type="hidden" name="website" value="">
                    <button type="submit" class="secondary" data-testid="embed-address-confirm-edit">Aanpassen</button>
                </form>
            </div>
        </div>
    </main>
    @include('embed.partials.resize-reporter')
</body>
</html>
