<x-mail::message>
# Nog een paar gegevens nodig

Hallo {{ $customerName }},

Met uw hulp kunnen we sneller uw airco plaatsen. Uw installateur heeft nog een paar gerichte antwoorden, foto's of documenten nodig. Via dezelfde beveiligde link ziet u alleen wat nog echt ontbreekt.

<x-mail::button :url="$customerUrl">
Aanvraag aanvullen
</x-mail::button>

@if ($expiresAt)
Deze link is geldig tot {{ $expiresAt->timezone(config('app.timezone'))->format('d-m-Y') }}.
@endif

Werkt de knop niet? Kopieer dan deze link in uw browser:

{{ $customerUrl }}

Met vriendelijke groet,  
{{ $appName }}
</x-mail::message>
