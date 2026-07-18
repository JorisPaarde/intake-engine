# Changelog

Alle noemenswaardige wijzigingen aan dit project. Bijhouden is verplicht per PR — zie [AGENTS.md](AGENTS.md).

## [Unreleased]

### Fixed

- Klantintake: verplichte Ja/Nee-vragen (Livewire `"1"`/`"0"`) werden niet als beantwoord gezien → “Volgende”/afronden geblokkeerd (`AnswerValueReader`).
- Foto-upload ververste alleen nog de betreffende form-composite i.p.v. de hele form-state te wissen.
- “Nieuwe link genereren” diende het formulier niet in (`x-secondary-button` default `type=button` → nu `type=submit`).

### Added

- BL-001 publieke demo: homepage **Start demo** (`POST /demo/start`) maakt een tijdelijke airco-intake + klantlink (`is_demo`, TTL, watermerk, geen AI-job); hourly purge via `intakes:purge-demos`. Feature-flag `DEMO_ENABLED` (default uit).
- `public/.user.ini` met PHP upload-limieten (`upload_max_filesize=10M`, `post_max_size=12M`) voor cPanel/LiteSpeed web-requests (BL-003).
- `/health` exposeert `php_upload` (ini + app-limiet) zodat staging-limieten remote te meten zijn zonder SSH.
- `AGENTS.md`: projectgeheugen met vast hoofddoel en vast ontwerpprincipe (alleen door producteigenaar aan te passen), snelstart-leesroutine en taakrouting (gericht lezen i.p.v. alles doorzoeken), documentkaart (bron van waarheid per onderwerp), versioneringsregels en verplicht onderhoudsprotocol voor agents.
- Documentversieheaders (`Documentversie` + `Laatste update`) op alle beheerde docs, README en AGENTS.md.
- Fase 6 AI-slice: `ai_runs`, null/fake/heuristic clients, `SummarizeIntake` job na afronding, AI-voorstel in HTML-rapport (soft-fail).
- Fase 5: `CompletenessChecker`, `CompleteIntake`, HTML-rapport (`generated_reports`), system attention points, klant-afronden + bedankt-scherm, installer review (`SubmitIntakeReview` / `ReviewDecision`).
- `docs/backlog.md` + GitHub issue #5: demo-versie van de app (backlog).
- Fase 4 foto-uploads: private storage, Livewire upload/preview/verwijderen, beveiligde serve-routes, validatie, installer-galerij.
- Deploy activeert na migraties ook `IntakeTemplateSeeder` (idempotente template-reference-data).
- Fase 3 klantintake: beveiligde link `/o/{token}`, Livewire-stappenflow, autosave, hervatten, conditionele vragen, voortgang.
- Producthomepage op `/` met korte uitleg, navigatie naar login/register en dashboard voor ingelogde gebruikers.
- Fase 2 interne basis: intake-schema, airco-template v1, dashboard, opname aanmaken, klantlink kopiëren/intrekken/herniewen, seeddata, feature tests.

### Changed

- `docs/backlog.md` v3.5: feedback producteigenaar verwerkt — te veel intakevragen en slimmer verzamelen. Nieuwe items BL-018 (vraag-voor-vraag klantflow), BL-019 (afleiden uit adres/openbare bronnen: satellietbeeld, BAG) en BL-020 (foto-gedreven afleiding en adaptieve vervolgvragen, bv. meterkastfoto → vrije groep); BL-017 (template-audit) prioriteit medium → high met concrete schrap-/vervangkandidaten; volgorde-advies bijgewerkt.
- `docs/intake-engine.md` v1.2: uitbreidingspunten verwijzen naar backlog-items (BL-016 t/m BL-020); geplande vraag-voor-vraag flow (BL-018) genoemd bij de sectieweergave.
- `docs/backlog.md` v3.4: BL-001 → `in_progress` (Start demo-pad).
- `docs/functional-test-status.md` v1.3: todo-regels voor publieke demo + purge (BL-001).
- `docs/DEPLOYMENT.md` v1.4 + `.env.staging.example` / `.env.production.example`: `DEMO_*`-flags.
- README → v1.6: BL-001 demo-pad genoemd.
- `docs/uploads.md` v1.3 + `docs/DEPLOYMENT.md` v1.3: staging-meting 2026-07-18 via `/health` (`512M`/`512M`); BL-003 afgerond (PR #13).
- `docs/functional-test-status.md` v1.2: sessie 2026-07-18 (BL-002 browserhertest) met bevindingen.
- `docs/backlog.md` v3.3: BL-003 → `done`, BL-002 → `in_progress` (merge PR #13 + BL-002-fixes).
- README → v1.5: BL-003 done; BL-002 browserhertest bezig.
- `docs/uploads.md` v1.2 + `docs/DEPLOYMENT.md` v1.2: upload-limieten via `.user.ini` als voorkeur; meetinstructie via `/health`.
- `docs/backlog.md` v3.1: BL-003 → `in_progress`.
- README → v1.4: statusregel BL-003 bijgewerkt.
- Documentstructuur ontdubbeld ("één bron per feit"): de geheugenkaart in AGENTS.md is nu de enige volledige documentkaart — de README-documentatietabel is vervangen door een verwijzing plus drie snelle ingangen; werkafspraken (branching, kwaliteit) hebben AGENTS.md § Werkafspraken als enige bron; README-secties Storage/Queues/Logging samengevoegd tot één verwijzende "Runtime"-sectie. README → v1.3, AGENTS.md → v1.1.
- `docs/backlog.md` v3.0: alle items gegroepeerd in vijf epics (E1 frictieloze basisflow, E2 communicatie zonder handwerk, E3 vraag minder/verzamel slimmer, E4 AI bespaart beoordeelwerk, E5 bruikbaar dossier & groei), elk expliciet herleid naar het vaste hoofddoel en ontwerpprincipe in AGENTS.md; nieuwe items BL-014 (afrondingsnotificatie), BL-015 (herinnering stilliggende intake), BL-016 (prefill/hergebruik bekende gegevens), BL-017 (airco-template v2 audit); BL-008 (HEIC) prioriteit low → medium.
- `docs/backlog.md` v2.0: geherstructureerd tot de enige projectbacklog met stabiele ID's (BL-001 t/m BL-013), status, prioriteit en afhankelijkheden; alle bekende uitgestelde items (demo, hertest, uploadlimieten, SMTP, PDF, externe LLM, HEIC, purge-job, production deploy, domein/SSL, multi-tenancy, S3) opgenomen.
- `docs/implementation-plan.md` gemarkeerd als afgeronde historie (Fase 1–6 klaar); nieuw werk hoort in de backlog.
- `docs/functional-test-status.md`: kapotte opmaak in sessienotities hersteld; todo-regels toegevoegd voor Fase 5/6-functionaliteit.
- README: status bijgewerkt (Fase 1–6 gemerged naar `main`), verwijzing naar AGENTS.md toegevoegd.
- `docs/ARCHITECTURE.md`: opgeloste trade-offs (Livewire, timezone, `MEDIA_DISK`) verwijderd; open trade-offs verwijzen naar backlog-ID's.

### Config

- `AI_PROVIDER`, `AI_API_KEY`, `AI_TIMEOUT_SECONDS`, `config/ai.php`
- `INTAKE_UPLOAD_MAX_KB`, `INTAKE_UPLOAD_MAX_FILES`
- `config/intake.php` uploads-sectie

### Known limitations

- Geen externe LLM-provider nog (alleen null/fake/heuristic); OpenAI e.d. later na DPIA.
- PDF-export van rapporten bewust later (HTML eerst; shared cPanel is geen betrouwbare PDF-host).
- HEIC niet in allowlist (alleen jpeg/png/webp).
- Geen automatische e-mail (staging mail = log); alleen kopieerbare link.
- Demo-user `installateur@example.com` ontbreekt op staging (deploy seedt alleen templates).
- Multi-tenancy bewust afwezig.
- Demo-versie: backlog (issue #5), nog niet gebouwd.

## [0.1.0] — infrastructuur + Fase 1 docs

### Added

- Fase 1 documentatie: productdoel, feitelijke stack, schema-ontwerp, intake-engine, uploads, AI-roadmap, implementatieplan.
- ADRs: templateversionering, klanttoegang zonder account, uploadbeveiliging, sync/async, AI uitgesteld, geen multi-tenancy in MVP.
- `docs/database.md` met Mermaid ER-diagram (ontwerp).
- Laravel-skelet, Breeze Blade auth, Livewire package, Pest/Pint/PHPStan.
- CI (Pint, PHPStan, Pest) en staging deploy via GitHub Actions + `deploy/activate.sh`.

### Changed

- README stackversies gecorrigeerd (Laravel 13.20).
- Architectuurdoc bijgewerkt aan auditbevindingen.
