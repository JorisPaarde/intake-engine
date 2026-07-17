# Gefaseerd implementatieplan

Gebaseerd op repository-audit (2026-07-17). Infrastructuur (Laravel, CI, cPanel-deploy) blijft intact.

## Fase 1 — Audit & ontwerp ✅

## Fase 2 — Interne basis ✅

**Doel:** installateur kan inloggen, opnames aanmaken/beheren, klantlink kopiëren.

Geïmplementeerd: enums, migrations, models, policies, Create/Revoke/Regenerate actions, dashboard, create/show UI, airco template v1 + seeders, `MEDIA_DISK` config, timezone fix, feature tests.

## Fase 3 — Klantintake ✅

**Doel:** mobiele stappenflow met autosave en hervatten.

Geïmplementeerd: customer middleware + `/o/{token}`, Livewire wizard, SaveIntakeAnswer, VisibilityResolver, ProgressCalculator, conditionele regels, voortgang/autosave, feature tests.

## Fase 4 — Foto’s ✅

Geïmplementeerd: `StoreIntakeUpload` / `DeleteIntakeUpload`, private media disk, Livewire camera/galerij UI, beveiligde serve-routes (klant + installateur), validatie, voortgang telt foto’s mee, tests.

## Fase 5 — Compleetheid, rapport, beoordeling ✅

**Doel:** strikte afronding, momentopname, HTML-rapport, installateur-beoordeling.

Geïmplementeerd: `CompletenessChecker`, `CompleteIntake`, system attention points, `generated_reports` HTML, klant-afronden + bedankt, installer rapport + `SubmitIntakeReview` / `ReviewDecision`, feature tests. PDF bewust later (cPanel shared hosting).

## Fase 6 — AI (optioneel)

Alleen na stabiele Fase 5. Zie `docs/ai.md`.

## Kwaliteitspoort per fase

Elke PR/fase: `composer check` (Pint + PHPStan + Pest), docs bijgewerkt, migrations reproduceerbaar, geen handmatige staging-DB-edits.
