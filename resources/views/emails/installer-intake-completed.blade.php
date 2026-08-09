<x-mail::message>
# Opname afgerond

Hallo,

{{ $customerName }} heeft de opname afgerond@if ($address) ({{ $address }})@endif. De opname staat klaar om te bekijken.

@if ($completedAt)
Afgerond op {{ $completedAt->timezone(config('app.timezone'))->format('d-m-Y H:i') }}.
@endif

<x-mail::button :url="$intakeUrl">
Open de opname
</x-mail::button>

Met vriendelijke groet,  
{{ $appName }}
</x-mail::message>
