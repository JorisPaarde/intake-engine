<x-mail::message>
# Herinnering: je digitale opname

Hallo {{ $customerName }},

Je hebt nog een openstaande digitale opname. Via de knop hieronder kun je verdergaan waar je was gebleven — dat kan op je telefoon.

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
