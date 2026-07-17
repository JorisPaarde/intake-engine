# Intake Engine

Generieke, AI-gestuurde intake-engine. Eerste toepassing: intakes voor airco-installateurs. De architectuur is domeingericht zodat nieuwe intaketypes (warmtepompen, zonnepanelen, laadpalen) later zonder grote refactors toegevoegd kunnen worden.

**Stack:** Laravel 12 · PHP 8.3+ · MySQL · Blade · Livewire · Alpine.js · Tailwind CSS · Breeze (auth) · Pest · Pint · PHPStan/Larastan

## Installatie (macOS)

Vereisten: PHP 8.3+, Composer 2, Node 20+, MySQL 8 (bijv. via [Laravel Herd](https://herd.laravel.com) of Homebrew).

```bash
git clone git@github.com:<org>/intake-engine.git
cd intake-engine
./bin/setup.sh
```

Het script installeert het Laravel-skelet, Livewire, Breeze (Blade + Pest-variant), Pint, PHPStan en Pest, zet `.env` op en bouwt de frontend. Daarna:

```bash
# .env: DB_DATABASE / DB_USERNAME / DB_PASSWORD aanpassen
mysql -u root -e "CREATE DATABASE intake_engine"
php artisan migrate
```

## Development workflow

```bash
php artisan serve     # app op http://localhost:8000
npm run dev           # Vite met hot reload (tweede terminal)
php artisan queue:work  # alleen nodig als je queued jobs test
```

Kwaliteitschecks (dezelfde als CI):

```bash
composer lint      # Pint, alleen controleren
composer fix       # Pint, automatisch fixen
composer analyse   # PHPStan level 6
composer test      # Pest
composer check     # alles achter elkaar
```

**Branching:** `main` is altijd deploybaar. Werk in feature branches (`feature/...`), open een PR; CI (Pint + PHPStan + Pest) moet groen zijn vóór merge. Merge naar `main` deployt automatisch naar staging.

## Omgevingen & .env-strategie

| Omgeving   | Bestand op machine | Voorbeeld in repo         |
|------------|--------------------|---------------------------|
| local      | `.env`             | `.env.example`            |
| staging    | `shared/.env` op server | `.env.staging.example`  |
| production | `shared/.env` op server | `.env.production.example` |

Secrets staan **nooit** in git. Server-`.env`-bestanden worden éénmalig handmatig aangemaakt en overleven elke deploy (shared-map). CI-secrets staan in GitHub repository secrets.

## Storage

`FILESYSTEM_DISK`/`MEDIA_DISK` bepalen waar bestanden (o.a. foto's) landen. Lokaal en op cPanel is dat `public` (lokale disk); overstappen naar S3 is een kwestie van `MEDIA_DISK=s3` + AWS-credentials in `.env` — geen codewijzigingen, mits alle code via `Storage::disk(config('filesystems.media'))`-achtige abstractie werkt (afspraak: nooit hardcoded disknamen).

## Queues

`QUEUE_CONNECTION=database` vanaf dag één. AI-verwerking en PDF-generatie worden later als queued jobs gebouwd. Op cPanel draait de worker via cron (zie `docs/DEPLOYMENT.md`); elke deploy doet `queue:restart`.

## Logging

`daily`-kanaal met retentie; lokaal `debug`, staging `info`, productie `warning`. Logs op de server: `shared/storage/logs/laravel-*.log` (overleven deploys).

## Deployment

Push naar `main` → GitHub Actions bouwt (composer + assets) → rsync naar de cPanel-server → `deploy/activate.sh` migreert, cachet en wisselt de `current`-symlink atomisch. Server heeft alleen SSH, rsync en PHP-CLI 8.3 nodig. Volledige server-setup: **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)**.

## Projectstructuur

```
app/
  Domains/            # domeinlogica, per bounded context
    Intake/           #   het generieke intakeproces (flows, stappen, antwoorden)
    Chat/             #   conversatie-UI/logica
    Photos/           #   foto-upload en -verwerking
    Reports/          #   rapport/PDF-generatie
    AI/               #   AI-provider-abstractie
    Users/            #   gebruikers, rollen
      Actions/        #   één klasse = één use-case (bv. StartIntake)
      Services/       #   domeinservices, integraties
      Models/         #   Eloquent-modellen van dit domein
  Http/               # dunne controllers, requests, middleware (framework-laag)
  Support/            # gedeelde helpers/waardeobjecten zonder domein
bin/setup.sh          # eenmalige lokale setup
deploy/activate.sh    # server-side release-activatie
docs/                 # architectuur- en deploymentdocumentatie
.github/workflows/    # ci.yml + deploy-staging.yml
```

Architectuurkeuzes en conventies: **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)**.

## Eerste feature branch (voorstel)

`feature/intake-flow-domain-model` — het generieke datamodel van de engine: `IntakeFlow` (definitie van een intaketype), `IntakeStep`, `Intake` (een sessie/aanvraag) en `IntakeAnswer`, met migraties, factories en Pest-tests. Bewust nog géén UI en géén AI: dit model bepaalt of de engine echt generiek is, dus dat verdient de eerste, best doordachte PR. Airco wordt daarna slechts de eerste geconfigureerde `IntakeFlow`.
