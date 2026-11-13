<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Opname starten — {{ $company->name }}</title>
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
            --embed-danger: #8a1f1f;
            --embed-danger-border: #f0c2c2;
            --embed-danger-bg: #fff1f1;
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
        /* Geen tweede witte kaart in de iframe: body is al wit (BL-169 /embed-test). */
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
        .lead { margin: 0.5rem 0 0; font-size: 0.875rem; color: var(--embed-muted); }
        .alert {
            margin-top: 1rem;
            border-radius: 0.75rem;
            padding: 0.75rem 1rem;
            font-size: 0.875rem;
            min-width: 0;
            overflow-wrap: anywhere;
        }
        .alert-warn { border: 1px solid #f5d38a; background: #fff8e8; color: #7a4e00; }
        .alert-error { border: 1px solid var(--embed-danger-border); background: var(--embed-danger-bg); color: var(--embed-danger); }
        .alert-error ul { margin: 0; padding-left: 1.1rem; }
        form { margin-top: 1.25rem; display: grid; gap: 1rem; min-width: 0; }
        label { display: block; font-size: 0.875rem; font-weight: 600; }
        .hint { font-weight: 400; color: var(--embed-muted); }
        input[type="text"],
        input[type="email"],
        input[type="tel"] {
            margin-top: 0.35rem;
            display: block;
            width: 100%;
            max-width: 100%;
            min-width: 0;
            min-height: 2.75rem;
            border: 1px solid var(--embed-border);
            border-radius: 0.75rem;
            padding: 0.55rem 0.75rem;
            font: inherit;
            color: var(--embed-text);
            background: #fff;
        }
        input[aria-invalid="true"] {
            border-color: var(--embed-danger);
        }
        input:focus {
            outline: 2px solid color-mix(in srgb, var(--embed-primary) 35%, white);
            border-color: var(--embed-primary);
        }
        .field-error {
            margin: 0.35rem 0 0;
            font-size: 0.8125rem;
            color: var(--embed-danger);
            overflow-wrap: anywhere;
        }
        .row {
            display: grid;
            gap: 1rem;
            min-width: 0;
            align-items: end;
        }
        .row > * { min-width: 0; max-width: 100%; }
        @media (min-width: 640px) {
            .row { grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr) minmax(0, 0.7fr); }
        }
        .consent a {
            color: var(--embed-primary);
            font-weight: 600;
            text-decoration: underline;
            text-underline-offset: 2px;
        }
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        .consent {
            border: 1px solid var(--embed-border);
            background: var(--embed-soft);
            border-radius: 0.75rem;
            padding: 0.85rem 1rem;
            min-width: 0;
        }
        .consent.is-invalid { border-color: var(--embed-danger); }
        .consent label {
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
            font-weight: 400;
            min-width: 0;
        }
        .consent label > span { min-width: 0; overflow-wrap: anywhere; }
        .consent input { margin-top: 0.2rem; flex-shrink: 0; }
        .hp {
            position: absolute;
            left: -9999px;
            width: 1px;
            height: 1px;
            overflow: hidden;
        }
        button[type="submit"] {
            min-height: 2.75rem;
            width: 100%;
            max-width: 100%;
            min-width: 0;
            border: 0;
            border-radius: 0.75rem;
            background: var(--embed-primary);
            color: var(--embed-on-primary);
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        button[type="submit"]:hover { filter: brightness(1.05); }
        button[type="submit"]:focus {
            outline: 2px solid color-mix(in srgb, var(--embed-primary) 45%, white);
            outline-offset: 2px;
        }
    </style>
</head>
<body>
    @php
        /** @var \Illuminate\Contracts\Support\MessageBag $fieldErrors */
        $fieldErrors = $fieldErrors ?? new \Illuminate\Support\MessageBag;
        $old = is_array($oldInput ?? null) ? $oldInput : [];
        $oldValue = static function (string $key, mixed $default = '') use ($old): mixed {
            return array_key_exists($key, $old) && $old[$key] !== null ? $old[$key] : $default;
        };
        $hasError = static fn (string $key): bool => $fieldErrors->has($key);
        $errorId = static fn (string $key): string => $key.'-error';
    @endphp
    <main>
        <div class="card">
            <p class="eyebrow">{{ $company->name }}</p>
            <h1>Start je opname</h1>
            <p class="lead">Vul je gegevens in. Daarna ga je door naar de opname.</p>

            @if (! empty($errorMessage))
                <div class="alert alert-warn" role="alert" data-testid="embed-error">
                    {{ $errorMessage }}
                </div>
            @endif

            @if ($fieldErrors->any())
                <div class="alert alert-error" role="alert" data-testid="embed-validation-summary">
                    <ul>
                        @foreach ($fieldErrors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form
                method="POST"
                action="{{ route('embed.store', ['public_key' => $publicKey]) }}"
                novalidate
                data-testid="embed-form"
            >
                <input type="hidden" name="embed_form_token" value="{{ $embedFormToken }}">

                <div class="hp" aria-hidden="true">
                    <label for="website">Website</label>
                    <input id="website" type="text" name="website" value="" tabindex="-1" autocomplete="off">
                </div>

                <div>
                    <label for="customer_name">Naam</label>
                    <input
                        id="customer_name"
                        name="customer_name"
                        type="text"
                        required
                        maxlength="120"
                        value="{{ $oldValue('customer_name') }}"
                        autocomplete="name"
                        @if ($hasError('customer_name')) aria-invalid="true" aria-describedby="{{ $errorId('customer_name') }}" @endif
                    >
                    @if ($hasError('customer_name'))
                        <p id="{{ $errorId('customer_name') }}" class="field-error">{{ $fieldErrors->first('customer_name') }}</p>
                    @endif
                </div>

                <div>
                    <label for="customer_email">E-mailadres</label>
                    <input
                        id="customer_email"
                        name="customer_email"
                        type="email"
                        required
                        maxlength="254"
                        value="{{ $oldValue('customer_email') }}"
                        autocomplete="email"
                        @if ($hasError('customer_email')) aria-invalid="true" aria-describedby="{{ $errorId('customer_email') }}" @endif
                    >
                    @if ($hasError('customer_email'))
                        <p id="{{ $errorId('customer_email') }}" class="field-error">{{ $fieldErrors->first('customer_email') }}</p>
                    @endif
                </div>

                <div>
                    <label for="customer_phone">Telefoonnummer <span class="hint">(optioneel)</span></label>
                    <input
                        id="customer_phone"
                        name="customer_phone"
                        type="tel"
                        maxlength="40"
                        value="{{ $oldValue('customer_phone') }}"
                        autocomplete="tel"
                        @if ($hasError('customer_phone')) aria-invalid="true" aria-describedby="{{ $errorId('customer_phone') }}" @endif
                    >
                    @if ($hasError('customer_phone'))
                        <p id="{{ $errorId('customer_phone') }}" class="field-error">{{ $fieldErrors->first('customer_phone') }}</p>
                    @endif
                </div>

                <div class="row">
                    <div>
                        <label for="address_postal_code">Postcode</label>
                        <input
                            id="address_postal_code"
                            name="address_postal_code"
                            type="text"
                            required
                            maxlength="7"
                            value="{{ $oldValue('address_postal_code') }}"
                            autocomplete="postal-code"
                            inputmode="text"
                            placeholder="1234 AB"
                            @if ($hasError('address_postal_code')) aria-invalid="true" aria-describedby="{{ $errorId('address_postal_code') }}" @endif
                        >
                        @if ($hasError('address_postal_code'))
                            <p id="{{ $errorId('address_postal_code') }}" class="field-error" data-testid="embed-postal-error">{{ $fieldErrors->first('address_postal_code') }}</p>
                        @endif
                    </div>
                    <div>
                        <label for="address_house_number">Huisnummer</label>
                        <input
                            id="address_house_number"
                            name="address_house_number"
                            type="text"
                            required
                            maxlength="6"
                            value="{{ $oldValue('address_house_number') }}"
                            inputmode="numeric"
                            pattern="[1-9][0-9]{0,5}"
                            autocomplete="address-line2"
                            @if ($hasError('address_house_number')) aria-invalid="true" aria-describedby="{{ $errorId('address_house_number') }}" @endif
                        >
                        @if ($hasError('address_house_number'))
                            <p id="{{ $errorId('address_house_number') }}" class="field-error">{{ $fieldErrors->first('address_house_number') }}</p>
                        @endif
                    </div>
                    <div>
                        <label for="address_house_number_addition">
                            <span aria-hidden="true">Toev.</span>
                            <span class="sr-only">Toevoeging, optioneel</span>
                        </label>
                        <input
                            id="address_house_number_addition"
                            name="address_house_number_addition"
                            type="text"
                            maxlength="20"
                            value="{{ $oldValue('address_house_number_addition') }}"
                            inputmode="text"
                            pattern="[A-Za-z0-9\-\s]*"
                            autocomplete="address-line3"
                            aria-label="Toevoeging, optioneel"
                            @if ($hasError('address_house_number_addition')) aria-invalid="true" aria-describedby="{{ $errorId('address_house_number_addition') }}" @endif
                        >
                        @if ($hasError('address_house_number_addition'))
                            <p id="{{ $errorId('address_house_number_addition') }}" class="field-error">{{ $fieldErrors->first('address_house_number_addition') }}</p>
                        @endif
                    </div>
                </div>

                @php
                    $privacyPolicyUrl = trim((string) config('intake.embed.privacy_policy_url', ''));
                @endphp
                <div @class(['consent', 'is-invalid' => $hasError('privacy_consent')])>
                    <label for="privacy_consent">
                        <input
                            id="privacy_consent"
                            name="privacy_consent"
                            type="checkbox"
                            value="1"
                            required
                            @checked((string) $oldValue('privacy_consent') === '1')
                            @if ($hasError('privacy_consent')) aria-invalid="true" aria-describedby="{{ $errorId('privacy_consent') }}" @endif
                        >
                        <span>
                            Ik geef {{ $company->name }} toestemming om mijn gegevens en foto’s voor deze opname te gebruiken.
                            @if ($privacyPolicyUrl !== '')
                                <a href="{{ $privacyPolicyUrl }}" target="_blank" rel="noopener noreferrer">Privacy</a>
                            @else
                                Privacy
                            @endif
                        </span>
                    </label>
                    @if ($hasError('privacy_consent'))
                        <p id="{{ $errorId('privacy_consent') }}" class="field-error">{{ $fieldErrors->first('privacy_consent') }}</p>
                    @endif
                </div>

                <button type="submit" data-testid="embed-submit">Controleer adres</button>
            </form>
        </div>
    </main>
    @include('embed.partials.resize-reporter')
</body>
</html>
