# ADR-0010: Bedrijfstenancy en begrensde white-label branding

- **Status:** Accepted
- **Datum:** 2026-07-25
- **Vervangt:** ADR-0006

## Context

Het product gaat van één virtueel installatiebedrijf naar meerdere zelfstandige installatiebedrijven. Ieder bedrijf moet eigen gebruikers, aanvragen, private media en een herkenbare huisstijl hebben. Branding zonder harde gegevensscheiding zou een tenantlek zijn; tenantisolatie en branding worden daarom als één architectuurgrens ingevoerd.

## Beslissing

- `companies` is de tenantbron. `users.company_id` en `intakes.company_id` zijn verplicht na de datamigratie.
- Gebruikers werken uitsluitend binnen hun eigen bedrijf. Policies en expliciet gescopete queries beschermen iedere installateursactie; route-modelbinding alleen is onvoldoende.
- Klanttoegang blijft tokengebonden aan exact één intake. De bijbehorende bedrijfsstijl wordt uitsluitend via die intake bepaald.
- Logo's blijven op de private mediadisk en worden via geautoriseerde/tokengebonden routes geleverd.
- Kleurafleiding gebeurt lokaal en deterministisch. Alleen gecontroleerde CSS-variabelen worden dynamisch; contrastkleur en fallbacks worden server-side bewaakt.
- Het visuele systeem gebruikt vaste neutrale oppervlakken en systeemtypografie. Tenantkleuren sturen acties en focus, niet de volledige componentstructuur. Glas-, blur- en translucency-effecten worden niet gebruikt.

## Gevolgen

- Iedere nieuwe tenantgebonden tabel of query moet tenantisolatietests krijgen.
- Bestaande users en intakes worden reproduceerbaar gemigreerd zonder handmatige productie-edit.
- Meerdere medewerkers per bedrijf zijn relationeel mogelijk; uitnodigingen en platformbeheer zijn aparte vervolgslices.
- Branding blijft herkenbaar en toegankelijk, ook wanneer een logo geen bruikbare kleur bevat.