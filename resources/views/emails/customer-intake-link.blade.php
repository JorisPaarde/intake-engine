<x-mail::message>
# Uw opname staat klaar

Hallo {{ $customerName }},

Met uw hulp kunnen we sneller uw airco plaatsen. Bekende woninggegevens hebben we al toegevoegd. Via de knop hieronder vraagt u alleen wat we nog nodig hebben. Dat kan op uw telefoon. U kunt later gewoon verdergaan.

<x-mail::button :url="$customerUrl">
Open uw opname
</x-mail::button>

@if ($expiresAt)
Deze link is geldig tot {{ $expiresAt->timezone(config('app.timezone'))->format('d-m-Y') }}.
@endif

Werkt de knop niet? Kopieer dan deze link in uw browser:

{{ $customerUrl }}

Met vriendelijke groet,  
{{ $appName }}
</x-mail::message>
