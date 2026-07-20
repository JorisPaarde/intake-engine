<x-mail::message>
# Nog een paar gegevens nodig

Hallo {{ $customerName }},

Je installateur heeft je aanvraag bekeken en heeft nog een paar gerichte antwoorden, foto's of documenten nodig. Via dezelfde beveiligde link zie je alleen wat nog ontbreekt.

<x-mail::button :url="$customerUrl">
Aanvraag aanvullen
</x-mail::button>

@if ($expiresAt)
Deze link is geldig tot {{ $expiresAt->timezone(config('app.timezone'))->format('d-m-Y') }}.
@endif

Werkt de knop niet? Kopieer dan deze link in je browser:

{{ $customerUrl }}

Met vriendelijke groet,  
{{ $appName }}
</x-mail::message>
