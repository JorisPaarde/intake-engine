# ADR-0008: Dev-admin alleen op staging/lokaal, hard uit in productie

- **Status:** Accepted
- **Datum:** 2026-07-22

## Context

Op staging moet controleerbaar zijn of de externe APIs (PDOK, Kadaster BAG, EP-Online, 3DBAG, AI, mail) werken en welke data er bij een opname binnenkwam. Die data bestaat al — `intake_external_facts`, `ai_runs`, `intake_activity_events`, `intake_answers`, `intake_uploads` — maar is nergens leesbaar bijeen; verificatie gebeurde via `/health`, logs en handmatige `docs/functional-test-status.md`-regels.

Een inzagepaneel dat dit toont, laat onvermijdelijk **ruwe klantdata (PII)** zien: antwoordwaarden, adres, naam, e-mail, uploads. Dat botst bewust met de privacy-keuzes elders: activity-events en `ai_runs` bewaren juist géén antwoordwaarden maar alleen keys/`input_hash` (ADR-0002), en externe feiten dragen herkomst en zekerheid (ADR-0007). Een inzagepaneel dat wél de ruwe waarden toont, hoort daarom nooit op productie met echte klantdata te draaien.

## Beslissing

- Er komt een **dev-admin** onder de routegroep `/dev`, los van de installateursflow (dashboard/metrics), voor het inzien van dienststatus en ruwe opname-data.
- Toegang is **omgevings-gated**: standaard aan op `local` en `staging`, en op `production` **automatisch uit** — de default van `config('devadmin.enabled')` sluit `production` uit, er is dáár geen env-var voor nodig. Met `DEV_ADMIN_ENABLED=false` is hij ook op staging uit te zetten.
- De gate (`EnsureDevAccess`) geeft buiten die omgevingen bewust **404** in plaats van 403, zodat de route in productie niet bestaat of lekt. Bovenop de gate geldt de gewone `auth`+`verified`-login; er is geen apart rollenmodel.
- De navigatielink verschijnt alleen wanneer `config('devadmin.enabled')`, dus in productie is de dev-admin ook onzichtbaar.
- De "werken de APIs?"-weergave doet **geen live calls**: status wordt passief afgeleid uit config-vlaggen en de laatst opgeslagen resultaten (externe feiten per bron, AI-runs per provider). Zo kost inzage geen quota/rate-limit en toont het wat er echt is binnengekomen.

## Alternatieven

| Alternatief | Afgewezen omdat |
|-------------|-----------------|
| Dev-admin ook op productie achter e-mail-allowlist | Toont echte klant-PII buiten grondslag; onnodig risico voor een puur diagnostisch hulpmiddel |
| 403 in plaats van 404 buiten staging | Bevestigt het bestaan van de route; 404 laat hem in productie simpelweg niet bestaan |
| Live API-pings vanuit het paneel | Kost quota/rate-limit en kan neveneffecten hebben; de opgeslagen resultaten tonen al of het werkt |
| Uitbreiden van `/metrics` of `/health` | Vermengt diagnostiek met de installateursflow en zou PII in de gewone flow trekken |

## Gevolgen

- Op staging/lokaal is per opname te zien welke externe feiten, AI-runs, antwoorden en events binnenkwamen, plus een passieve dienststatus — zonder de normale flow te vervuilen.
- In productie bestaat `/dev` niet (404) en ontbreekt de navigatielink; er lekt geen klant-PII via dit paneel.
- Nieuwe inzage-onderdelen horen onder dezelfde gate te vallen; toon er nooit iets dat op productie zichtbaar zou moeten zijn.
