<x-mail::message>
# Je opname staat klaar

Hallo {{ $customerName }},

Met jouw hulp kunnen we sneller je airco plaatsen. Bekende woninggegevens hebben we al toegevoegd. Via de knop hieronder vraag je alleen wat we nog nodig hebben. Dat kan op je telefoon. Je kunt later gewoon verdergaan.

<x-mail::button :url="$customerUrl">
Open je opname
</x-mail::button>

@if ($expiresAt)
Deze link is geldig tot {{ $expiresAt->timezone(config('app.timezone'))->format('d-m-Y') }}.
@endif

Werkt de knop niet? Kopieer dan deze link in je browser:

{{ $customerUrl }}

Met vriendelijke groet,  
{{ $appName }}
</x-mail::message>
