# ADR-0014: Herbeoordeling prefill bij nieuwe context (hybrid heuristiek + AI)

- **Status:** Accepted
- **Datum:** 2026-08-11
- **Raakt:** BL-064, BL-065 · **Bouwt op:** ADR-0013

## Context

ADR-0013 introduceerde catalogusbewuste AI-prefill en hield de lokale parser als offline-fallback wanneer tekst-AI uit staat. In de praktijk:

1. Prefill draaide bij aanmaak **vóór** adresverrijking, waardoor BAG/EP-feiten ontbraken in de AI-context.
2. Latere context (hernieuwde BAG, installateursnotities, bijgewerkte openingszin) triggerde geen herbeoordeling.
3. Met tekst-AI aan werd de foutloze lokale heuristiek overgeslagen, terwijl die juist goedkoop en betrouwbaar is voor evidente koelen/ruimtes/zolder.

## Beslissing

1. **Hybrid pad in `DeriveIntentFromRequest`:**
   - Eerst altijd de bevroren lokale heuristiek toepassen wanneer er een openingszin is (foutloze patronen: koelen/verwarmen, aantallen, ruimtetypen, zolder).
   - Daarna, als tekst-AI aan én externe calls toegestaan, `PrefillAnswersFromKnownContext` laten beoordelen wat de catalogus nog kan vullen (keuzevragen zoals buitenunitplek).
2. **Herbeoordeling** bij groeiende context — opnieuw `DeriveIntentFromRequest` aanroepen wanneer:
   - adresverrijking (PDOK/BAG/EP/…) is afgerond (aanmaak én retry);
   - de openingszin opnieuw wordt opgeslagen;
   - een installateursobservatie/notitie wordt toegevoegd of aangepast.
3. **Bekende context** voor AI bevat naast openingszin/antwoorden/feiten ook beknopte, geredacteerde installateursobservaties.
4. Geen nieuwe outdoor- of keuzeheuristiek in de lokale parser (ADR-0013 blijft leidend voor “geen regex-explosie”).

## Alternatieven

| Alternatief | Afgewezen omdat |
|-------------|-----------------|
| Alleen AI, nooit lokale heuristiek bij AI-aan | Onnodige providerkosten/latentie voor evidente feiten; faalt soft als provider leeg teruggeeft. |
| Herbeoordeling bij élke antwoordsave | Iedere klantstap = nieuwe AI-call; inputhash verandert altijd. |
| Alleen bij aanmaak opnieuw ordenen (enrich→derive) | Lost retry/notities/openingszin-update niet op. |

## Gevolgen

- Create-flow: eerst verrijken, dan prefill (lokaal + AI).
- Idempotente AI-runs via inputhash blijven: ongewijzigde context herhaalt geen provider-call.
- Menselijke antwoorden blijven onaantastbaar; alleen `ai` / `ai_suggestion` / `request_text` mogen worden bijgesteld.
