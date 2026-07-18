# Backlog — Digitale Opname

> **Documentversie:** 2.0 · **Laatste update:** 2026-07-17 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

De **enige backlog** van dit project: al het werk dat bewust niet in de afgeronde MVP-fasen 1–6 zit (zie `docs/implementation-plan.md`), plus nieuw ontdekt werk. Proces en statusregels: zie [AGENTS.md § Backlogproces](../AGENTS.md#backlogproces).

Status: `backlog` · `ready` · `in_progress` · `done` · `dropped` — prioriteit: `high` · `medium` · `low`

**Leeswijzer:** scan de overzichtstabel hieronder; open daarna alleen de detailsectie van het item waaraan je werkt.

## Overzicht

| ID | Item | Status | Prioriteit |
|----|------|--------|------------|
| BL-001 | Demo-versie van de app | backlog | medium |
| BL-002 | Functionele hertest staging (Fase 3–6) | ready | high |
| BL-003 | Staging PHP-uploadlimieten verifiëren/verhogen | ready | high |
| BL-004 | Automatische e-mail van klantlink (SMTP) | backlog | medium |
| BL-005 | PDF-export van rapporten | backlog | medium |
| BL-006 | Externe LLM-provider (na DPIA) | backlog | medium |
| BL-007 | AI-uitbreidingen: attention points, fotokwaliteit, accepteren/verwijderen | backlog | low |
| BL-008 | HEIC-ondersteuning bij foto-uploads | backlog | low |
| BL-009 | Purge-job voor soft-deleted intakes (bewaartermijn) | backlog | medium |
| BL-010 | Production-deployworkflow (tags + eigen omgeving) | backlog | medium |
| BL-011 | Eigen domein + geldig SSL voor staging | backlog | medium |
| BL-012 | Multi-tenancy (companies) | backlog | low |
| BL-013 | S3 als mediadisk | backlog | low |

## Items

### BL-001 — Demo-versie van de app

- **Status:** backlog · **Prioriteit:** medium · **Ref:** [issue #5](https://github.com/JorisPaarde/intake-engine/issues/5)
- **Doel:** publiek of semi-publiek demopad zodat prospects/installateurs het product kunnen ervaren zonder eigen accountsetup of echte klantdata.
- **Mogelijke invulling:**
  - Vaste demo-installateur + vooraf gevulde airco-intake (read-only of resetbaar)
  - Of: "Start demo"-knop die een tijdelijke intake + klantlink aanmaakt en na X uur opruimt
  - Duidelijke watermerken: "Demo — geen echte offerte"
  - Geen productiegegevens; seed/fixtures alleen fictief
- **Afhankelijkheden:** geen — klantflow (Fase 3), uploads (Fase 4) en rapport (Fase 5) zijn af, dus de demo kan het eindproduct tonen.
- **Niet doen in demo:** echte mail naar willekeurige adressen, persistente PII van bezoekers zonder TTL.

### BL-002 — Functionele hertest staging (Fase 3–6)

- **Status:** ready · **Prioriteit:** high · **Ref:** `docs/functional-test-status.md`
- **Doel:** de sinds de testsessie van 2026-07-17 gedeployde functionaliteit handmatig verifiëren op staging: producthomepage `/`, klantintake `/o/{token}`, foto-uploads, afronden + rapport + review, AI-samenvatting via queue, registratie + e-mailverificatie, end-to-end queue-job.
- **Afhankelijkheden:** BL-003 voor de upload-test (limieten moeten eerst kloppen).
- **Let op:** resultaten alleen vastleggen in `docs/functional-test-status.md`, door de daadwerkelijk testende agent/tester.

### BL-003 — Staging PHP-uploadlimieten verifiëren/verhogen

- **Status:** ready · **Prioriteit:** high
- **Doel:** op cPanel meten (`php -i | grep -E 'upload_max_filesize|post_max_size'`) en via MultiPHP INI Editor minimaal `upload_max_filesize=10M`, `post_max_size=12M` instellen; gemeten waarden documenteren in `docs/uploads.md`.
- **Waarom:** de applicatielimiet is 5 MB per foto; te lage PHP-limieten breken mobiele foto-uploads stil.

### BL-004 — Automatische e-mail van klantlink (SMTP)

- **Status:** backlog · **Prioriteit:** medium
- **Doel:** klantlink automatisch mailen i.p.v. alleen kopieerbaar maken. Vereist werkende SMTP-configuratie (staging heeft nu `MAIL_MAILER=log`); daarna ook registratie/e-mailverificatie betrouwbaar.
- **Afhankelijkheden:** SMTP-account op host of externe mailprovider.

### BL-005 — PDF-export van rapporten

- **Status:** backlog · **Prioriteit:** medium
- **Doel:** naast het HTML-rapport (`generated_reports`) een PDF-pad. Bewust uitgesteld: shared cPanel is geen betrouwbare host voor zware PDF-generatie. Opties: lichte lib, externe render-service, of pas na hosting-upgrade.

### BL-006 — Externe LLM-provider (na DPIA)

- **Status:** backlog · **Prioriteit:** medium · **Ref:** ADR-0005, `docs/ai.md`
- **Doel:** OpenAI (of vergelijkbaar) client achter `AiClientInterface` naast null/fake/heuristic.
- **Blokkerend:** DPIA/akkoord en redactiestrategie voor persoonsgegevens — géén PII naar een provider vóór die er zijn.

### BL-007 — AI-uitbreidingen

- **Status:** backlog · **Prioriteit:** low · **Ref:** `docs/ai.md`
- **Doel:** `SuggestAttentionPoints`, `AssessPhotoUsability`, en UI waarmee de installateur AI-voorstellen accepteert of verwijdert. AI blijft ondersteunend, nooit bron van waarheid.
- **Afhankelijkheden:** BL-006 voor zinvolle kwaliteit (heuristic kan als tussenstap).

### BL-008 — HEIC-ondersteuning bij foto-uploads

- **Status:** backlog · **Prioriteit:** low
- **Doel:** iPhones maken standaard HEIC-foto's; de allowlist is nu jpeg/png/webp. Onderzoek server-side conversie (Imagick op cPanel?) of client-side conversie vóór upload.

### BL-009 — Purge-job voor soft-deleted intakes

- **Status:** backlog · **Prioriteit:** medium · **Ref:** `docs/database.md` (bewaartermijn-voorstel)
- **Doel:** voorgestelde bewaartermijn bekrachtigen en implementeren: 30 dagen na soft delete hard purge van dossier inclusief storage (foto's). Scheduled job + tests.

### BL-010 — Production-deployworkflow

- **Status:** backlog · **Prioriteit:** medium · **Ref:** `docs/DEPLOYMENT.md`
- **Doel:** `deploy-production.yml` getriggerd op tags (`v*`), `PRODUCTION_*`-secrets, eigen `apps/intake-engine-production`-boom en database. Eerste release taggen als `v0.x` en CHANGELOG `[Unreleased]` afsluiten.

### BL-011 — Eigen domein + geldig SSL voor staging

- **Status:** backlog · **Prioriteit:** medium
- **Doel:** het tijdelijke `.cpanel.site`-domein (self-signed, "Technical Domain"-tussenscherm) vervangen door een eigen (sub)domein met Let's Encrypt. Daarna README-omgevingstabel bijwerken.

### BL-012 — Multi-tenancy (companies)

- **Status:** backlog · **Prioriteit:** low · **Ref:** ADR-0006
- **Doel:** bewust afwezig in MVP. Pas oppakken bij een concrete tweede klant/bedrijf: `companies`-tabel + tenant-scope op intakes en users.

### BL-013 — S3 als mediadisk

- **Status:** backlog · **Prioriteit:** low · **Ref:** `docs/uploads.md`
- **Doel:** `MEDIA_DISK=s3` + AWS-vars; bestaande rijen behouden `disk`+`path`. Pas nodig bij storagegroei of vertrek van cPanel.

## Afgerond / vervallen

Nog geen items. `done`- en `dropped`-items blijven hier staan als geheugen (met datum + PR).
