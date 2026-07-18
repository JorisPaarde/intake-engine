<x-mail::message>
# Je digitale opname staat klaar

Hallo {{ $customerName }},

Je installateur heeft een digitale opname voor je klaargezet. Via de knop hieronder vul je de vragen in en lever je foto’s aan — dat kan op je telefoon, en je kunt later gewoon verdergaan.

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
