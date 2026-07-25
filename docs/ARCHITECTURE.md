# Architectuurkeuzes

> **Documentversie:** 1.3 · **Laatste update:** 2026-07-25 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

## Uitgangspunt: engine, geen airco-app

Airco is een *configuratie* van de engine, geen aparte codebase. Intaketype-specifieke vragen, validaties en rapportindeling leven in templateversies (data), niet in “airco”-controllers. Nieuwe intaketypes = nieuwe template + versie.

Zie `docs/intake-engine.md` en ADR-0001.

## Huidige runtime-stack (feitelijk)

| Laag | Versie / keuze |
|------|----------------|
| PHP | `^8.3` (composer); staging CI/server **8.4**; lokaal gemeten 8.5.7 |
| Laravel | **13.20.0** (`^13.8`) |
| Auth | Laravel Breeze (Blade), session guard |
| UI | Blade + Alpine.js; **Livewire 4.3** (klantwizard) |
| CSS/JS | Tailwind 3.4 + Vite 8 |
| DB | MySQL (env); sqlite in-memory in tests |
| Queue/cache/session | `database` |
| Tests | Pest 4 |
| Kwaliteit | Pint + PHPStan/Larastan level 6 |

## Domeinstructuur: `app/Domains/*`

Namespaces binnen één Laravel-app (`App\Domains\Intake\...`), geen aparte packages.

Per domein:

- **Actions** — één use-case (`CreateIntake`, `SaveIntakeAnswer`, `CompleteIntake`, …)
- **Services** — herbruikbare domeinlogica (`CompletenessChecker`, `VisibilityResolver`, …)
- **Models** — Eloquent binnen het domein

`app/Http` blijft dun (controllers, form requests, middleware, Livewire als UI-adapter). `app/Support` voor domeinloze helpers.

Actieve domeinen: `Intake`, `AI` en `Branding`. Voeg pas een nieuw domein toe wanneer meerdere use-cases een eigen begrippen- en servicelaag nodig hebben.

## Request- en datastromen

```text
Installateur (session auth)
  → Dashboard / CreateIntake / Review
  → leest intakes + generated_reports + private uploads (policy)

Klant (access_token middleware)
  → stappen-UI → SaveIntakeAnswer / StoreIntakeUpload
  → CompletenessChecker bij navigatie/afronden
  → CompleteIntake → snapshot + HTML-rapport

Templatebeheer (seed/artisan)
  → published intake_template_versions (immutabel)
```

## Frontend

Server-rendered Blade. Livewire voor interactieve klantstappen en uploads (Fase 3–4). Alpine voor kleine client-gedragingen. Geen Inertia/SPA.

Bestaande Breeze-componenten vormen één solide, tenantgestuurd designsysteem: systeemtypografie, neutrale oppervlakken en CSS-variabelen uit `Company::themeTokens()`. Geen Liquid Glass, blur of translucency (ADR-0010).

## Queues

`QUEUE_CONNECTION=database` blijft. Kernintake is **synchronisch** (ADR-0004). AI-samenvatting en PDF-export (BL-005, Dompdf) lopen as jobs. cPanel-cron worker: zie `docs/DEPLOYMENT.md`.

## Storage

Media via `config('filesystems.media')` → env `MEDIA_DISK`. Default **private `local`**, niet `public` (ADR-0003). S3 = env-wissel. Details: `docs/uploads.md`.

## Autorisatie

- Installateur: `auth` + policies; iedere query en mutatie is begrensd door `company_id`.
- Klant: token-middleware, scope = één intake; bedrijf en branding worden uitsluitend via die intake geladen.
- `companies` is de tenantbron (ADR-0010, vervangt ADR-0006).

### Tenantinvarianten voor implementerende agents

1. Een installateursservice die tenantdata leest, ontvangt een verplichte `Company`; `null` mag nooit “alle bedrijven” betekenen.
2. Route-modelbinding is geen autorisatie. Gebruik een policy of een autoriserende FormRequest vóór iedere mutatie of private download.
3. Customer-routes gebruiken alleen het door `EnsureCustomerIntakeAccess` geplaatste `customer_intake`; zoek geen bedrijf op uit een los requestveld.
4. Private mediapaden worden nooit direct gepubliceerd. Logo's en intakebestanden lopen via geautoriseerde/tokengebonden controllers.
5. Nieuwe tenantgebonden paden krijgen minimaal een positieve test voor een collega binnen hetzelfde bedrijf en een negatieve cross-company test.

## AI

Minimale Fase 6-slice: samenvatting na afronding (ADR-0005 / `docs/ai.md`). Externe LLM later.

## Testen

Pest. CI: Pint + PHPStan + Pest. Domeinregels krijgen feature tests per fase.

## Deployment

Build in GitHub Actions, rsync release, `deploy/activate.sh` (migrate, cache, atomic symlink). Details: `docs/DEPLOYMENT.md`.

## Codekwaliteit

- Pint (laravel + `declare_strict_types`)
- PHPStan level 6 (Larastan)
- CI blokkeert merges op PR

## Trade-offs

1. **cPanel** — cron-queue i.p.v. Supervisor; gedeelde PHP-limieten (uploads! zie BL-003 in `docs/backlog.md`).
2. **Token plaintext in DB** — hertoonbare link vs. hash-only (ADR-0002).
3. **Expliciete companies-tabel** — iets meer query- en testdiscipline, maar een harde grens voor data, media en branding (ADR-0010).
4. **HTML-rapport eerst** — PDF is een afgeleid async artefact (BL-005 done; Dompdf).

## Gerelateerde documentatie

- `docs/database.md` — schema + ER
- `docs/intake-engine.md` — templates/regels/compleetheid
- `docs/uploads.md` — media
- `docs/ai.md` — AI-roadmap
- `docs/implementation-plan.md` — fasering
- `docs/decisions/` — ADRs
