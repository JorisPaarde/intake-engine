# ADR-0013: AI-prefill tegen de volledige templatevraagenset

- **Status:** Accepted
- **Datum:** 2026-08-11
- **Raakt:** BL-048, BL-063, BL-064

## Context

BL-048 introduceerde een begrensde lokale parser die evidente feiten uit de openingszin haalt (koelen, ruimtes, zolder). Daarna groeide de druk om steeds meer opties heuristisch te herkennen (buitenunitplekken, synoniemen). Dat schaalt niet: iedere keuzevraag heeft meerdere opties, formuleringen zijn open, en productregel is dat alleen met voldoende zekerheid wordt overgenomen wat de aanvrager al heeft verteld.

De producteigenaar wil daarom geen uitbreiding van regex-heuristiek. Alle vooraf bekende context moet naar een AI-model dat de **volledige vraagenset van de gepinde templateversie** kent, per vraag beoordeelt of er genoeg bewijs is, en alleen dan invult.

## Beslissing

1. **Primaire pad** (wanneer `AI_TEXT_INFERENCE_ENABLED` én externe call toegestaan): `PrefillAnswersFromKnownContext` bouwt
   - een **vraagcatalogus** uit de gepinde `IntakeTemplateVersion` (secties, types, opties; geen fotovragen als invuldoel);
   - een **bekende-contextpakket** (openingszin, bestaande antwoorden met bron, relevante externe feiten zonder identiteit/coördinaten);
   - en vraagt één versioned prompt (`request_prefill`) om per vraag een invulling met zekerheid.
2. **Toepassing:** `high` → automatische invulling met `prefill_source=ai` (vraag vervalt); `medium` → `ai_suggestion` (voorzet blijft zichtbaar); `low` of ontbrekend → niets. Optiewaarden moeten in de catalogus van díe templateversie bestaan. Menselijke/installateursantwoorden worden niet overschreven.
3. **Lokale parser** blijft alleen **offline-fallback** wanneer tekst-AI uit staat of externe calls verboden zijn (bijv. herstelpass op klantlink). Die parser wordt **niet** verder uitgebreid met nieuwe domeinheuristieken (zoals buitenunitplekken). Bestaande evidente koel-/ruimte-/zolderpatronen mogen blijven.
4. **Templateopties** (zoals `dormer` voor dakkapel) horen in een nieuwe templateversie (ADR-0001), zodat AI én UI dezelfde keuzes kennen — niet als hardcoded parser-enums.

## Alternatieven

| Alternatief | Afgewezen omdat |
|-------------|-----------------|
| Lokale parser blijven uitbreiden per optie | Combinatorische explosie; foutgevoelige synoniemen; botst met ontwerpprincipe. |
| Alleen de smalle `request_intent`-schema (rooms/cooling) | Mist buitenunit, bereikbaarheid en overige keuzevragen die al in de tekst staan. |
| AI zonder templatecatalogus | Model verzint keys/opties die niet bij de gepinde versie horen. |
| Lokale parser volledig verwijderen | Staging/demo zonder tekst-AI verliest evidente hergebruik; herstelpass zou niets doen. |

## Gevolgen

- Tekst-AI moet aan (plus provider/DPIA) voordat open buitenunitformuleringen betrouwbaar worden overgenomen.
- Nieuwe keuze-opties = templateversie + catalogus, niet parsercode.
- Featuretests dekken AI-pad met FakeAiClient én offline lokale fallback apart.
