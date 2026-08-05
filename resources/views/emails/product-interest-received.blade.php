@php
    $isDemoPdfLead = str_contains((string) $interest->message, 'source=demo_pdf_request');
@endphp
<x-mail::message>
# {{ $isDemoPdfLead ? 'Demo-lead: PDF-aanvraag' : 'Nieuwe interesse in Digitale Opname' }}

@if ($isDemoPdfLead)
Er is via de publieke installateursdemo een demorapport als PDF aangevraagd. Behandel dit als lead / kennismakingsaanvraag.
@else
Er is via de publieke landingspagina een nieuwe aanvraag voor een kennismaking binnengekomen.
@endif

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
