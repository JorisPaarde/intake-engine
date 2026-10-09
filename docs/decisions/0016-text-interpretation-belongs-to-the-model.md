# ADR-0016: Tekstinterpretatie hoort bij het AI-model

- **Status:** Accepted
- **Datum:** 2026-10-09
- **Raakt:** BL-148 · **Bouwt op:** ADR-0013 · **Vervangt:** ADR-0014 besluit 1 (altijd lokale parser vóór AI)
- **Notion:** IE: Tekstinterpretatie terug naar het AI-model (8 okt 2026)

## Context

ADR-0013 bepaalde dat de lokale parser alleen **offline-fallback** is en niet met nieuwe regex-heuristiek mag groeien. ADR-0014 besluit 1 zette daar hybrid tegenover: de lokale parser draaide altijd, ook met tekst-AI aan. Daarna groeide code-interpretatie buiten de parser (verdieping per ruimte, eigendom-injectie, koel-intent uit “geen airco”, claimherschrijving, hoogte-regex, retake-keywords). Dat botst met het ontwerpprincipe: betekenis van vrije tekst bepaalt het model; code bewaakt.

De producteigenaar herbevestigde dit op 8 okt 2026.

## Beslissing

1. **Met tekst-AI aan** (`AI_TEXT_INFERENCE_ENABLED` én externe call toegestaan) interpreteert **geen** applicatiecode vrije tekst. `LocalRequestIntentParser` draait **niet**. Catalogus-AI (`PrefillAnswersFromKnownContext`) is het enige interpretatiepad.
2. **Lokale parser** blijft **alleen fallback** wanneer tekst-AI uit staat of externe calls verboden zijn (klantlink-herstelpass, `allowExternal: false`). Die parser blijft bevroren: geen nieuwe patronen.
3. **Code mag alleen bewaken:** schema-/enum-/optievalidatie tegen de gepinde catalogus; `evidence_quote` letterlijk in de bron (anti-hallucinatie) zonder de betekenis van het citaat te beoordelen; invoervalidatie; privacyredactie. Code weigert of markeert onzeker; zij **herschrijft of vult geen betekenis in**.
4. **Nieuwe formulering die misgaat** → prompt, JSON-schema en `eval:interpretation` aanpassen. Geen nieuwe `preg_match`/keywordlijst in het interpretatiepad.
5. ADR-0014 besluit 2–4 blijven (herbeoordeling bij nieuwe context, bekende context, geen outdoor-heuristiek in de parser).

## Alternatieven

| Alternatief | Afgewezen omdat |
|-------------|-----------------|
| Hybrid houden (lokaal altijd, daarna AI) | Code vult betekenis in die het model hoort te leveren; regex groeit per incident. |
| Lokale parser volledig verwijderen | Staging/demo zonder tekst-AI en de herstelpass verliezen evidente fallback. |
| Keyword-guards houden “tot het model het kan” | Dat is opnieuw interpretatie; de evaluatieset is de regressiepoort. |

## Gevolgen

- Lege of zwakke modeloutput met tekst-AI aan wordt **niet** stil aangevuld door regex.
- `OwnershipNormalizer` map’t alleen korte modeltokens naar cataloguswaarden (`owned`/`rented`); scant geen aanvraagtekst.
- `RoomFloorLevelExtractor` blijft privégereedschap van de lokale parser, niet van catalogusclassificatie.
- `DerivedClaimConfidenceGuard` kapt zekerheid af of markeert onzeker; herschrijft geen zinnen.
- Vervolghoogtes komen uit prompt `follow_up_text`; getallen moeten in de brontekst staan.
- Evaluatieset: `php artisan eval:interpretation` (nachtjob / handmatig). Alleen mergen als `model_raw` en `pipeline_final` niet dalen op de geraakte feiten.
