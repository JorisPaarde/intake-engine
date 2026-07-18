# Gefaseerd implementatieplan

> **Documentversie:** 1.1 · **Laatste update:** 2026-07-17 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Gebaseerd op repository-audit (2026-07-17). Infrastructuur (Laravel, CI, cPanel-deploy) blijft intact.

**Status: alle fasen (1–6) zijn afgerond en gemerged naar `main`.** Dit document is historie/geheugen van het MVP-traject; voeg hier geen nieuw werk toe. Nieuw en uitgesteld werk staat in [docs/backlog.md](backlog.md).

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

## Fase 6 — AI ✅

**Doel:** optionele AI-samenvatting na afronding, soft-fail t.o.v. kernintake.

Geïmplementeerd: `ai_runs`, `AiClientInterface` (null/fake/heuristic), `SummarizeIntake` + job na `CompleteIntake`, AI-voorstelblok in HTML-rapport, feature tests. Externe LLM-provider later.

## Kwaliteitspoort per fase

Elke PR/fase: `composer check` (Pint + PHPStan + Pest), docs bijgewerkt, migrations reproduceerbaar, geen handmatige staging-DB-edits.
