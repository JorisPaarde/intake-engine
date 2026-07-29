# Intake Engine (Digitale Opname)

> **Documentversie:** 2.0 · **Laatste update:** 2026-07-30 · Onderhoud: zie [AGENTS.md](AGENTS.md)

**Werk je als agent aan dit project? Lees eerst [AGENTS.md](AGENTS.md)** — het projectgeheugen, de documentkaart en het onderhoudsprotocol.

Doel: na een bestaande aanvraag alle technische opname-informatie samenbrengen en zoveel mogelijk installateurstijd en onnodige locatiebezoeken besparen. In het besloten doelmodel kan de opname door de klant, volledig door de installateur of hybride worden gevuld. Eerste domein: **airco**.

De centrale opname is het productmodel; de bestaande data-gedreven intake-engine is één van de invoerkanalen. Airco krijgt daarnaast domeinobjecten voor ruimtes, plaatsings- en installatieopties en afzonderlijke koel-, condens- en stroomverbindingen. Het besloten doelmodel staat in [docs/product-model.md](docs/product-model.md). De huidige applicatie wordt daar stapsgewijs naartoe gemigreerd; zie [§ Huidige status](#huidige-status).

**Stack (feitelijk):** Laravel **13.20** · PHP **^8.3** (staging/CI **8.4**) · MySQL · Blade · Livewire **4.3** (package aanwezig) · Alpine.js · Tailwind CSS 3 · Breeze (auth) · Pest 4 · Pint · PHPStan/Larastan 6 · Vite 8

## Omgevingen (live)

| Omgeving | URL |
|----------|-----|
| Production | https://intake-engine.nl/ |
| Staging | https://staging.intake-engine.nl/ |

Inloggen op `/login`, dashboard op `/dashboard`, health-check op `/health`. Beide omgevingen gebruiken geldig HTTPS en hebben een eigen `.env`, app-key, sessiecookie, database, storage en releaseboom. `main` deployt naar staging; een `v*`-tag of bewuste handmatige production-dispatch deployt naar production.

## Installatie (macOS)

Vereisten: PHP 8.3+, Composer 2, Node 20+, MySQL 8 (bijv. [Laravel Herd](https://herd.laravel.com) of Homebrew).

```bash
git clone git@github.com:JorisPaarde/intake-engine.git
cd intake_engine
composer setup
# of: composer install && cp .env.example .env && php artisan key:generate && npm install && npm run build
```

```bash
# .env: DB_* aanpassen
mysql -u root -e "CREATE DATABASE intake_engine"
php artisan migrate --seed
```

Demo-login na seed: `installateur@example.com` / `password` (fictief).

**Uploadlimieten:** verhoog lokaal `upload_max_filesize` / `post_max_size` (zie `docs/uploads.md`). Standaard PHP CLI kan op 2M staan.

## Development

```bash
composer dev          # serve + queue + logs + vite
# of apart:
php artisan serve
npm run dev
php artisan queue:work
```

Kwaliteit (zelfde als CI):

```bash
composer lint      # Pint --test
composer fix       # Pint
composer analyse   # PHPStan level 6
composer test      # Pest
composer check     # lint + analyse + test
```

Branching, kwaliteitspoort en overige werkafspraken: [AGENTS.md § Werkafspraken](AGENTS.md#werkafspraken).

## Omgevingen & .env

| Omgeving   | Bestand              | Voorbeeld                 |
|------------|----------------------|---------------------------|
| local      | `.env`               | `.env.example`            |
| staging    | `shared/.env` server | `.env.staging.example`    |
| production | `shared/.env` server | `.env.production.example` |

Secrets nooit in git. Belangrijke vars: `APP_*`, `DB_*`, `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_*`, `FILESYSTEM_DISK`, `MEDIA_DISK` (private media: `local`), `MAIL_*`, `AI_*` (placeholders).

## Runtime: storage, queues & logging

- **Storage:** intakefoto's en aangeleverde documenten via `MEDIA_DISK` (privé, geen hardcoded disknamen) — [docs/uploads.md](docs/uploads.md)
- **Queues:** `QUEUE_CONNECTION=database`; cron-worker op cPanel — [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md); sync/async-keuzes: ADR-0004
- **Logging:** daily stack; lokaal `debug`, staging `info`, productie `warning`; server: `shared/storage/logs/`

## Deployment

Push `main` → staging; tag `v*` of handmatige production-dispatch → production. Beide gebruiken GitHub Actions → rsync → `deploy/activate.sh` (environmentguard, migrate, cache, atomische symlink). **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)**.

## Projectstructuur

```
app/Domains/     # Actieve domeinen, waaronder Intake en Branding; zie docs/ARCHITECTURE.md
app/Http/        # dunne framework-laag + Breeze auth
docs/            # architectuur, schema, engine, uploads, AI, ADRs
deploy/          # activate.sh
.github/workflows/
```

## Documentatie

De volledige documentkaart — welk document waarvoor de bron van waarheid is — staat in [AGENTS.md § Geheugenkaart](AGENTS.md#geheugenkaart-welk-document-is-waarvoor-de-bron-van-waarheid). Snelle ingangen:

- [docs/product-model.md](docs/product-model.md) — centrale opname, rollen, workflows, beslisgereedheid en airco-domeinmodel
- [docs/backlog.md](docs/backlog.md) — al het open werk en de uitvoeringsvolgorde
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — gescheiden staging- en productiondeploy op cPanel
- [docs/metrics.md](docs/metrics.md) — definities van productmetrics en staging-smoke
- [CHANGELOG.md](CHANGELOG.md) — wijzigingslog

## Huidige status

**Huidige implementatie:** MVP-fasen 1–6 zijn afgerond. De app heeft een beveiligde klantwizard, private foto-/documentuploads, rapport/PDF, gerichte vervolgrondes, installer-review, multi-accounttenancy en white-label. BAG/PDOK, PDOK-luchtfoto, EP-Online en 3DBAG vullen het dossier al automatisch. AI-samenvatting, aandachtspunten, foto-afleiding en de backend van de begeleide leidingroute zijn gebouwd achter privacy- en providerflags. Production en staging zijn gescheiden.

**Besloten doelmodel, nog te bouwen:** de opname wordt het centrale dossier van een bestaande aanvraag; de klantlink wordt optioneel; volledig installateur-uitgevoerde en hybride opname worden kernflows; sterke bron-/AI-afleidingen worden zonder losse bevestigingsadministratie gebruikt; beslisgereedheid vervangt één globale compleetheidsstatus; airco krijgt kandidaatopstellingen met afzonderlijke koel-, condens- en stroomroutes. Zie [docs/product-model.md](docs/product-model.md), ADR-0011/0012 en de nieuwe uitvoeringsitems in [docs/backlog.md](docs/backlog.md).

**Open operationeel werk:** BL-001 publieke demo staat standaard aan; externe AI-activering wacht op DPIA/key; SMTP en overige host/env-acties staan in [docs/DEPLOYMENT.md § Handmatige acties](docs/DEPLOYMENT.md#handmatige-acties-producteigenaar). Handmatige teststatus: [docs/functional-test-status.md](docs/functional-test-status.md).
