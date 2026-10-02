@php
    $title = 'Geen toegang';
    $body = 'Je hebt geen rechten om deze pagina te openen. Log in met het juiste account of vraag je installateur om hulp.';
@endphp
@include('errors._layout', ['title' => $title, 'heading' => $title, 'body' => $body, 'status' => 403])
