# Changelog

Alle noemenswaardige wijzigingen aan dit project.

## [Unreleased]

### Added

- Deploy activeert na migraties ook `IntakeTemplateSeeder` (idempotente template-reference-data).
- Fase 3 klantintake: beveiligde link `/o/{token}`, Livewire-stappenflow, autosave, hervatten, conditionele vragen, voortgang (foto-upload UI volgt in Fase 4).
- Producthomepage op `/` met korte uitleg, navigatie naar login/register en dashboard voor ingelogde gebruikers.
- Fase 2 interne basis: intake-schema, airco-template v1, dashboard, opname aanmaken, klantlink kopiëren/intrekken/herniewen, seeddata, feature tests.

### Migrations

- `2026_07_17_120000_create_intake_engine_tables` — templates, versions, sections, questions, options, rules, intakes, answers, uploads, attention points, notes, reviews, reports, activity events.

### Config

- `config/intake.php` — `INTAKE_TOKEN_TTL_DAYS` (default 60)
- `config/filesystems.php` — `media` disk key
- `config/app.php` — `APP_TIMEZONE` wordt gelezen
- `.env.example` — `MEDIA_DISK=local`, `INTAKE_TOKEN_TTL_DAYS`

### Known limitations

- Foto-upload UI is placeholder; echte uploads komen in Fase 4.
- Afronden / strikte compleetheidsblokkade / rapport / review volgen in Fase 5.
- Geen automatische e-mail (staging mail = log); alleen kopieerbare link.
- Multi-tenancy bewust afwezig.

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
