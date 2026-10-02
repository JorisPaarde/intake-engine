<x-mail::message>
# Herinnering: je opname

Hallo {{ $customerName }},

Met jouw hulp kunnen we sneller je airco plaatsen. Je hebt nog een openstaande opname. Via de knop hieronder kun je verdergaan waar je was. Dat kan op je telefoon.

<x-mail::button :url="$customerUrl">
Ga verder met je opname
</x-mail::button>

@if ($expiresAt)
Deze link is geldig tot {{ $expiresAt->timezone(config('app.timezone'))->format('d-m-Y') }}.
@endif

Werkt de knop niet? Kopieer dan deze link in je browser:

{{ $customerUrl }}

Met vriendelijke groet,  
{{ $appName }}
</x-mail::message>
