# Intake Engine (Digitale Opname)

> **Documentversie:** 1.27 · **Laatste update:** 2026-07-20 · Onderhoud: zie [AGENTS.md](AGENTS.md)

**Werk je als agent aan dit project? Lees eerst [AGENTS.md](AGENTS.md)** — het projectgeheugen, de documentkaart en het onderhoudsprotocol.

Helpt installatiebedrijven om aanvragen op afstand te beoordelen via een begeleide digitale intake. Eerste template: **airco-opname**. De kern is een herbruikbare intake-engine, geen hardcoded airco-app. Het vaste [hoofddoel](AGENTS.md#hoofddoel-vast--niet-door-agents-aan-te-passen) en [ontwerpprincipe](AGENTS.md#ontwerpprincipe-vast--niet-door-agents-aan-te-passen) van het product staan in AGENTS.md.

**Stack (feitelijk):** Laravel **13.20** · PHP **^8.3** (staging/CI **8.4**) · MySQL · Blade · Livewire **4.3** (package aanwezig) · Alpine.js · Tailwind CSS 3 · Breeze (auth) · Pest 4 · Pint · PHPStan/Larastan 6 · Vite 8

## Omgevingen (live)

| Omgeving | URL |
|----------|-----|
| Staging  | https://sociable-navy-raccoon.45-152-250-86.cpanel.site |

Inloggen op `/login`, dashboard op `/dashboard`, health-check op `/health`. Dit is een tijdelijk `.cpanel.site`-testdomein met self-signed SSL: de browser toont een waarschuwing en een "Technical Domain"-tussenscherm (klik *Continue*). Vervangen zodra er een eigen domein aan het account hangt.

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

Push `main` → GitHub Actions → rsync → `deploy/activate.sh` (migrate, cache, atomic symlink). **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)**.

## Projectstructuur

```
app/Domains/     # Intake, Photos, Reports, AI, Users, Chat (scaffolds)
app/Http/        # dunne framework-laag + Breeze auth
docs/            # architectuur, schema, engine, uploads, AI, ADRs
deploy/          # activate.sh
.github/workflows/
```

## Documentatie

De volledige documentkaart — welk document waarvoor de bron van waarheid is — staat in [AGENTS.md § Geheugenkaart](AGENTS.md#geheugenkaart-welk-document-is-waarvoor-de-bron-van-waarheid). Snelle ingangen:

- [docs/backlog.md](docs/backlog.md) — al het open werk, geordend in 5 epics
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — deploy naar staging/cPanel
- [docs/metrics.md](docs/metrics.md) — definities van productmetrics en staging-smoke
- [CHANGELOG.md](CHANGELOG.md) — wijzigingslog

## Huidige status

**MVP-fasen 1–6 afgerond en gemerged naar `main`** (t/m AI-samenvatting). Open werk: [docs/backlog.md](docs/backlog.md). BL-019 is code-compleet: adres-autocomplete en BAG-verrijking leveren bouwjaar, gebruiksdoel, oppervlakte, locatie en perceel met bron/zekerheid; een eenduidig BAG-bouwjaar vervangt de klantvraag in airco v4. Een actuele PDOK-luchtfoto rond de BAG-locatie staat als private, gemarkeerde context in dossier en PDF. BL-020 is code-compleet achter een privacyflag: airco v5 laat meterkastfoto's multimodaal beoordelen, vult alleen bij hoge zekerheid de vrije-groepkeuze als bevestigbare voorzet in en bewaart fase, bron en onzekerheid in het dossier; externe activering wacht op DPIA/key. BL-027 biedt gerichte tekst-, foto- en beveiligde PDF-documentopdrachten na beoordeling. BL-026 toont op `/metrics` privacyveilige funnel-, frictie- en dossiermetrics. BL-001 demo-code is klaar, maar staging-flag/smoke en overige **host/env-acties** (SMTP, domein/SSL, PDOK-grondslag, externe AI) staan in [docs/DEPLOYMENT.md § Handmatige acties](docs/DEPLOYMENT.md#handmatige-acties-producteigenaar). Handmatige teststatus: [docs/functional-test-status.md](docs/functional-test-status.md).
