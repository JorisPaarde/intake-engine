@php
    $title = 'Er ging iets mis';
    $body = 'Er is een technische fout opgetreden. Probeer het zo opnieuw. Blijft het misgaan? Laat het je contactpersoon weten.';
@endphp
@include('errors._layout', ['title' => $title, 'heading' => $title, 'body' => $body, 'status' => 500])
