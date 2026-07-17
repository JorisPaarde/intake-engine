# Intake Engine (Digitale Opname)

Helpt installatiebedrijven om aanvragen op afstand te beoordelen via een begeleide digitale intake. Eerste template: **airco-opname**. De kern is een herbruikbare intake-engine, geen hardcoded airco-app.

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

**Branching:** `main` is deploybaar. Feature branches + PR; CI groen vóór merge. Merge naar `main` → staging deploy.

## Omgevingen & .env

| Omgeving   | Bestand              | Voorbeeld                 |
|------------|----------------------|---------------------------|
| local      | `.env`               | `.env.example`            |
| staging    | `shared/.env` server | `.env.staging.example`    |
| production | `shared/.env` server | `.env.production.example` |

Secrets nooit in git. Belangrijke vars: `APP_*`, `DB_*`, `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_*`, `FILESYSTEM_DISK`, `MEDIA_DISK` (private media: `local`), `MAIL_*`, `AI_*` (placeholders).

## Storage

Intake-foto’s via `MEDIA_DISK` (private `local`, later `s3`). Geen hardcoded disknamen. Zie `docs/uploads.md`.

## Queues

`QUEUE_CONNECTION=database`. Kernintake is sync; AI/PDF later als jobs. cPanel: cron worker — `docs/DEPLOYMENT.md`.

## Logging

Daily stack; lokaal `debug`, staging `info`, productie `warning`. Server: `shared/storage/logs/`.

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

| Document | Inhoud |
|----------|--------|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Architectuur & trade-offs |
| [docs/database.md](docs/database.md) | Schema + ER-diagram |
| [docs/intake-engine.md](docs/intake-engine.md) | Templates, regels, compleetheid |
| [docs/uploads.md](docs/uploads.md) | Media & limieten |
| [docs/ai.md](docs/ai.md) | AI-roadmap (nog niet gebouwd) |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | cPanel / CI deploy |
| [docs/implementation-plan.md](docs/implementation-plan.md) | Fasering |
| [docs/backlog.md](docs/backlog.md) | Backlog (o.a. demo-versie) |
| [docs/functional-test-status.md](docs/functional-test-status.md) | Functionele teststatus (handmatig) |
| [docs/decisions/](docs/decisions/) | ADRs |
| [CHANGELOG.md](CHANGELOG.md) | Wijzigingslog |

## Huidige status

**Fase 5 (compleetheid, rapport, beoordeling) afgerond.** Volgende: optioneel Fase 6 AI. Demo-versie staat op de backlog ([issue #5](https://github.com/JorisPaarde/intake-engine/issues/5)).
