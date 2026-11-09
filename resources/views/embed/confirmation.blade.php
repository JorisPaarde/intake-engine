<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Opname — {{ $company->name }}</title>
    <style>
        :root {
            --embed-bg: #eef1ec;
            --embed-card: #ffffff;
            --embed-border: #dde2da;
            --embed-text: #18201d;
            --embed-muted: #5e6862;
            --embed-primary: {{ $company->themeTokens()['primary'] }};
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
        .lead { margin: 0.75rem 0 0; font-size: 0.95rem; color: var(--embed-text); }
        .hint { margin: 0.75rem 0 0; font-size: 0.875rem; color: var(--embed-muted); }
    </style>
</head>
<body>
    <main>
        <div class="card" data-testid="embed-confirmation">
            <p class="eyebrow">{{ $company->name }}</p>
            <h1>Check je e-mail</h1>
            <p class="lead">We hebben je de link naar je opname gemaild.</p>
            <p class="hint">Klik op de link in de mail om verder te gaan. Zie je geen mail? Kijk ook in je spammap.</p>
        </div>
    </main>
</body>
</html>
