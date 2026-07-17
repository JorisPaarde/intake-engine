<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Opnamerapport — {{ $intake->customer_name }}</title>
    <style>
        body { font-family: Georgia, "Times New Roman", serif; color: #1a1a1a; line-height: 1.45; margin: 0; padding: 1.5rem; }
        h1 { font-size: 1.5rem; margin: 0 0 0.25rem; }
        h2 { font-size: 1.15rem; margin: 1.5rem 0 0.5rem; border-bottom: 1px solid #ccc; padding-bottom: 0.25rem; }
        .meta { color: #555; font-size: 0.9rem; margin-bottom: 1.25rem; }
        .attention { background: #fff8e6; border: 1px solid #e6d5a8; padding: 0.75rem 1rem; margin: 1rem 0; }
        .attention ul { margin: 0.35rem 0 0; padding-left: 1.25rem; }
        dl { margin: 0; }
        dt { font-weight: 700; margin-top: 0.65rem; }
        dd { margin: 0.15rem 0 0; color: #222; }
        .instance { color: #666; font-size: 0.85rem; font-weight: normal; }
    </style>
</head>
<body>
    <h1>Opnamerapport</h1>
    <p class="meta">
        {{ $intake->customer_name }} · {{ $intake->fullAddress() }}<br>
        {{ $version->template?->name }} · template v{{ $version->version }}<br>
        Gegenereerd {{ $generatedAt->timezone(config('app.timezone'))->format('d-m-Y H:i') }}
    </p>

    @if ($attentionPoints !== [])
        <div class="attention">
            <strong>Aandachtspunten</strong>
            <ul>
                @foreach ($attentionPoints as $point)
                    <li>{{ $point['label'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @foreach ($sections as $section)
        <h2>
            {{ $section['title'] }}
            @if ($section['instance_label'])
                <span class="instance">({{ $section['instance_label'] }})</span>
            @endif
        </h2>
        <dl>
            @foreach ($section['questions'] as $question)
                <dt>{{ $question['label'] }}</dt>
                <dd>{{ $question['display'] }}</dd>
            @endforeach
        </dl>
    @endforeach
</body>
</html>
