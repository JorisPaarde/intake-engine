<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Opnamerapport — {{ $intake->customer_name }}</title>
    <style>
        body { font-family: Georgia, "Times New Roman", serif; color: #1a1a1a; line-height: 1.45; margin: 0; padding: 1.5rem; }
        h1 { font-size: 1.5rem; margin: 0 0 0.25rem; }
        h2 { font-size: 1.15rem; margin: 1.5rem 0 0.5rem; border-bottom: 1px solid #ccc; padding-bottom: 0.25rem; break-after: avoid-page; page-break-after: avoid; }
        h3 { break-after: avoid-page; page-break-after: avoid; }
        .meta { color: #555; font-size: 0.9rem; margin-bottom: 1.25rem; }
        .attention { background: #fff8e6; border: 1px solid #e6d5a8; padding: 0.75rem 1rem; margin: 1rem 0; }
        .attention ul { margin: 0.35rem 0 0; padding-left: 1.25rem; }
        dl { margin: 0; }
        dt { font-weight: 700; margin-top: 0.65rem; }
        dd { margin: 0.15rem 0 0; color: #222; overflow-wrap: anywhere; }
        .instance { color: #666; font-size: 0.85rem; font-weight: normal; }
        .source { color: #666; font-size: 0.78rem; }
        .uncertainty { background: #fff8e6; border: 1px solid #e6d5a8; padding: 0.75rem 1rem; margin: 1rem 0; }
        .aerial { margin: 0 0 1rem; max-width: 720px; }
        .aerial-image { position: relative; line-height: 0; }
        .aerial-image img { display: block; width: 100%; height: auto; }
        .aerial-marker { position: absolute; left: 50%; top: 50%; width: 14px; height: 14px; margin: -9px 0 0 -9px; border: 2px solid #fff; border-radius: 50%; background: #dc2626; }
        .aerial figcaption { color: #666; font-size: 0.78rem; line-height: 1.35; margin-top: 0.35rem; }
        .photo-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.75rem; break-before: avoid-page; page-break-before: avoid; }
        .photo { break-inside: avoid; margin: 0; min-width: 0; }
        .photo img { display: block; width: 100%; height: 220px; object-fit: contain; background: #f3f4f6; }
        .photo figcaption { color: #555; font-size: 0.78rem; line-height: 1.35; margin-top: 0.3rem; overflow-wrap: anywhere; }
        .attachment { border: 1px solid #d1d5db; padding: 0.75rem; break-inside: avoid; min-width: 0; }
        .attachment a { color: #3730a3; font-weight: 700; overflow-wrap: anywhere; }
        .attachment p { color: #555; font-size: 0.78rem; line-height: 1.35; margin: 0.35rem 0 0; overflow-wrap: anywhere; }
    </style>
</head>
<body>
    <h1>Opnamerapport</h1>
    <p class="meta">
        {{ $intake->customer_name }} · {{ $intake->fullAddress() }}<br>
        {{ $version->template?->name }} · template v{{ $version->version }}<br>
        Gegenereerd {{ $generatedAt->timezone(config('app.timezone'))->format('d-m-Y H:i') }}
    </p>

    <h2>Korte samenvatting</h2>
    <p>{{ $dossierSummary }}</p>

    <h2>Klant en contact</h2>
    <dl>
        <dt>Naam</dt>
        <dd>{{ $intake->customer_name }}</dd>
        <dt>E-mail</dt>
        <dd>{{ $intake->customer_email }}</dd>
        <dt>Telefoon</dt>
        <dd>{{ $intake->customer_phone ?: '—' }}</dd>
        <dt>Adres</dt>
        <dd>{{ $intake->fullAddress() }}</dd>
    </dl>

    <h2>Automatisch verzamelde informatie</h2>
    @if ($externalData['aerial_image'])
        <figure class="aerial">
            <div class="aerial-image">
                <img src="{{ $externalData['aerial_image']['data_uri'] }}" alt="Luchtfoto rond de BAG-locatie van deze opname">
                <span class="aerial-marker"></span>
            </div>
            <figcaption>
                Rode markering: BAG-locatie
                @if ($externalData['aerial_image']['ground_width_meters'] && $externalData['aerial_image']['ground_height_meters'])
                    · circa {{ $externalData['aerial_image']['ground_width_meters'] }} × {{ $externalData['aerial_image']['ground_height_meters'] }} meter
                @endif
                · Bron:
                @if ($externalData['aerial_image']['source_url'])
                    <a href="{{ $externalData['aerial_image']['source_url'] }}">{{ $externalData['aerial_image']['source'] }}</a>
                @else
                    {{ $externalData['aerial_image']['source'] }}
                @endif
            </figcaption>
        </figure>
    @endif

    @if ($externalData['facts'] !== [])
        <dl>
            @foreach ($externalData['facts'] as $fact)
                <dt>{{ $fact['label'] }}</dt>
                <dd>
                    {{ $fact['display'] }}<br>
                    <span class="source">
                        Bron:
                        @if ($fact['source_url'])
                            <a href="{{ $fact['source_url'] }}">{{ $fact['source'] }}</a>
                        @else
                            {{ $fact['source'] }}
                        @endif
                        · {{ $fact['confidence'] }}
                    </span>
                </dd>
            @endforeach
        </dl>
    @else
        <p>Geen externe gegevens beschikbaar.</p>
    @endif

    @if ($externalData['uncertainties'] !== [])
        <div class="uncertainty">
            <strong>Open vragen en onzekerheden</strong>
            <ul>
                @foreach ($externalData['uncertainties'] as $uncertainty)
                    <li>{{ $uncertainty }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($photoGroups !== [])
        <h2>Aangeleverde foto’s en bestanden</h2>
        @foreach ($photoGroups as $group)
            <h3>{{ $group['heading'] }}</h3>
            <div class="photo-grid">
                @foreach ($group['uploads'] as $item)
                    @if (str_starts_with($item['upload']->mime_type, 'image/'))
                        <figure class="photo">
                            <img
                                src="{{ route('installer.uploads.show', [$intake, $item['upload']], false) }}"
                                data-intake-upload-id="{{ $item['upload']->id }}"
                                alt="{{ $item['caption'] }}"
                            >
                            <figcaption>
                                {{ $item['caption'] }} · {{ $item['upload']->original_filename }}<br>
                                Bron: aangeleverd door klant
                                @if ($item['upload']->followUpItem?->round)
                                    · aanvulling ronde {{ $item['upload']->followUpItem->round->round_number }}
                                @endif
                            </figcaption>
                        </figure>
                    @else
                        <div class="attachment">
                            <a href="{{ route('installer.uploads.show', [$intake, $item['upload']]) }}">{{ $item['upload']->original_filename }}</a>
                            <p>
                                {{ $item['caption'] }} · PDF · {{ number_format($item['upload']->size_bytes / 1024, 0, ',', '.') }} KB<br>
                                Bron: aangeleverd door klant
                                @if ($item['upload']->followUpItem?->round)
                                    · aanvulling ronde {{ $item['upload']->followUpItem->round->round_number }}
                                @endif
                            </p>
                        </div>
                    @endif
                @endforeach
            </div>
        @endforeach
    @endif

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

    @if (! empty($aiSummary))
        <div class="ai-advice" style="background:#f0f6fb;border:1px solid #b9d0e4;padding:0.75rem 1rem;margin:1rem 0;">
            <strong>AI-voorstel (niet bindend)</strong>
            <p style="margin:0.5rem 0 0;">{{ $aiSummary['summary'] }}</p>
            @if (! empty($aiSummary['highlights']))
                <ul style="margin:0.5rem 0 0;padding-left:1.25rem;">
                    @foreach ($aiSummary['highlights'] as $highlight)
                        <li>{{ $highlight }}</li>
                    @endforeach
                </ul>
            @endif
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

    @if ($followUpRounds->isNotEmpty())
        <h2>Aanvullende informatie</h2>
        @foreach ($followUpRounds as $round)
            <h3>Ronde {{ $round->round_number }}</h3>
            <dl>
                @foreach ($round->items as $item)
                    <dt>{{ $item->prompt }}</dt>
                    <dd>
                        @if ($item->type === \App\Enums\FollowUpItemType::Text)
                            {{ $item->response_text ?: '—' }}
                        @elseif ($item->type === \App\Enums\FollowUpItemType::Photo)
                            {{ $item->uploads->count() }} {{ $item->uploads->count() === 1 ? 'aanvullende foto' : "aanvullende foto's" }}
                        @else
                            {{ $item->uploads->count() }} {{ $item->uploads->count() === 1 ? 'aangeleverd document' : 'aangeleverde documenten' }}
                        @endif
                        <br><span class="source">Bron: aangeleverd door klant · ronde {{ $round->round_number }}</span>
                    </dd>
                @endforeach
            </dl>
        @endforeach
    @endif

    <h2>Voorstel volgende stap</h2>
    <p>{{ $nextStep }}</p>
</body>
</html>
