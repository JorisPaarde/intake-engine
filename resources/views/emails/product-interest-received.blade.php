<x-mail::message>
# Nieuwe interesse in Digitale Opname

Er is via de publieke landingspagina een nieuwe aanvraag voor een kennismaking binnengekomen.

- **Bedrijf:** {{ $interest->company_name }}
- **Naam:** {{ $interest->contact_name }}
- **E-mail:** {{ $interest->email }}
- **Telefoon:** {{ $interest->phone ?: 'Niet ingevuld' }}

@if ($interest->message)
**Toelichting**

{{ $interest->message }}
@endif

Reageer rechtstreeks op deze e-mail om contact op te nemen.
</x-mail::message>
