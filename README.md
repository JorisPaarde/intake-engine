# Intake Engine (Digitale Opname)

> **Documentversie:** 2.14 · **Laatste update:** 2026-08-11 · Onderhoud: zie [AGENTS.md](AGENTS.md)

**Werk je als agent aan dit project? Lees eerst [AGENTS.md](AGENTS.md)** — het projectgeheugen, de documentkaart en het onderhoudsprotocol.

Doel: na een bestaande aanvraag alle technische opname-informatie samenbrengen en zoveel mogelijk installateurstijd en onnodige locatiebezoeken besparen. De opname kan door de klant, volledig door de installateur of hybride worden gevuld. Eerste domein: **airco**.

De centrale opname is het productmodel; de data-gedreven intake-engine is één van de invoerkanalen. Airco heeft daarnaast domeinobjecten voor gewenste ruimtes, plaatsings- en installatieopties en afzonderlijke koel-, condens- en stroomverbindingen. Het geïmplementeerde model staat in [docs/product-model.md](docs/product-model.md); zie [§ Huidige status](#huidige-status).

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

## Publieke interactieve demo

De publieke homepage is een productfunnel voor airco-installateurs: probleem en werkwijze, afzonderlijke voordelen voor installateur en klant, productweergaven met fictieve demo-inhoud, FAQ, interactieve demo en een interesseformulier voor een pilot.

Een gast kan vanaf `/` zonder account **Probeer de demo** kiezen (header: **Inloggen** + pilot). De app maakt per start een eigen tijdelijk installatiebedrijf en gebruiker, logt de bezoeker in als installateur en begeleidt via pop-ups: opnamelijst → *Nieuwe opname* (zelf postcode/huisnummer invullen) → rolkeuze i.p.v. mail (*klant* of *zelf*). Tijdens de demosessie staat overal **Demo beëindigen** (ook in de app-nav bij terugnavigeren); op `/` daarnaast **Verder in demo**. Een echt account ziet **Mijn opnames** (zelfde `/dashboard` als nav “Opnames”), geen demostart.

- dezelfde create-, dossier-, tenant-, upload- en klanttaaklogica als productie;
- adresverrijking na postcode/huisnummer en AI-foto-/tekstinterpretatie zoals in productie (wanneer die integraties aan staan);
- verkorte klantwizard of installateurswerkplek met optioneel voorbeelddossier;
- vooraf berekende BAG-/luchtfoto-/EP-Online-/3DBAG- en AI-voorbeelddata bij sample-load als snelle boost;
- synthetische foto’s via de normale dossier- en analysebeeldpipeline;
- geen klantmail of automatische PDF; wel optioneel demorapport-PDF op e-mailaanvraag (tegelijk productlead);
- automatische hard purge van dossier, media, gebruiker en bedrijf na standaard twee uur.

Een interesse-inzending wordt los van technische opnames in `product_interests` bewaard, zonder IP-adres. De dagelijkse purge verwijdert haar standaard na 365 dagen. Met `PRODUCT_INTEREST_MAIL_TO` en werkende SMTP wordt daarnaast een interne melding in de queue gezet; zonder mailconfig blijft de inzending gewoon bewaard.

Technisch plan en acceptatie: [docs/plans/bl-001-interactive-installer-demo.md](docs/plans/bl-001-interactive-installer-demo.md).

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

**Huidige implementatie:** MVP-fasen 1–6 én de dossiermigratie BL-030/035–042 zijn gebouwd. De app heeft één tenantgebonden opnamedossier met bron, zekerheid, status en bewijs; een beveiligde klantwizard; een vrije mobiele installateurswerkplek (acties eerst, sticky CTA als echte handeling, open punten met deep links, bewerkbare ruimtes/plaatsingen, 1-klik klanttaak vanuit uitzondering/foto, bewijs bij het object); gerichte hybride klanttaken; en beslisstatus per technisch gebied. Airco modelleert gewenste ruimtes, binnen-/buiten-/voedings-/afvoerposities, single-/multi-splitopties en afzonderlijke koel-, condens- en stroomverbindingen. De bestaande routebackend is per verbinding hergebruikt.

BAG/PDOK, PDOK-luchtfoto, EP-Online en 3DBAG vullen hetzelfde dossier automatisch. AI kan bron- en beeldbewijs synthetiseren tot plaatsingen, installatieopties, verbindingen, uitzonderingen en één gerichte vervolgtaak; voorstellen blijven controleerbaar en de installateur keurt het geheel goed. Iedere opgeslagen foto heeft een metadata-vrije dossierkopie en een kleinere analysekopie. `/metrics` meet onder meer offerte op afstand, prijsindicatie, actieve tijd, locatiebezoekredenen, voorstelafwijkingen en montageverrassingen.

De publieke homepage is een installateursfunnel met fictieve productweergaven, interactieve demo en een zelfstandige, privacybegrensde interesse-CTA, visueel afgestemd op de JPWebcreation-huisstijl (aparte marketingtokens; de ingelogde app behoudt tenant-/Apple-styling). Contactinzendingen starten nooit een technische opname. Gebruikersgerichte app-teksten volgen gecontroleerd eenvoudig Nederlands ([docs/language.md](docs/language.md)); nieuwe airco-opnames gebruiken template v12.

**Open operationeel werk:** de begeleidde BL-001-demo (installateursstart + rolkeuze + coachmarks) is codegereed maar blijft `in_progress` tot de staging-/mobiele smoke is uitgevoerd. Externe AI-activering wacht op DPIA/key; SMTP en overige host/env-acties staan in [docs/DEPLOYMENT.md § Handmatige acties](docs/DEPLOYMENT.md#handmatige-acties-producteigenaar). Handmatige teststatus: [docs/functional-test-status.md](docs/functional-test-status.md).
