# Backlog — Digitale Opname

> **Documentversie:** 3.8 · **Laatste update:** 2026-07-18 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

De **enige backlog** van dit project: al het werk dat bewust niet in de afgeronde MVP-fasen 1–6 zit (zie `docs/implementation-plan.md`), plus nieuw ontdekt werk. Proces en statusregels: zie [AGENTS.md § Backlogproces](../AGENTS.md#backlogproces).

De items zijn gegroepeerd in **vijf epics**. Elke epic is herleid naar het vaste [hoofddoel](../AGENTS.md#hoofddoel-vast--niet-door-agents-aan-te-passen) (*met zo min mogelijk handelingen van aanvraag naar een bruikbaar dossier; voor iedere ontbrekende informatie de eenvoudigste manier van aanleveren*) en het vaste [ontwerpprincipe](../AGENTS.md#ontwerpprincipe-vast--niet-door-agents-aan-te-passen) (*niets vragen wat al bekend is of eenvoudiger kan worden vastgesteld; per ontbrekend gegeven de snelste en duidelijkste verzamelmethode*).

Status: `backlog` · `ready` · `in_progress` · `done` · `dropped` — prioriteit: `high` · `medium` · `low`

**Leeswijzer:** scan de epictabel en de overzichtstabel hieronder; open daarna alleen de detailsectie van het item waaraan je werkt.

## Epics

| Epic | Naam | Koppeling met hoofddoel / ontwerpprincipe |
|------|------|-------------------------------------------|
| E1 | Frictieloze basisflow | Elke haperende stap (mislukte foto-upload, SSL-waarschuwing, onbevestigde werking) kost de aanvrager extra handelingen of breekt het pad naar een bruikbaar dossier. |
| E2 | Communicatie zonder handwerk | Link doorsturen, afronding signaleren en nabellen zijn nu handmatige installateurshandelingen; die moeten verdwijnen. |
| E3 | Vraag minder, verzamel slimmer | Directe toepassing van het ontwerpprincipe op de intake zelf: hergebruik wat bekend is, kies per gegeven de snelste en duidelijkste verzamelmethode. |
| E4 | AI bespaart beoordeelwerk | Samenvatting, aandachtspunten en fotokwaliteitscheck besparen de installateur leeswerk en de aanvrager een extra aanleverronde. AI blijft ondersteunend (docs/ai.md). |
| E5 | Bruikbaar dossier & klaar voor groei | Het dossier moet buiten de browser bruikbaar zijn en het product moet zonder extra handelingen te ervaren, beheren en opschalen zijn. |

Volgorde-advies: volg kolom **#** in de overzichtstabel hieronder. De rode draad: eerst lopend werk afronden (BL-002, BL-001), dan de kern van het hoofddoel — minder en slimmere vragen (BL-017, BL-016; BL-018 done) en betrouwbare, drempelloze aanlevering (BL-008, BL-011) — daarna installateurshandelingen wegnemen (E2), vervolgens slimme afleiding (BL-019, BL-006, BL-020) en tot slot groei-/beheeritems.

## Overzicht

Geprioriteerd op het hoofddoel (herprioritering 2026-07-18): hoeveel handelingen bespaart of repareert een item in de kernflow van aanvrager en installateur, hoe direct, en voor hoeveel intakes? Kolom **#** is de aanbevolen uitvoeringsvolgorde (afhankelijkheden meegewogen); `done`/`dropped` staan onderaan zonder volgnummer.

| # | ID | Item | Epic | Status | Prioriteit |
|---|----|------|------|--------|------------|
| 1 | BL-002 | Functionele hertest staging (Fase 3–6) | E1 | in_progress | high |
| 2 | BL-001 | Demo-versie van de app | E5 | in_progress | medium |
| 3 | BL-017 | Airco-template v2: vraag-voor-vraag audit op het ontwerpprincipe | E3 | backlog | high |
| 4 | BL-016 | Hergebruik bekende gegevens (prefill) | E3 | backlog | high |
| 5 | BL-008 | HEIC-ondersteuning bij foto-uploads | E1 | backlog | high |
| 6 | BL-011 | Eigen domein + geldig SSL voor staging | E1 | backlog | high |
| 7 | BL-004 | Automatische e-mail van klantlink (SMTP) | E2 | backlog | medium |
| 8 | BL-014 | Afrondingsnotificatie voor de installateur | E2 | backlog | medium |
| 9 | BL-015 | Herinnering bij stilliggende intake | E2 | backlog | medium |
| 10 | BL-019 | Afleiden uit adres en openbare bronnen (satellietbeeld, BAG) | E3 | backlog | medium |
| 11 | BL-005 | PDF-export van rapporten | E5 | backlog | medium |
| 12 | BL-006 | Externe LLM-provider (na DPIA) | E4 | backlog | medium |
| 13 | BL-020 | Foto-gedreven afleiding en adaptieve vervolgvragen | E4 | backlog | medium |
| 14 | BL-007 | AI-uitbreidingen: attention points, fotokwaliteit, accepteren/verwijderen | E4 | backlog | low |
| 15 | BL-009 | Purge-job voor soft-deleted intakes (bewaartermijn) | E5 | backlog | low |
| 16 | BL-010 | Production-deployworkflow (tags + eigen omgeving) | E5 | backlog | low |
| 17 | BL-012 | Multi-tenancy (companies) | E5 | backlog | low |
| 18 | BL-013 | S3 als mediadisk | E5 | backlog | low |
| — | BL-018 | Vraag-voor-vraag klantflow (één vraag per scherm) | E3 | done | high |
| — | BL-003 | Staging PHP-uploadlimieten verifiëren/verhogen | E1 | done | high |

## Epic E1 — Frictieloze basisflow

De flow van Fase 1–6 belooft "zo min mogelijk handelingen", maar dat geldt alleen als elke stap ook echt werkt. Een mislukte iPhone-foto of een SSL-tussenscherm kost de aanvrager juist éxtra handelingen — het tegendeel van het hoofddoel. Foto's zijn bovendien onze snelste verzamelmethode (ontwerpprincipe), dus uploads moeten op elk toestel betrouwbaar zijn.

### BL-002 — Functionele hertest staging (Fase 3–6)

- **Status:** in_progress · **Prioriteit:** high · **Ref:** `docs/functional-test-status.md`
- **Doel:** de sinds de testsessie van 2026-07-17 gedeployde functionaliteit handmatig verifiëren op staging: producthomepage `/`, klantintake `/o/{token}`, foto-uploads, afronden + rapport + review, AI-samenvatting via queue, registratie + e-mailverificatie, end-to-end queue-job.
- **Voortgang (2026-07-18):** homepage, health, auth, registratie, opname+klantlink, klantwizard, foto-upload → **pass**. Geblokkeerd op afronden door boolean-validatiebug + regenerate-knop die niet POSTte; fixes in dezelfde PR. **Resterend na deploy:** hergenereren, volledige afronding → bedankt → rapport/review → AI/queue hertesten.
- **Afhankelijkheden:** geen meer — BL-003 is done (uploadlimieten op staging ok).
- **Let op:** resultaten alleen vastleggen in `docs/functional-test-status.md`, door de daadwerkelijk testende agent/tester.

### BL-003 — Staging PHP-uploadlimieten verifiëren/verhogen

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-18 · **PR:** #12 (limieten + `/health`), docs-afronding #13
- **Doel:** PHP-limieten ≥ app-limiet (5 MB): minimaal `upload_max_filesize=10M`, `post_max_size=12M`; gemeten waarden documenteren in `docs/uploads.md`.
- **Resultaat:** staging web-SAPI via `GET /health` → `php_upload`: `upload_max_filesize=512M`, `post_max_size=512M`, `max_file_uploads=20` (ruim boven minimum). `public/.user.ini` (10M/12M) blijft in git als vangnet; host staat hoger. Gemeten waarden in `docs/uploads.md`.
- **Waarom:** te lage PHP-limieten breken mobiele foto-uploads stil — en een mislukte upload is voor de aanvrager de duurste handeling die er is.

### BL-008 — HEIC-ondersteuning bij foto-uploads

- **Status:** backlog · **Prioriteit:** high *(opgehoogd 2026-07-18 bij hoofddoel-herprioritering: een stil mislukkende iPhone-foto is voor de aanvrager de duurste handeling die er is, en foto is onze snelste verzamelmethode — dit raakt een groot deel van álle intakes)*
- **Doel:** iPhones maken standaard HEIC-foto's; de allowlist is nu jpeg/png/webp. Onderzoek server-side conversie (Imagick op cPanel?) of client-side conversie vóór upload. De aanvrager mag nooit zelf hoeven converteren of instellingen omzetten.

### BL-011 — Eigen domein + geldig SSL voor staging

- **Status:** backlog · **Prioriteit:** high *(opgehoogd 2026-07-18 bij hoofddoel-herprioritering: dit kost élke aanvrager twee handelingen vóór de eerste vraag)*
- **Doel:** het tijdelijke `.cpanel.site`-domein (self-signed, "Technical Domain"-tussenscherm) vervangen door een eigen (sub)domein met Let's Encrypt. Daarna README-omgevingstabel bijwerken.
- **Waarom:** het tussenscherm en de browserwaarschuwing zijn twee extra handelingen (en een vertrouwensbreuk) vóór de aanvrager ook maar één vraag heeft gezien.
- **Afhankelijkheden:** extern — er moet een eigen (sub)domein aan het hostingaccount gekoppeld worden; actie producteigenaar/host.

## Epic E2 — Communicatie zonder handwerk

Tussen "installateur maakt opname aan" en "installateur beoordeelt dossier" zitten nu drie handmatige handelingen: de link zelf versturen, het dashboard checken op afgeronde intakes, en stilgevallen aanvragers nabellen. Elk daarvan kan het systeem overnemen.

### BL-004 — Automatische e-mail van klantlink (SMTP)

- **Status:** backlog · **Prioriteit:** medium
- **Doel:** klantlink automatisch mailen i.p.v. alleen kopieerbaar maken. Vereist werkende SMTP-configuratie (staging heeft nu `MAIL_MAILER=log`); daarna ook registratie/e-mailverificatie betrouwbaar.
- **Afhankelijkheden:** SMTP-account op host of externe mailprovider.
- **Let op:** tokens nooit in logs (ADR-0002); kopieerbare link blijft bestaan als fallback.

### BL-014 — Afrondingsnotificatie voor de installateur

- **Status:** backlog · **Prioriteit:** medium
- **Doel:** zodra de klant afrondt, krijgt de installateur een signaal (mail en/of dashboard-markering) zodat de beoordeling direct kan starten. Bespaart het periodiek handmatig checken van het dashboard.
- **Afhankelijkheden:** mailvariant vereist BL-004 (SMTP); een dashboard-markering ("nieuw afgerond") kan eerder.

### BL-015 — Herinnering bij stilliggende intake

- **Status:** backlog · **Prioriteit:** medium
- **Doel:** scheduled command: klant kreeg een link maar rondde niet af binnen N dagen → één automatische herinnering met dezelfde hervat-link. Bespaart de installateur het nabellen en de aanvrager het terugzoeken van de link.
- **Afhankelijkheden:** BL-004 (SMTP).
- **Niet doen:** herhaald mailen; maximaal één herinnering per intake, en stoppen bij ingetrokken/verlopen token.

## Epic E3 — Vraag minder, verzamel slimmer

De meest directe toepassing van het ontwerpprincipe: *de applicatie vraagt niets wat al bekend is of eenvoudiger kan worden vastgesteld*. Elke geschrapte of slimmer gestelde vraag is een blijvende besparing voor élke toekomstige aanvrager.

### BL-016 — Hergebruik bekende gegevens (prefill)

- **Status:** backlog · **Prioriteit:** high *(opgehoogd 2026-07-18 bij hoofddoel-herprioritering: de meest directe toepassing van het ontwerpprincipe — deterministisch, zonder externe afhankelijkheden, en pakt logisch mee met BL-017/BL-018)*
- **Doel:** gegevens die al bekend zijn nooit opnieuw aan de aanvrager vragen:
  - wat de installateur bij het aanmaken al invulde (bijv. aanleiding/klantcontext) vooraf tonen of overslaan;
  - afleidbare waarden berekenen i.p.v. uitvragen;
  - binnen repeatable secties (ruimtes) zinvolle antwoorden van de vorige instantie als voorzet aanbieden.
- **Kaders:** prefill is een voorzet, geen verborgen aanname — de aanvrager ziet en bevestigt wat is overgenomen. Deterministisch, geen LLM in deze keten (`docs/intake-engine.md`).

### BL-017 — Airco-template v2: vraag-voor-vraag audit op het ontwerpprincipe

- **Status:** backlog · **Prioriteit:** high *(opgehoogd 2026-07-18: producteigenaar signaleert dat de intake nu veel te veel vragen bevat)*
- **Doel:** elke vraag in de airco-template toetsen aan het ontwerpprincipe: is dit al bekend of afleidbaar (schrappen)? Is er een snellere/duidelijkere verzamelmethode (foto i.p.v. meetvraag, keuzelijst i.p.v. vrije tekst, boolean i.p.v. open vraag)? Feedback van installateurs meenemen.
- **Richting (feedback producteigenaar 2026-07-18):** het totaal aantal vragen moet fors omlaag. Kandidaten om te schrappen of te vervangen door een foto/afleiding:
  - kamermaten (`room_length_m`, `room_width_m`, `ceiling_height_m`) → op termijn inschatten uit `room_photos` (BL-020); tot die tijd op zijn minst optioneel of samengevoegd tot één indicatieve keuzevraag (klein/gemiddeld/groot);
  - `free_group_known` → afleidbaar uit `fusebox_photo` (BL-020): vraag alleen stellen als de foto ontbreekt of geen uitsluitsel geeft;
  - gevel/omgeving (`facade_overview_photo`, deels `outdoor_location`) → deels afleidbaar uit satellietbeeld op basis van adres (BL-019);
  - vrije-tekstvragen (`outdoor_accessibility`, `pipe_route_description`, `drain_location`) → waar mogelijk keuzelijst of foto-opdracht;
  - dubbele afstandsvragen (`distance_to_indoor`, `pipe_distance_indication`, `fusebox_distance`) → ontdubbelen of afleiden uit route-foto's.
- **Uitvoering:** nieuwe templateversie publiceren volgens ADR-0001; lopende/afgeronde intakes blijven op v1; keys stabiel houden.
- **Afhankelijkheden:** installateurs-feedback (kan uit BL-002/demo-gebruik komen). Verwijderen van vragen hoeft níet te wachten op BL-019/BL-020; schrappen kan direct, afleiden komt daarna.

### BL-018 — Vraag-voor-vraag klantflow (één vraag per scherm)

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-18 · **PR:** #18
- **Doel:** de klantwizard toont nu een hele sectie per scherm; de producteigenaar wil vragen **stap voor stap** stellen: één vraag (of één logisch mini-cluster, zoals een foto-opdracht met bijbehorende controle­vraag) per scherm, met autosave per antwoord en duidelijke voortgang.
- **Waarom (hoofddoel):** één vraag per scherm voelt lichter, werkt beter op mobiel en maakt conditionele logica direct zichtbaar (vervolgvraag verschijnt pas als die relevant is) — minder scrollen en minder afhaken.
- **Kaders:** de datastructuur (secties → vragen) blijft ongewijzigd; dit is een presentatielaag bovenop de bestaande engine. Sectietitels blijven als hoofdstukmarkering zichtbaar. Regels (`show`/`require`) evalueren per antwoord, zodat overgeslagen vragen nooit getoond worden.
- **Resultaat:** `IntakeStepBuilder` bouwt één stap per zichtbare vraag; wizard toont sectietitel + “Vraag X van Y”; hervatten via `current_question_key` / `current_section_instance_key`; conditionele vragen verschijnen/verdwijnen live uit de stappenlijst. Mini-clusters (foto + controlevraag) nog niet als apart meta-mechanisme — elke vraag is nu één scherm.
- **Afhankelijkheden:** geen harde; combineert goed met BL-017 (minder vragen) en BL-016 (prefill).

### BL-019 — Afleiden uit adres en openbare bronnen (satellietbeeld, BAG)

- **Status:** backlog · **Prioriteit:** medium
- **Doel:** het adres is al bekend bij het aanmaken van de opname (`intakes.address_*`); gebruik dat om vragen te schrappen of te verifiëren i.p.v. ze te stellen:
  - **Satelliet-/luchtfoto** (bijv. Google Maps Static API of PDOK-luchtfoto) tonen in het installateursrapport en als context bij de buitenunit-/gevelvragen — kan `facade_overview_photo` deels vervangen of de aanvrager alleen om bevestiging vragen ("klopt dit beeld van uw woning?");
  - **BAG/open data:** bouwjaar (`build_year`) en gebouwtype (`building_type`) zijn vaak uit openbare registers af te leiden; toon als voorzet die de aanvrager alleen bevestigt (kader BL-016: prefill is een voorzet, geen verborgen aanname).
- **Kaders:** afgeleide waarden zijn deterministisch of door de aanvrager bevestigd; API-keys via `.env`, nooit in git; kosten/quota van externe API's afwegen (PDOK/BAG is gratis en Nederlands, Google Maps betaald). Privacy: adres alleen naar externe API sturen als daar een verwerkingsgrondslag voor is — meenemen in dezelfde DPIA-lijn als BL-006.
- **Afhankelijkheden:** geen harde; rapportintegratie kan los van de klantflow. Bij externe API's: DPIA-afweging (zie BL-006).

## Epic E4 — AI bespaart beoordeelwerk

AI mag nooit bron van waarheid zijn (docs/ai.md, ADR-0005), maar kan wél handelingen schrappen: de samenvatting bespaart de installateur leeswerk, aandachtspunten-voorstellen versnellen de beoordeling, en een fotokwaliteitscheck voorkomt dat de aanvrager later een tweede aanleverronde moet doen.

### BL-006 — Externe LLM-provider (na DPIA)

- **Status:** backlog · **Prioriteit:** medium · **Ref:** ADR-0005, `docs/ai.md`
- **Doel:** OpenAI (of vergelijkbaar) client achter `AiClientInterface` naast null/fake/heuristic.
- **Blokkerend:** DPIA/akkoord en redactiestrategie voor persoonsgegevens — géén PII naar een provider vóór die er zijn.

### BL-007 — AI-uitbreidingen

- **Status:** backlog · **Prioriteit:** low · **Ref:** `docs/ai.md`
- **Doel:** `SuggestAttentionPoints`, `AssessPhotoUsability`, en UI waarmee de installateur AI-voorstellen accepteert of verwijdert. AI blijft ondersteunend, nooit bron van waarheid; niets blokkeert de kernflow.
- **Waarom (hoofddoel):** `AssessPhotoUsability` geeft de aanvrager direct feedback ("foto te donker — maak er nog één") zolang die tóch al bezig is — dat is één handeling nu i.p.v. een hele extra ronde later.
- **Afhankelijkheden:** BL-006 voor zinvolle kwaliteit (heuristic kan als tussenstap).

### BL-020 — Foto-gedreven afleiding en adaptieve vervolgvragen

- **Status:** backlog · **Prioriteit:** medium
- **Doel:** foto's niet alleen opslaan maar er informatie uit **afleiden**, zodat vragen vervallen of juist gericht gesteld worden. Voorbeelden (richting, geen letterlijke scope):
  - **Meterkastfoto:** herken of er een vrije groep is; zit de kast vol → stel gericht de vervolgvragen die daarbij horen (uitbreiding groepenkast, 1-fase/3-fase) en sla `free_group_known` als vraag over;
  - **Ruimtefoto's:** schat afmetingen/volume van de kamer in → kamermaatvragen (BL-017) vervallen of worden een te bevestigen voorzet;
  - **Route-/gevelfoto's:** schat leidinglengte en boringen in als voorzet voor de installateur.
- **Kaders (ADR-0005, docs/ai.md):** AI-uitkomsten zijn altijd een **voorzet** — de aanvrager of installateur bevestigt; deterministische regels (`show`/`require`) blijven de enige poort voor verplichte velden. Een AI-afleiding mag een vraag *invullen als voorzet* of een *conditionele vervolgvraag activeren via een bevestigd antwoord*, maar nooit stil een verplicht veld wegnemen. Foto-analyse loopt async (ADR-0004) en mag de flow nooit blokkeren: geen of trage analyse = gewoon de vraag stellen.
- **Uitvoering (gefaseerd):** eerst de template-kant (vragen conditioneel maken op een bevestigbaar afleidingsantwoord, via BL-017-versie), dan `AssessPhoto*`-acties achter `AiClientInterface`, dan de klantflow-integratie ("wij zien op uw foto X — klopt dat?").
- **Afhankelijkheden:** BL-006 (externe multimodale LLM + DPIA) voor betrouwbare beeldherkenning; BL-007 legt de `AssessPhotoUsability`-basis; BL-017/BL-018 voor de template- en flowkant.

## Epic E5 — Bruikbaar dossier & klaar voor groei

Het hoofddoel eindigt bij een **bruikbaar dossier**: bruikbaar in de offerte-flow van de installateur (ook buiten de browser), te ervaren door prospects zonder accountsetup, en veilig te beheren en op te schalen zodra meer bedrijven meedoen.

### BL-005 — PDF-export van rapporten

- **Status:** backlog · **Prioriteit:** medium
- **Doel:** naast het HTML-rapport (`generated_reports`) een PDF-pad, zodat het dossier direct in de offerte-/archiefflow van de installateur past zonder knip- en plakwerk. Bewust uitgesteld: shared cPanel is geen betrouwbare host voor zware PDF-generatie. Opties: lichte lib, externe render-service, of pas na hosting-upgrade. **Async** job (ADR-0004); HTML blijft bron.

### BL-001 — Demo-versie van de app

- **Status:** in_progress · **Prioriteit:** medium *(staat desondanks op #2 in de uitvoeringsvolgorde: het restwerk is klein — staging-flag + smoke-test — en lopend werk afronden gaat vóór nieuw werk starten)* · **Ref:** [issue #5](https://github.com/JorisPaarde/intake-engine/issues/5)
- **Doel:** publiek of semi-publiek demopad zodat prospects/installateurs het product kunnen ervaren zonder eigen accountsetup of echte klantdata — het hoofddoel ("zo min mogelijk handelingen") toegepast op de allereerste kennismaking.
- **Invulling (deze PR):** homepage **"Start demo"** → tijdelijke airco-intake + klantlink (`is_demo`, TTL via `DEMO_TTL_HOURS`, watermerk, geen AI-job, hourly `intakes:purge-demos`). Geen account nodig; fictieve `@demo.invalid`-e-mail. Feature-flag `DEMO_ENABLED` (default uit; aanzetten op staging).
- **Nog te doen na deploy:** `DEMO_ENABLED=true` in staging `shared/.env`, smoke-test Start demo → wizard → watermerk; daarna status → `done`.
- **Afhankelijkheden:** geen — klantflow (Fase 3), uploads (Fase 4) en rapport (Fase 5) zijn af.
- **Niet doen in demo:** echte mail naar willekeurige adressen, persistente PII van bezoekers zonder TTL.

### BL-009 — Purge-job voor soft-deleted intakes

- **Status:** backlog · **Prioriteit:** low *(verlaagd 2026-07-18 bij hoofddoel-herprioritering: bespaart geen handelingen in de kernflow; weer ophogen zodra er echte klantdata in productie staat — dan is de bewaartermijn een verplichting, koppel aan BL-010)* · **Ref:** `docs/database.md` (bewaartermijn-voorstel)
- **Doel:** voorgestelde bewaartermijn bekrachtigen en implementeren: 30 dagen na soft delete hard purge van dossier inclusief storage (foto's). Scheduled job + tests.

### BL-010 — Production-deployworkflow

- **Status:** backlog · **Prioriteit:** low *(verlaagd 2026-07-18 bij hoofddoel-herprioritering: bespaart nu geen handelingen; oppakken zodra een eerste echte klant/productiegang concreet is — zelfde trigger als BL-012. Neem BL-009 dan mee.)* · **Ref:** `docs/DEPLOYMENT.md`
- **Doel:** `deploy-production.yml` getriggerd op tags (`v*`), `PRODUCTION_*`-secrets, eigen `apps/intake-engine-production`-boom en database. Eerste release taggen als `v0.x` en CHANGELOG `[Unreleased]` afsluiten.

### BL-012 — Multi-tenancy (companies)

- **Status:** backlog · **Prioriteit:** low · **Ref:** ADR-0006
- **Doel:** bewust afwezig in MVP. Pas oppakken bij een concrete tweede klant/bedrijf: `companies`-tabel + tenant-scope op intakes en users.

### BL-013 — S3 als mediadisk

- **Status:** backlog · **Prioriteit:** low · **Ref:** `docs/uploads.md`
- **Doel:** `MEDIA_DISK=s3` + AWS-vars; bestaande rijen behouden `disk`+`path`. Pas nodig bij storagegroei of vertrek van cPanel.

## Afgerond / vervallen

`done`- en `dropped`-items blijven in de overzichtstabel en detailsecties hierboven staan als geheugen (met datum + PR).

| ID | Afgerond | PR |
|----|----------|-----|
| BL-018 | 2026-07-18 | #18 |
| BL-003 | 2026-07-18 | #12 (+ staging-verificatie via `/health`, docs #13) |
