<x-mail::message>
# Opname afgerond

Hallo,

{{ $customerName }} heeft de digitale opname afgerond@if ($address) ({{ $address }})@endif. Het dossier staat klaar om te beoordelen.

@if ($completedAt)
Afgerond op {{ $completedAt->timezone(config('app.timezone'))->format('d-m-Y H:i') }}.
@endif

<x-mail::button :url="$intakeUrl">
Open het dossier
</x-mail::button>

Met vriendelijke groet,  
{{ $appName }}
</x-mail::message>
