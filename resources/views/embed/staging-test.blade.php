<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Zo ziet het opnameformulier eruit op jouw website — Digitale Opname</title>
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
        .frame-wrap {
            margin-top: 1.25rem;
            min-width: 0;
            max-width: 100%;
            overflow-x: hidden;
        }
    </style>
</head>
<body>
{{-- Technisch (niet zichtbaar voor installateurs): public_key, iframe_src, CSP frame-ancestors bevat 'self'. Smoke: php artisan embed:prepare-staging-test --}}
<!-- embed-staging-smoke public_key={{ $publicKey }} iframe_src={{ $iframeSrc }} prepare=embed:prepare-staging-test -->
<main>
    <h1>Zo ziet het opnameformulier eruit op jouw website</h1>
    <p class="lead" data-testid="embed-staging-test-intro">
        Dit is een voorbeeld van een installateurswebsite met de digitale opname erin.
        Je klant vult hier zelf zijn adres, de ruimtes en foto’s in.
        Jij krijgt daarna een compleet overzicht om een offerte te maken, zonder dat je eerst langs hoeft.
        Probeer het hieronder gerust uit; het is een testomgeving, er gaat niets naar echte klanten.
    </p>

    <div class="frame-wrap" data-testid="embed-staging-test-frame">
        {!! $iframeSnippet !!}
    </div>
</main>
</body>
</html>
