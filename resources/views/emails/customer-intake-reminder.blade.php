<x-mail::message>
# Herinnering: uw opname

Hallo {{ $customerName }},

Met uw hulp kunnen we sneller uw airco plaatsen. U hebt nog een openstaande opname. Via de knop hieronder kunt u verdergaan waar u was. Dat kan op uw telefoon.

<x-mail::button :url="$customerUrl">
Ga verder met uw opname
</x-mail::button>

@if ($expiresAt)
Deze link is geldig tot {{ $expiresAt->timezone(config('app.timezone'))->format('d-m-Y') }}.
@endif

Werkt de knop niet? Kopieer dan deze link in uw browser:

{{ $customerUrl }}

Met vriendelijke groet,  
{{ $appName }}
</x-mail::message>
