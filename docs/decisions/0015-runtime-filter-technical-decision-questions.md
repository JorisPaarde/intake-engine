# ADR-0015: Runtime-filter voor technische beslisvragen op gepinde templates

- **Status:** Accepted
- **Datum:** 2026-10-03
- **Relates to:** ADR-0001

## Context

Klanttest 2026-10-02 (P0 / BL-116): de klant mag geen technische beslissingen nemen (condenspomp/afschot, leidingroute, doorboringen, elektrische voorziening). Nieuwe intakes gebruiken airco v17. Lopende klantlinks op v1–v16 zouden die vragen anders nog tonen.

ADR-0001 verbiedt inhoudelijke wijziging van gepubliceerde templateversies.

## Beslissing

- Exporteer één gedeelde sleutellijst (`TechnicalDecisionKeys`) van technische beslisvragen — dit is het **enige** klantfiltermechanisme.
- De klantwizard en klantcompleetheid filteren die sleutels **runtime** weg, ongeacht gepinde templateversie.
- In **klantmodus** telt een zichtbaarheidsregel waarvan de bron een `TechnicalDecisionKeys`-sleutel is als voldaan, zodat afhankelijke klantvragen (bijv. `drain_photo` op v16) zichtbaar blijven.
- Dit is een **gedragsfilter** op wie de vraag ziet/moet beantwoorden, geen inhoudswijziging van gepinde templates (keys, labels, rules in de DB blijven intact).
- AI-antwoorden (`ai` / `ai_suggestion`) en eventuele eerdere klantantwoorden op deze sleutels zijn context in open installateurspunten (`*_open`); ze sluiten die punten niet. Afhandeling vanuit de survey-werkplek volgt in BL-117.

## Alternatieven

| Alternatief | Afgewezen omdat |
|-------------|-----------------|
| Migratie van alle intakes naar v17 | Onnodig risico; breekt ADR-0001-geest voor lopende dossiers |
| Alleen meta-flag op v17, geen filter op v1–v16 | Open klantlinks blijven technische ja/nee tonen |
| In-place edit van gepubliceerde v1–v16 | Verboden door ADR-0001 |
| Parallelle `meta.installer_decision` naast de sleutellijst | Dubbel mechanisme; de sleutellijst volstaat |

## Gevolgen

- Stroom 3 (tekst-prefill) hergebruikt `TechnicalDecisionKeys` om technische sleutels niet te prefillen voor de klant.
- Rapporten/dossier blijven antwoorden op de gepinde versie lezen; alleen de klantpresentatie wijzigt.
