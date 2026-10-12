<x-mail::message>
# AI-alert{{ $isTest ? ' (test)' : '' }}

Korte melding over de externe AI-dienst.

- **App:** {{ $appName }}
- **Fouttype:** {{ $errorType->label() }}
@if ($httpStatus !== null)
- **HTTP-status:** {{ $httpStatus }}
@endif
- **Tijd:** {{ $occurredAt }}
- **Bericht:** {{ $sanitizedMessage }}

@if ($isTest)
Dit is een **testalert** (ai:alert-test). Er is geen echte storing.
@else
Controleer OpenRouter-saldo/key en de applicatielogs indien nodig. Secrets en tokens staan nooit in deze mail.
@endif
</x-mail::message>
