# Changelog

Alle noemenswaardige wijzigingen aan dit project.

## [Unreleased]

### Added

- Fase 2 interne basis: intake-schema, airco-template v1, dashboard, opname aanmaken, klantlink kopiëren/intrekken/herniewen, seeddata, feature tests.

### Migrations

- `2026_07_17_120000_create_intake_engine_tables` — templates, versions, sections, questions, options, rules, intakes, answers, uploads, attention points, notes, reviews, reports, activity events.

### Config

- `config/intake.php` — `INTAKE_TOKEN_TTL_DAYS` (default 60)
- `config/filesystems.php` — `media` disk key
- `config/app.php` — `APP_TIMEZONE` wordt gelezen
- `.env.example` — `MEDIA_DISK=local`, `INTAKE_TOKEN_TTL_DAYS`

### Known limitations

- Klantintake-UI (Fase 3) nog niet gebouwd; link `/o/{token}` bestaat nog niet als route.
- Geen automatische e-mail (staging mail = log); alleen kopieerbare link.
- Uploads/rapport/review UI volgen in latere fasen.
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
