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
