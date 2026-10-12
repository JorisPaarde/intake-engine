{{-- Single connection/route list item for the intake HTML report. --}}
<li>
    {{ $route['type_label'] }} · {{ $route['label'] }}
    ({{ $route['from'] }} → {{ $route['to'] }})
    <br><strong>Status:</strong> {{ $route['status_label'] }}
    @if (! empty($route['installer_note']))
        <br><strong>Notitie:</strong> {{ $route['installer_note'] }}
    @endif
    @if (($route['uncertainties'] ?? []) !== [])
        <br><strong>Open onzekerheden:</strong> {{ implode(' · ', $route['uncertainties']) }}
    @endif
    @if ($route['segments'] !== [])
        <ul style="margin:0.25rem 0 0;padding-left:1.25rem;">
            @foreach ($route['segments'] as $segment)
                <li>{{ $segment }}</li>
            @endforeach
        </ul>
    @endif
</li>
