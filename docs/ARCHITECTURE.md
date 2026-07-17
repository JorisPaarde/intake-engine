# Architectuurkeuzes

## Uitgangspunt: engine, geen airco-app

Airco is een *configuratie* van de engine, geen aparte codebase. Alles wat intaketype-specifiek is (vragen, validaties, rapportsjablonen) hoort in data/configuratie van het `Intake`-domein te leven, niet in code die "airco" heet. Nieuwe intaketypes = nieuwe flow-definitie, geen refactor.

## Domeinstructuur: `app/Domains/*`

Gekozen: domeinen als namespaces binnen één Laravel-app (`App\Domains\Intake\...`), in plaats van aparte composer-packages/modules.

Waarom: PSR-4 werkt automatisch (alles onder `app/` is `App\`), geen extra tooling of package-overhead, refactoren tussen domeinen blijft goedkoop terwijl de grenzen wél expliciet zijn. Als een domein later echt zelfstandig moet worden, is extractie naar een package een mechanische stap.

Per domein:

- **Actions** — één klasse per use-case met een `handle()`/`__invoke()` (bv. `StartIntake`, `AttachPhoto`). Dit is waar businesslogica leeft; controllers en Livewire-components roepen alleen Actions aan. Voorkomt fat controllers én fat models.
- **Services** — domeinservices en integraties die door meerdere Actions gebruikt worden (bv. `Reports\PdfRenderer`, `AI\CompletionClient`).
- **Models** — Eloquent-modellen van dat domein. Cross-domein relaties mogen, maar schrijf-operaties op andermans modellen lopen via Actions van dát domein.

`app/Http` blijft de dunne framework-laag (controllers, form requests, middleware). `app/Support` is voor gedeelde, domeinloze helpers.

## AI als abstractie

Het `AI`-domein krijgt een provider-interface (bv. `CompletionClient`) zodat de rest van de app nooit direct aan een specifieke vendor-SDK hangt. Providerkeuze en API-key via `.env` (`AI_PROVIDER`, `AI_API_KEY`).

## Frontend: Blade + Livewire + Alpine

Server-side rendering met Livewire voor interactiviteit (chat-achtige intake-UI past hier goed), Alpine voor kleine client-side gedragingen. Geen SPA-framework: minder buildcomplexiteit, minder API-oppervlak, en het team/de agent werkt in één taal-ecosysteem.

## Queues vanaf dag één

`QUEUE_CONNECTION=database`: geen extra infrastructuur nodig op cPanel, wél vanaf het begin het juiste mentale model — AI-calls en PDF-generatie worden jobs, nooit synchrone requests. Database-driver is prima tot serieuze volumes; migreren naar Redis is een `.env`-wijziging plus een Redis-host.

## Storage-abstractie

Alle media-code gebruikt de disk uit configuratie (`MEDIA_DISK`), nooit een hardcoded disknaam. Daardoor is local → S3 een configuratiewijziging. cPanel-let-op: `storage/` is shared over releases, dus uploads overleven deploys.

## Testen: Pest

Pest boven kale PHPUnit: expressiever, standaard in nieuwe Laravel-projecten, en de compacte syntax is prettig voor zowel mensen als AI-agents. Tests draaien op sqlite in-memory in CI.

## Deployment: build in CI, atomische releases

De server draait alleen PHP; composer/node draaien in GitHub Actions. Releases landen in `releases/<sha>` en worden via een `current`-symlink geactiveerd — rollback = symlink terugzetten. Zie `docs/DEPLOYMENT.md`.

## Codekwaliteit

- **Pint** (laravel-preset + `declare_strict_types`) — stijl is geen discussie.
- **PHPStan level 6 via Larastan** — hoog genoeg om echte bugs te vangen, laag genoeg om geen baseline-moeras te creëren. Ambitie: later naar 7/8.
- **CI blokkeert merges** bij falende checks.

## Risico's en verbeterpunten

1. **cPanel is de zwakste schakel.** Geen supervisor (queue-worker via cron is een pragmatische maar minder robuuste oplossing), gedeelde resources, beperkte PHP-configuratie. Bij groei: migreren naar een VPS (Forge/Ploi) — de deploy-strategie (rsync + symlink) verhuist dan mee.
2. **Database-queue onder load.** Bij veel AI-jobs wordt de DB-queue een bottleneck; Redis is dan de stap.
3. **Geen zero-downtime-garantie voor migraties.** `migrate --force` tijdens activatie kan bij destructieve migraties kort breken; afspraak: alleen additieve migraties, destructief in twee stappen.
4. **PHPStan level 6 met lege codebase is makkelijk;** discipline nodig om het niveau vast te houden zodra domeinen volstromen.
5. **Secrets-hygiëne op cPanel:** `.env` in `shared/` moet buiten de webroot staan en `600`-permissies hebben — gedocumenteerd in DEPLOYMENT.md, maar het blijft handwerk.
6. **Nog geen error-monitoring.** Aanrader zodra staging live is: Sentry of Flare (gratis tier volstaat), plus uptime-monitoring.
