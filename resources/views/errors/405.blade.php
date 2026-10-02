@php
    $title = 'Actie niet toegestaan';
    $body = 'Deze pagina kun je zo niet openen. Ga terug en gebruik de knop op de vorige pagina, of start opnieuw vanaf de homepage.';
@endphp
@include('errors._layout', ['title' => $title, 'heading' => $title, 'body' => $body, 'status' => 405])
