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
        *, *::before, *::after { box-sizing: border-box; }
        html, body {
            margin: 0;
            max-width: 100%;
            overflow-x: hidden;
        }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.45;
        }
        main {
            max-width: 48rem;
            width: 100%;
            margin: 0 auto;
            padding: 1.5rem 1rem 3rem;
            min-width: 0;
        }
        h1 { margin: 0; font-size: 1.5rem; letter-spacing: -0.01em; }
        .lead { margin: 0.5rem 0 0; color: var(--muted); font-size: 0.95rem; }
        .card {
            margin-top: 1.25rem;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            padding: 1rem 1.25rem;
            min-width: 0;
            max-width: 100%;
        }
        .meta { font-size: 0.875rem; color: var(--muted); }
        code { font-size: 0.8rem; word-break: break-all; }
        .frame-wrap {
            min-width: 0;
            max-width: 100%;
            overflow-x: hidden;
        }
        .hint { margin-top: 0.75rem; font-size: 0.875rem; color: var(--muted); }
    </style>
</head>
<body>
<main>
    <h1>Embed staging-test</h1>
    <p class="lead">
        Simuleert een installateurswebsite: het formulier staat in een iframe op deze host
        (<code>'self'</code> in frame-ancestors). Happy path: invullen → adres bevestigen → top-window landt op de klantlink.
    </p>

    <div class="card meta" data-testid="embed-staging-test-meta">
        <p>Testbedrijf: <strong>{{ $company->name }}</strong></p>
        <p>Sleutel: <code data-testid="embed-staging-test-key">{{ $publicKey }}</code></p>
        <p>Iframe-src: <code>{{ $iframeSrc }}</code></p>
    </div>

    <div class="card" data-testid="embed-staging-test-frame">
        <div class="frame-wrap">
            {!! $iframeSnippet !!}
        </div>
        <p class="hint">
            De iframe-hoogte past zich automatisch aan via <code>postMessage</code>.
            Na “Ja, klopt” breekt de redirect uit het iframe (<code>target="_top"</code>).
            Op het dashboard van de testgebruiker zie je de badge “Nieuw via website”.
        </p>
    </div>
</main>
</body>
</html>
