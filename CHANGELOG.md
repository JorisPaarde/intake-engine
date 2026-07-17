# Changelog

Alle noemenswaardige wijzigingen aan dit project.

## [Unreleased]

### Added

- `docs/backlog.md` + GitHub issue #5: demo-versie van de app (backlog).
- Fase 4 foto-uploads: private storage, Livewire upload/preview/verwijderen, beveiligde serve-routes, validatie, installer-galerij.
- Deploy activeert na migraties ook `IntakeTemplateSeeder` (idempotente template-reference-data).
- Fase 3 klantintake: beveiligde link `/o/{token}`, Livewire-stappenflow, autosave, hervatten, conditionele vragen, voortgang.
- Producthomepage op `/` met korte uitleg, navigatie naar login/register en dashboard voor ingelogde gebruikers.
- Fase 2 interne basis: intake-schema, airco-template v1, dashboard, opname aanmaken, klantlink kopiëren/intrekken/herniewen, seeddata, feature tests.

### Config

- `INTAKE_UPLOAD_MAX_KB`, `INTAKE_UPLOAD_MAX_FILES`
- `config/intake.php` uploads-sectie

### Known limitations

- Afronden / strikte compleetheidsblokkade / rapport / review volgen in Fase 5.
- HEIC niet in allowlist (alleen jpeg/png/webp).
- Staging PHP upload-limieten nog te verifiëren op cPanel.
- Geen automatische e-mail (staging mail = log); alleen kopieerbare link.
- Multi-tenancy bewust afwezig.
- Demo-versie: backlog (issue #5), nog niet gebouwd.

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
