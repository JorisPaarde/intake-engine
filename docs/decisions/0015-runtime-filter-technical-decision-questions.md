# ADR-0015: Runtime-filter voor technische beslisvragen op gepinde templates

- **Status:** Accepted
- **Datum:** 2026-10-03
- **Relates to:** ADR-0001

## Context

Klanttest 2026-10-02 (P0 / BL-116): de klant mag geen technische beslissingen nemen (condenspomp/afschot, leidingroute, doorboringen, elektrische voorziening). Nieuwe intakes krijgen airco v17 met `meta.installer_decision`. Lopende klantlinks op v1–v16 zouden die vragen anders nog tonen.

ADR-0001 verbiedt inhoudelijke wijziging van gepubliceerde templateversies.

## Beslissing

- Exporteer één gedeelde sleutellijst (`TechnicalDecisionKeys`) van technische beslisvragen.
- De klantwizard en klantcompleetheid filteren die sleutels **runtime** weg, ongeacht gepinde templateversie.
- Dit is een **gedragsfilter** op wie de vraag ziet/moet beantwoorden, geen inhoudswijziging van gepinde templates (keys, labels, rules in de DB blijven intact).
- Airco v17 zet daarnaast `meta.installer_decision` voor discoverability; de sleutellijst is leidend voor oudere versies.
- AI-antwoorden (`ai` / `ai_suggestion`) op deze sleutels zijn voorstellen: het open installateurspunt blijft staan tot een antwoord met bron `installer`.

## Alternatieven

| Alternatief | Afgewezen omdat |
|-------------|-----------------|
| Migratie van alle intakes naar v17 | Onnodig risico; breekt ADR-0001-geest voor lopende dossiers |
| Alleen meta op v17, geen filter op v1–v16 | Open klantlinks blijven technische ja/nee tonen |
| In-place edit van gepubliceerde v1–v16 | Verboden door ADR-0001 |

## Gevolgen

- Stroom 3 (tekst-prefill) hergebruikt `TechnicalDecisionKeys` om technische sleutels niet te prefillen voor de klant.
- Rapporten/dossier blijven antwoorden op de gepinde versie lezen; alleen de klantpresentatie wijzigt.
