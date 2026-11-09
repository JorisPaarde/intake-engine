<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Embed staging-test — Digitale Opname</title>
    <style>
        :root {
            --bg: #eef1ec;
            --card: #ffffff;
            --border: #dde2da;
            --text: #18201d;
            --muted: #5e6862;
            --primary: #1f6b4a;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.45;
        }
        main { max-width: 48rem; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
        h1 { margin: 0; font-size: 1.5rem; letter-spacing: -0.01em; }
        .lead { margin: 0.5rem 0 0; color: var(--muted); font-size: 0.95rem; }
        .card {
            margin-top: 1.25rem;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            padding: 1rem 1.25rem;
        }
        .meta { font-size: 0.875rem; color: var(--muted); }
        code { font-size: 0.8rem; word-break: break-all; }
        iframe {
            display: block;
            width: 100%;
            max-width: 40rem;
            height: 720px;
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            background: #fff;
        }
        .hint { margin-top: 0.75rem; font-size: 0.875rem; color: var(--muted); }
    </style>
</head>
<body>
<main>
    <h1>Embed staging-test</h1>
    <p class="lead">
        Simuleert een installateurswebsite: het formulier staat in een iframe op deze host
        (<code>'self'</code> in frame-ancestors). Happy path: invullen → top-window landt op de klantlink.
    </p>

    <div class="card meta" data-testid="embed-staging-test-meta">
        <p>Testbedrijf: <strong>{{ $company->name }}</strong></p>
        <p>Sleutel: <code data-testid="embed-staging-test-key">{{ $publicKey }}</code></p>
        <p>Iframe-src: <code>{{ $iframeSrc }}</code></p>
    </div>

    <div class="card" data-testid="embed-staging-test-frame">
        <iframe
            src="{{ $iframeSrc }}"
            title="Opname starten via websiteformulier"
            width="100%"
            height="720"
            loading="lazy"
            referrerpolicy="strict-origin-when-cross-origin"
        ></iframe>
        <p class="hint">
            Na versturen breekt de redirect uit het iframe (<code>target="_top"</code>).
            Op het dashboard van de testgebruiker zie je de badge “Nieuw via website”.
        </p>
    </div>
</main>
</body>
</html>
