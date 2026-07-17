# Gefaseerd implementatieplan

Gebaseerd op repository-audit (2026-07-17). Infrastructuur (Laravel, CI, cPanel-deploy) blijft intact.

## Fase 1 — Audit & ontwerp ✅

## Fase 2 — Interne basis ✅

**Doel:** installateur kan inloggen, opnames aanmaken/beheren, klantlink kopiëren.

Geïmplementeerd: enums, migrations, models, policies, Create/Revoke/Regenerate actions, dashboard, create/show UI, airco template v1 + seeders, `MEDIA_DISK` config, timezone fix, feature tests.

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
