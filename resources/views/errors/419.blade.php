@php
    $title = 'Sessie verlopen';
    $body = 'Je sessie is verlopen. Vernieuw de pagina en probeer het opnieuw. Was je bezig met opslaan? Controleer of je wijzigingen nog staan.';
@endphp
@include('errors._layout', ['title' => $title, 'heading' => $title, 'body' => $body, 'status' => 419])
