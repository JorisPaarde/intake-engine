@php
    $title = 'Tijdelijk niet bereikbaar';
    $body = 'De server is even druk of in onderhoud. Wacht een moment en probeer het opnieuw. Je gegevens gaan niet verloren door deze melding.';
@endphp
@include('errors._layout', ['title' => $title, 'heading' => $title, 'body' => $body, 'status' => 503])
