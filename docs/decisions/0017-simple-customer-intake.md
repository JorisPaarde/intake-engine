# ADR-0017: Eenvoudige klantopname — de klant vertelt en fotografeert, de rest is installateurswerk

- **Status:** Accepted
- **Datum:** 2026-11-27
- **Raakt:** BL-172 · **Bouwt op:** ADR-0001 (immutabele templateversies), ADR-0013/0016 (catalogus-AI interpreteert vrije tekst), ADR-0015 (technische beslisvragen niet aan de klant)

## Context

Airco v33 vroeg de klant per ruimte naar type, maten, verdieping, zon, glas en binnenunitplek, plus woning, buitenunit, leidingroute, meterkast en condensafvoer. Een klant met drie slaapkamers zag zo tientallen schermen. Veel van die antwoorden staan al in de aanvraagtekst, de foto's, de BAG, het energielabel, 3DBAG of de luchtfoto, of zijn technische keuzes voor de installateur. De producteigenaar besloot op 27 nov 2026 de klantflow drastisch te vereenvoudigen, zonder vragen weg te gooien.

## Beslissing

1. **Klantflow (airco v34):** aanvraagtekst in eigen woorden → per ruimte één fotoscherm (met de optionele vraag aan welke gevelkant de ruimte ligt) → foto van de buitenunitplek → foto van de meterkast → één open wensenveld → versturen. Het aantal ruimtes wordt alleen gevraagd als het niet al uit de tekst komt.
2. **Niets weggooien:** alle andere vragen blijven in het template met `meta.audience = installer`. Ze staan in de AI-catalogus, worden gevuld door AI en externe bronnen, staan in het dossier en kunnen via een vervolgvraag alsnog aan de klant worden gesteld. Ze tellen niet mee voor klantcompleetheid (`CustomerAudience`), ook als ze in het template verplicht zijn.
3. **Wensenveld:** voorbeeldvragen staan in de template-meta (`wish_prompts` met `when_missing`); de wizard toont alleen voorbeelden voor onderwerpen die nog onbekend zijn. Geen eigen spraakknop; we wijzen op dicteren via het toetsenbord.
4. **Wensentekst is een tweede bron voor stated facts.** De catalogus-prefill krijgt `customer_wishes` en accepteert letterlijke quotes uit aanvraag óf wensen. Na versturen draait de prefill nog één keer (`PrefillFromCustomerWishesJob`, vóór samenvatting en dossiersynthese) en mag dan schrijven op een afgeronde opname, alleen vóór de installateursreview, alleen tekst-AI-bronnen, zonder het aantal ruimtes of de ruimtestructuur te veranderen.
5. **Aan/uit per templateversie** (`meta.customer_flow = simple` op `request_reason`). Opnames op v33 en ouder houden hun flow; er is geen tenant-toggle.

## Alternatieven

| Alternatief | Afgewezen omdat |
|-------------|-----------------|
| Vragen uit het template verwijderen | Kennis voor AI-catalogus, dossier en vervolgvragen gaat verloren; oudere opnames worden onleesbaar. |
| Tenant-instelling oude/nieuwe flow | Twee klantflows onderhouden; de producteigenaar wil de eenvoudige flow voor iedereen. |
| Eigen spraakknop (Web Speech API) | Browsersteun wisselt, privacyvragen; dicteren op het toetsenbord werkt al op elke telefoon. |
| Prefill pas bij installateursreview | De samenvatting en synthese na versturen zouden de wensen missen. |

## Gevolgen

- Minder klantinspanning (drie kamers: ±8 schermen i.p.v. tientallen); meer gewicht op foto-AI, tekst-AI en externe bronnen. Open punten lopen via de installateur (vervolgvraag) in plaats van via de klant.
- `known_summary` ("Dit weten we al") verdwijnt in v34; wat gevonden is, ziet de installateur in het dossier. Een klantgerichte "Dit hebben we gevonden"-pagina is apart werk (BL-174).
- Gevelkant + luchtfoto zijn input voor de zonoriëntatie; automatische oriëntatie uit BAG-contour + straatzijde is BL-173.
- v33-regressietests blijven draaien via `pinAircoTemplateVersion(33)`.
