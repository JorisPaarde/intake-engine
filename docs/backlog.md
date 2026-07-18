# Backlog — Digitale Opname

> **Documentversie:** 3.2 · **Laatste update:** 2026-07-18 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

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

Volgorde-advies: E1 eerst (bevat de `ready`/`high` items), daarna E2 en E3; E4 en E5 op basis van klantvraag en DPIA.

## Overzicht

| ID | Item | Epic | Status | Prioriteit |
|----|------|------|--------|------------|
| BL-002 | Functionele hertest staging (Fase 3–6) | E1 | ready | high |
| BL-003 | Staging PHP-uploadlimieten verifiëren/verhogen | E1 | done | high |
| BL-008 | HEIC-ondersteuning bij foto-uploads | E1 | backlog | medium |
| BL-011 | Eigen domein + geldig SSL voor staging | E1 | backlog | medium |
| BL-004 | Automatische e-mail van klantlink (SMTP) | E2 | backlog | medium |
| BL-014 | Afrondingsnotificatie voor de installateur | E2 | backlog | medium |
| BL-015 | Herinnering bij stilliggende intake | E2 | backlog | medium |
| BL-016 | Hergebruik bekende gegevens (prefill) | E3 | backlog | medium |
| BL-017 | Airco-template v2: vraag-voor-vraag audit op het ontwerpprincipe | E3 | backlog | medium |
| BL-006 | Externe LLM-provider (na DPIA) | E4 | backlog | medium |
| BL-007 | AI-uitbreidingen: attention points, fotokwaliteit, accepteren/verwijderen | E4 | backlog | low |
| BL-005 | PDF-export van rapporten | E5 | backlog | medium |
| BL-001 | Demo-versie van de app | E5 | backlog | medium |
| BL-009 | Purge-job voor soft-deleted intakes (bewaartermijn) | E5 | backlog | medium |
| BL-010 | Production-deployworkflow (tags + eigen omgeving) | E5 | backlog | medium |
| BL-012 | Multi-tenancy (companies) | E5 | backlog | low |
| BL-013 | S3 als mediadisk | E5 | backlog | low |

## Epic E1 — Frictieloze basisflow

De flow van Fase 1–6 belooft "zo min mogelijk handelingen", maar dat geldt alleen als elke stap ook echt werkt. Een mislukte iPhone-foto of een SSL-tussenscherm kost de aanvrager juist éxtra handelingen — het tegendeel van het hoofddoel. Foto's zijn bovendien onze snelste verzamelmethode (ontwerpprincipe), dus uploads moeten op elk toestel betrouwbaar zijn.

### BL-002 — Functionele hertest staging (Fase 3–6)

- **Status:** ready · **Prioriteit:** high · **Ref:** `docs/functional-test-status.md`
- **Doel:** de sinds de testsessie van 2026-07-17 gedeployde functionaliteit handmatig verifiëren op staging: producthomepage `/`, klantintake `/o/{token}`, foto-uploads, afronden + rapport + review, AI-samenvatting via queue, registratie + e-mailverificatie, end-to-end queue-job.
- **Afhankelijkheden:** geen meer — BL-003 is done (uploadlimieten op staging ok).
- **Let op:** resultaten alleen vastleggen in `docs/functional-test-status.md`, door de daadwerkelijk testende agent/tester.

### BL-003 — Staging PHP-uploadlimieten verifiëren/verhogen

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-18 · **PR:** #12 (limieten + `/health`), follow-up docs deze PR
- **Doel:** PHP-limieten ≥ app-limiet (5 MB): minimaal `upload_max_filesize=10M`, `post_max_size=12M`; gemeten waarden documenteren in `docs/uploads.md`.
- **Resultaat:** staging web-SAPI via `GET /health` → `php_upload`: `upload_max_filesize=512M`, `post_max_size=512M`, `max_file_uploads=20` (ruim boven minimum). `public/.user.ini` (10M/12M) blijft in git als vangnet; host staat hoger. Gemeten waarden in `docs/uploads.md`.

### BL-008 — HEIC-ondersteuning bij foto-uploads

- **Status:** backlog · **Prioriteit:** medium *(opgehoogd van low: foto is onze snelste verzamelmethode en iPhones zijn een groot deel van de aanvragers)*
- **Doel:** iPhones maken standaard HEIC-foto's; de allowlist is nu jpeg/png/webp. Onderzoek server-side conversie (Imagick op cPanel?) of client-side conversie vóór upload. De aanvrager mag nooit zelf hoeven converteren of instellingen omzetten.

### BL-011 — Eigen domein + geldig SSL voor staging

- **Status:** backlog · **Prioriteit:** medium
- **Doel:** het tijdelijke `.cpanel.site`-domein (self-signed, "Technical Domain"-tussenscherm) vervangen door een eigen (sub)domein met Let's Encrypt. Daarna README-omgevingstabel bijwerken.
- **Waarom:** het tussenscherm en de browserwaarschuwing zijn twee extra handelingen (en een vertrouwensbreuk) vóór de aanvrager ook maar één vraag heeft gezien.

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

- **Status:** backlog · **Prioriteit:** medium
- **Doel:** gegevens die al bekend zijn nooit opnieuw aan de aanvrager vragen:
  - wat de installateur bij het aanmaken al invulde (bijv. aanleiding/klantcontext) vooraf tonen of overslaan;
  - afleidbare waarden berekenen i.p.v. uitvragen;
  - binnen repeatable secties (ruimtes) zinvolle antwoorden van de vorige instantie als voorzet aanbieden.
- **Kaders:** prefill is een voorzet, geen verborgen aanname — de aanvrager ziet en bevestigt wat is overgenomen. Deterministisch, geen LLM in deze keten (`docs/intake-engine.md`).

### BL-017 — Airco-template v2: vraag-voor-vraag audit op het ontwerpprincipe

- **Status:** backlog · **Prioriteit:** medium
- **Doel:** elke vraag in de airco-template toetsen aan het ontwerpprincipe: is dit al bekend of afleidbaar (schrappen)? Is er een snellere/duidelijkere verzamelmethode (foto i.p.v. meetvraag, keuzelijst i.p.v. vrije tekst, boolean i.p.v. open vraag)? Feedback van installateurs meenemen.
- **Uitvoering:** nieuwe templateversie publiceren volgens ADR-0001; lopende/afgeronde intakes blijven op v1; keys stabiel houden.
- **Afhankelijkheden:** installateurs-feedback (kan uit BL-002/demo-gebruik komen).

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

## Epic E5 — Bruikbaar dossier & klaar voor groei

Het hoofddoel eindigt bij een **bruikbaar dossier**: bruikbaar in de offerte-flow van de installateur (ook buiten de browser), te ervaren door prospects zonder accountsetup, en veilig te beheren en op te schalen zodra meer bedrijven meedoen.

### BL-005 — PDF-export van rapporten

- **Status:** backlog · **Prioriteit:** medium
- **Doel:** naast het HTML-rapport (`generated_reports`) een PDF-pad, zodat het dossier direct in de offerte-/archiefflow van de installateur past zonder knip- en plakwerk. Bewust uitgesteld: shared cPanel is geen betrouwbare host voor zware PDF-generatie. Opties: lichte lib, externe render-service, of pas na hosting-upgrade. **Async** job (ADR-0004); HTML blijft bron.

### BL-001 — Demo-versie van de app

- **Status:** backlog · **Prioriteit:** medium · **Ref:** [issue #5](https://github.com/JorisPaarde/intake-engine/issues/5)
- **Doel:** publiek of semi-publiek demopad zodat prospects/installateurs het product kunnen ervaren zonder eigen accountsetup of echte klantdata — het hoofddoel ("zo min mogelijk handelingen") toegepast op de allereerste kennismaking.
- **Mogelijke invulling:**
  - Vaste demo-installateur + vooraf gevulde airco-intake (read-only of resetbaar)
  - Of: "Start demo"-knop die een tijdelijke intake + klantlink aanmaakt en na X uur opruimt
  - Duidelijke watermerken: "Demo — geen echte offerte"
  - Geen productiegegevens; seed/fixtures alleen fictief
- **Afhankelijkheden:** geen — klantflow (Fase 3), uploads (Fase 4) en rapport (Fase 5) zijn af, dus de demo kan het eindproduct tonen.
- **Niet doen in demo:** echte mail naar willekeurige adressen, persistente PII van bezoekers zonder TTL.

### BL-009 — Purge-job voor soft-deleted intakes

- **Status:** backlog · **Prioriteit:** medium · **Ref:** `docs/database.md` (bewaartermijn-voorstel)
- **Doel:** voorgestelde bewaartermijn bekrachtigen en implementeren: 30 dagen na soft delete hard purge van dossier inclusief storage (foto's). Scheduled job + tests.

### BL-010 — Production-deployworkflow

- **Status:** backlog · **Prioriteit:** medium · **Ref:** `docs/DEPLOYMENT.md`
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
| BL-003 | 2026-07-18 | #12 (+ staging-verificatie via `/health`) |
