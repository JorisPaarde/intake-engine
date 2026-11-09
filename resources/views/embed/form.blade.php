<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Opname starten — {{ $company->name }}</title>
    <style>
        :root {
            --embed-bg: #eef1ec;
            --embed-card: #ffffff;
            --embed-border: #dde2da;
            --embed-text: #18201d;
            --embed-muted: #5e6862;
            --embed-primary: {{ $company->themeTokens()['primary'] }};
            --embed-on-primary: {{ $company->themeTokens()['on_primary'] }};
            --embed-soft: #f7f8f6;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
            background: var(--embed-bg);
            color: var(--embed-text);
            line-height: 1.45;
        }
        main { max-width: 32rem; margin: 0 auto; padding: 1.5rem 1rem; }
        .card {
            background: var(--embed-card);
            border: 1px solid var(--embed-border);
            border-radius: 0.75rem;
            padding: 1.25rem;
            box-shadow: 0 1px 2px rgba(24, 32, 29, 0.04);
        }
        .eyebrow { margin: 0; font-size: 0.875rem; font-weight: 600; color: var(--embed-muted); }
        h1 { margin: 0.25rem 0 0; font-size: 1.25rem; font-weight: 700; letter-spacing: -0.01em; }
        .lead { margin: 0.5rem 0 0; font-size: 0.875rem; color: var(--embed-muted); }
        .alert {
            margin-top: 1rem;
            border-radius: 0.75rem;
            padding: 0.75rem 1rem;
            font-size: 0.875rem;
        }
        .alert-warn { border: 1px solid #f5d38a; background: #fff8e8; color: #7a4e00; }
        .alert-error { border: 1px solid #f0c2c2; background: #fff1f1; color: #8a1f1f; }
        .alert-error ul { margin: 0; padding-left: 1.1rem; }
        form { margin-top: 1.25rem; display: grid; gap: 1rem; }
        label { display: block; font-size: 0.875rem; font-weight: 600; }
        .hint { font-weight: 400; color: var(--embed-muted); }
        input[type="text"],
        input[type="email"],
        input[type="tel"],
        input[type="number"] {
            margin-top: 0.35rem;
            width: 100%;
            min-height: 2.75rem;
            border: 1px solid var(--embed-border);
            border-radius: 0.75rem;
            padding: 0.55rem 0.75rem;
            font: inherit;
            color: var(--embed-text);
            background: #fff;
        }
        input:focus {
            outline: 2px solid color-mix(in srgb, var(--embed-primary) 35%, white);
            border-color: var(--embed-primary);
        }
        .row { display: grid; gap: 1rem; }
        @media (min-width: 640px) {
            .row { grid-template-columns: 1.1fr 0.9fr 0.9fr; }
        }
        .consent {
            border: 1px solid var(--embed-border);
            background: var(--embed-soft);
            border-radius: 0.75rem;
            padding: 0.85rem 1rem;
        }
        .consent label {
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
            font-weight: 400;
        }
        .consent input { margin-top: 0.2rem; }
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
    <main>
        <div class="card">
            <p class="eyebrow">{{ $company->name }}</p>
            <h1>Start je opname</h1>
            <p class="lead">Vul je gegevens in. Daarna ga je door naar de opname.</p>

            @if ($errorMessage)
                <div class="alert alert-warn" role="alert" data-testid="embed-error">
                    {{ $errorMessage }}
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-error" role="alert">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form
                method="POST"
                action="{{ route('embed.store', ['public_key' => $publicKey]) }}"
                target="_top"
                novalidate
            >
                @csrf

                <div class="hp" aria-hidden="true">
                    <label for="website">Website</label>
                    <input id="website" type="text" name="website" value="{{ old('website') }}" tabindex="-1" autocomplete="off">
                </div>

                <div>
                    <label for="customer_name">Naam</label>
                    <input id="customer_name" name="customer_name" type="text" required maxlength="120" value="{{ old('customer_name') }}" autocomplete="name">
                </div>

                <div>
                    <label for="customer_email">E-mailadres</label>
                    <input id="customer_email" name="customer_email" type="email" required maxlength="254" value="{{ old('customer_email') }}" autocomplete="email">
                </div>

                <div>
                    <label for="customer_phone">Telefoonnummer <span class="hint">(optioneel)</span></label>
                    <input id="customer_phone" name="customer_phone" type="tel" maxlength="40" value="{{ old('customer_phone') }}" autocomplete="tel">
                </div>

                <div class="row">
                    <div>
                        <label for="address_postal_code">Postcode</label>
                        <input id="address_postal_code" name="address_postal_code" type="text" required maxlength="7" value="{{ old('address_postal_code') }}" autocomplete="postal-code" placeholder="1234 AB">
                    </div>
                    <div>
                        <label for="address_house_number">Huisnummer</label>
                        <input id="address_house_number" name="address_house_number" type="number" required min="1" max="999999" value="{{ old('address_house_number') }}">
                    </div>
                    <div>
                        <label for="address_house_number_addition">Toevoeging <span class="hint">(optioneel)</span></label>
                        <input id="address_house_number_addition" name="address_house_number_addition" type="text" maxlength="20" value="{{ old('address_house_number_addition') }}">
                    </div>
                </div>

                <div class="consent">
                    <label for="privacy_consent">
                        <input id="privacy_consent" name="privacy_consent" type="checkbox" value="1" required @checked(old('privacy_consent'))>
                        <span>
                            Ik geef {{ $company->name }} toestemming om mijn gegevens te gebruiken voor deze opname.
                            {{ $company->name }} is verwerkingsverantwoordelijke.
                        </span>
                    </label>
                </div>

                <button type="submit" data-testid="embed-submit">Start opname</button>
            </form>
        </div>
    </main>
</body>
</html>
