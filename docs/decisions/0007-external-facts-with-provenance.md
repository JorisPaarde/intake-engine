# ADR-0007: Externe feiten met herkomst en zekerheid

- **Status:** Accepted
- **Datum:** 2026-07-20

## Context

Adres-, gebouw-, kaart- en latere beeldbronnen kunnen vragen vervangen en het dossier completer maken. Een losse kopie naar `intakes` of `intake_answers` verliest echter waar het gegeven vandaan kwam, wanneer het is opgehaald en hoe zeker het is. Zonder die context kan de installateur een afleiding niet verantwoord controleren.

## Beslissing

- Automatisch opgehaalde gegevens worden generiek opgeslagen in `intake_external_facts`, gekoppeld aan de intake.
- Elk feit bewaart machinekey, label, JSON-waarde, bron, bronreferentie/-URL, zekerheid en ophaaltijdstip.
- Een extern feit mag een vraag alleen overslaan als de gepinde template dit expliciet toestaat én het antwoord uit de vereiste bron komt. Airco v4 gebruikt dit eerst voor een eenduidig BAG-bouwjaar (`skip_when_prefilled_by=pdok`).
- Geen match, ambiguïteit of providerstoring wordt als onzekerheid in het dossier getoond; de handmatige vraag blijft de fallback.
- PDOK-verrijking gebeurt synchronisch met een korte timeout omdat de uitkomst vóór de klantflow nodig is. Falen is altijd soft-fail en blokkeert aanmaken/mailen niet.
- Een externe media-afleiding bewaart alleen disk/pad/MIME/afmetingen in `value`; bytes blijven op private storage. BL-019 gebruikt dit voor een server-side PDOK WMS-luchtfoto met zichtbare bron en locatiecentrum. Bovenaanzicht is context, geen bewijs voor gevel of exacte plaatsing.
- Externe feiten en mediabestanden vallen onder dezelfde bewaartermijn als de intake; `HardDeleteIntake` verwijdert de bestanden vóór cascade-delete. Uitgaande adres-/locatiebevraging is configureerbaar met `PDOK_ENABLED`/`PDOK_AERIAL_ENABLED` en vereist een passende grondslag voor echte klantdata.

## Alternatieven

| Alternatief | Afgewezen omdat |
|-------------|-----------------|
| Alleen afgeleide antwoorden opslaan | Bron, zekerheid en ophaaltijd verdwijnen; installateur kan het feit niet beoordelen |
| Provider-specifieke kolommen op `intakes` | Schaal slecht naar kaart-, luchtfoto- en beeldbronnen en maakt het kernmodel providergebonden |
| Altijd vragen en externe data alleen tonen | Bespaart de aanvrager geen handelingen bij een betrouwbare bron |
| Verrijking verplicht laten slagen | Externe storing zou de kernflow blokkeren |

## Gevolgen

- Rapport en AI-context kunnen aangeleverde en opgehaalde gegevens samen gebruiken zonder herkomstverlies.
- Nieuwe bronnen moeten hun feiten, zekerheid en onzekerheidsregels expliciet mappen.
- Lagere-confidence afleidingen blijven zichtbaar als te controleren voorzet of open vraag; ze mogen niet stil verplichte informatie vervangen.
