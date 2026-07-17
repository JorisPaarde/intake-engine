# Gefaseerd implementatieplan

Gebaseerd op repository-audit (2026-07-17). Infrastructuur (Laravel, CI, cPanel-deploy) blijft intact.

## Fase 1 — Audit & ontwerp ✅ (deze fase)

- Stack en infra vastgelegd
- Schema, versionering, tokens, uploads ontworpen
- Documentatie + ADRs

Geen functionele featurecode in deze fase.

## Fase 2 — Interne basis

**Doel:** installateur kan inloggen, opnames aanmaken/beheren, klantlink kopiëren.

1. Enums + migrations (schema uit `docs/database.md`)
2. Eloquent models in `app/Domains/Intake/Models`
3. Policies (`IntakePolicy`)
4. Actions: `CreateIntake`, `RevokeIntakeToken`, `RegenerateIntakeToken` (indien nodig)
5. Dashboard (Blade): lijst met status, voortgang, datums
6. Create-formulier (alleen template Airco)
7. Detailpagina: klantgegevens, kopieerbare link, status
8. Seeders: user + airco template v1 + voorbeeldintakes
9. Wire `MEDIA_DISK` in config (voorbereiding; uploads in Fase 4)
10. Tests: create, authorize, token uniqueness/revoke
11. Docs + CHANGELOG bijwerken

### Bestanden Fase 2 (voorgenomen toevoegen/wijzigen)

**Nieuw**

- `app/Enums/IntakeStatus.php`, `QuestionType.php`, `TemplateVersionStatus.php`, `ReviewDecision.php`, `RuleOperator.php`, `RuleEffect.php`, `AttentionPointSource.php`
- `database/migrations/*_create_intake_templates_table.php` (+ versions, sections, questions, options, rules, intakes, answers, uploads, attention_points, notes, reviews, generated_reports, activity_events)
- `app/Domains/Intake/Models/*` (IntakeTemplate, IntakeTemplateVersion, IntakeSection, IntakeQuestion, …, Intake, …)
- `app/Domains/Intake/Actions/CreateIntake.php`, `RevokeIntakeAccess.php`
- `app/Domains/Intake/Services/IntakeAccessTokenGenerator.php`
- `app/Policies/IntakePolicy.php` (+ registratie)
- `app/Http/Controllers/Installer/DashboardController.php`, `IntakeController.php`
- `app/Http/Requests/Installer/StoreIntakeRequest.php`
- `resources/views/installer/dashboard.blade.php`, `intakes/create.blade.php`, `intakes/show.blade.php`
- `database/data/templates/airco/v1.php` (of `.json`)
- `database/seeders/IntakeTemplateSeeder.php`, `DemoIntakeSeeder.php`
- `tests/Feature/Installer/CreateIntakeTest.php`, `DashboardTest.php`, `IntakeAccessTokenTest.php`
- `routes` uitbreiding in `routes/web.php` (installer group)

**Wijzigen**

- `routes/web.php` — dashboard → echte opnamelijst
- `resources/views/dashboard.blade.php` / layouts navigation
- `database/seeders/DatabaseSeeder.php`
- `config/filesystems.php` — `media` key (voorbereiding)
- `config/app.php` — timezone via `env('APP_TIMEZONE')`
- `README.md`, `CHANGELOG.md`, `docs/*` waar implementatie afwijkt van ontwerp
- Eventueel `bootstrap/app.php` voor policies/rate limiters

**Mail:** staging = `log` → geen auto-mail; alleen kopieerbare link. Documenteer latere mail in CHANGELOG.

## Fase 3 — Klantintake

**Doel:** mobiele stappenflow met autosave en hervatten.

1. Customer middleware (token lookup + expiry/revoke)
2. Livewire (of dunne controller + Livewire children) stappen-UI
3. `SaveIntakeAnswer`, visibility/completeness services (basis)
4. Conditionele regels
5. Voortgangsindicator + “opgeslagen”-feedback
6. Tests: access isolation, save, resume, conditional, validation

## Fase 4 — Foto’s

1. Upload Action + private disk
2. Preview/delete UI (mobiel)
3. Beveiligde serve-routes
4. Server validatie + documenteer cPanel PHP-limieten (meten op staging)
5. Corrigeer `.env*.example`: `MEDIA_DISK=local`
6. Tests: mime/size, unauthorized access denied

## Fase 5 — Compleetheid, rapport, beoordeling

1. Strikte `CompletenessChecker` + afronden
2. Snapshot + attention points
3. HTML-rapportweergave
4. Review UI + `ReviewDecision`
5. Tests: incomplete kan niet afronden; rapportinhoud; templatewijziging raakt oude intake niet
6. PDF alleen als cPanel-proof; anders documenteren als later

## Fase 6 — AI (optioneel)

Alleen na stabiele Fase 5. Zie `docs/ai.md`.

## Kwaliteitspoort per fase

Elke PR/fase: `composer check` (Pint + PHPStan + Pest), docs bijgewerkt, migrations reproduceerbaar, geen handmatige staging-DB-edits.
