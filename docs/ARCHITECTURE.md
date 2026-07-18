# Architectuurkeuzes

> **Documentversie:** 1.2 · **Laatste update:** 2026-07-18 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

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

Geplande domeinen: `Intake`, `Photos`, `Reports`, `Users`, `AI` (leeg tot Fase 6), `Chat` (alleen indien conversatie-UI nodig — MVP gebruikt stappenflow, geen chatbot).

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

Bestaande Breeze-componenten hergebruiken; geen nieuw designsysteem.

## Queues

`QUEUE_CONNECTION=database` blijft. Kernintake is **synchronisch** (ADR-0004). AI-samenvatting en PDF-export (BL-005, Dompdf) lopen as jobs. cPanel-cron worker: zie `docs/DEPLOYMENT.md`.

## Storage

Media via `config('filesystems.media')` → env `MEDIA_DISK`. Default **private `local`**, niet `public` (ADR-0003). S3 = env-wissel. Details: `docs/uploads.md`.

## Autorisatie

- Installateur: `auth` + policies
- Klant: token-middleware, scope = één intake
- Geen multi-tenancy in MVP (ADR-0006)

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
3. **Geen companies-tabel** — sneller MVP; later tenant-scope toevoegen (BL-012).
4. **HTML-rapport eerst** — PDF is een afgeleid async artefact (BL-005 done; Dompdf).

## Gerelateerde documentatie

- `docs/database.md` — schema + ER
- `docs/intake-engine.md` — templates/regels/compleetheid
- `docs/uploads.md` — media
- `docs/ai.md` — AI-roadmap
- `docs/implementation-plan.md` — fasering
- `docs/decisions/` — ADRs
