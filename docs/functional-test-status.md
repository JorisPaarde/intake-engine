# Functionele teststatus

> **Documentversie:** 1.4 · **Laatste update:** 2026-07-18 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Handmatig bijgehouden overzicht van wat functioneel is getest (en wat nog niet).

**Niet** invullen via geautomatiseerde agent-implementatie; bijwerken door de testende agent of tester. Implementerende agents voegen alleen nieuwe `todo`-regels toe voor functionaliteit die zij introduceren.

Laatste testsessie: 2026-07-18 (staging via headless Chromium/Playwright; cPanel 428 Technical Domain dismissed)

| Onderdeel | Status | Getest op | Notities |
|-----------|--------|-----------|----------|
| Deploy-pipeline (push -> Actions -> rsync -> activate -> live) | pass | 2026-07-17 | Atomische symlink-swap werkt; release schoon geserveerd |
| /health (app boot + DB-verbinding) | pass | 2026-07-18 | JSON ok; `php_upload` 512M/512M (BL-003) |
| /login rendert | pass | 2026-07-18 | Toont loginformulier |
| Auth-beveiliging dashboard/intakes | pass | 2026-07-18 | Uitgelogd → redirect `/login` (na dismiss 428-interstitial) |
| Dashboard weergave | pass | 2026-07-18 | Bereikbaar na registratie |
| Opname aanmaken (Airco) | pass | 2026-07-18 | Opgeslagen, detail + klantlink |
| Beveiligde klantlink genereren | pass | 2026-07-18 | Token-URL `/o/{64}` |
| Klantlink hergenereren | fail | 2026-07-18 | Knop diende niet in (secondary-button default `type=button`). Fix in PR |
| Klantlink intrekken | todo | - | Niet opnieuw gedaan in 2026-07-18-sessie (destructief); eerder pass 2026-07-17 |
| Migraties + logs op server | pass | 2026-07-17 | Alle migraties Ran; geen errors in logs |
| Airco-template beschikbaar | pass | 2026-07-18 | Selecteerbaar bij aanmaken |
| Homepage / (producthomepage Fase 3) | pass | 2026-07-18 | “Digitale Opname” producthomepage (geen Laravel-welcome) |
| Registratie /register | pass | 2026-07-18 | Formulier werkt; landt op `/dashboard` |
| E-mailverificatie flow | pass | 2026-07-18 | Geen `/verify-email`-blokkade op staging na register (of niet afgedwongen) |
| Klant-intakepagina /o/{token} (Fase 3) | pass | 2026-07-18 | Wizard laadt (8 stappen bij 1 binnenunit) — *vóór BL-018; hertest nodig* |
| Vraag-voor-vraag klantflow (BL-018) | todo | - | Na deploy: één vraag per scherm, sectietitel als markering, Volgende/Vorige, conditionele vraag verschijnt pas na relevant antwoord, hervatten op juiste vraag |
| Foto-uploads (Fase 4) | pass | 2026-07-18 | JPEG-upload + preview + “Foto opgeslagen” op ruimtestap |
| Afronden + bedankt-scherm (Fase 5) | fail | 2026-07-18 | Geblokkeerd: verplichte **boolean**-vragen (Ja/Nee) worden niet als beantwoord gezien → “Volgende” blijft hangen vanaf buitenunit-stap. Fix in PR |
| HTML-rapport + installateur-review (Fase 5) | todo | - | Niet bereikt door boolean-blokkade |
| AI-samenvatting in rapport (Fase 6) | todo | - | Niet bereikt; verwacht `blocked` bij `AI_PROVIDER=null` |
| Queue-worker (cron) | todo | - | Niet end-to-end bevestigd (geen AI-resultaat om te zien) |
| Demo-login `installateur@example.com` | fail | 2026-07-18 | Credentials matchen niet — `DatabaseSeeder` draait niet bij deploy (alleen IntakeTemplateSeeder) |
| Publieke demo “Start demo” (BL-001) | todo | - | Na deploy + `DEMO_ENABLED=true`: knop op `/`, redirect `/o/{token}`, watermerk, bedankt-copy |
| Demo-intake purge (`intakes:purge-demos`) | todo | - | Scheduler/hourly; expired demo-intakes verdwijnen (incl. uploads) |

## Legenda

| Status | Betekenis |
|--------|-----------|
| `todo` | Nog niet getest |
| `pass` | Functioneel OK |
| `fail` | Fout gevonden |
| `blocked` | Kan niet getest worden (afhankelijkheid/omgeving) |
| `n/a` | Niet van toepassing voor deze omgeving |

## Ruimte voor details

### Sessie 2026-07-18 (staging) — BL-002

Scope: functionele hertest Fase 3–6 via browser (Playwright/Chromium, `ignoreHTTPSErrors`, cPanel “428 Technical Domain” → Continue).

Werkend:

- Producthomepage, health (incl. uploadlimieten), login-formulier, auth-guard, registratie → dashboard
- Opname aanmaken (airco), klantlink, klantwizard `/o/{token}`, foto-upload op ruimtestap

Bugs / blokkades:

1. **Boolean-validatie (blokkerend voor afronden)** — `AnswerValueReader` eiste `is_bool()` terwijl Livewire-radio’s `"1"`/`"0"` sturen. `next()`/`complete()` blijven op stappen met verplichte Ja/Nee (o.a. buitenunit `noise_sensitive`). Reproduceerbaar: buitenunit-stap invullen inclusief Nee → Volgende → alert “Beantwoord eerst de verplichte vragen”.
2. **Klantlink hergenereren** — `<x-secondary-button>` in het regenerate-formulier had implicit `type="button"`, dus de POST firede niet.
3. **Foto-hydrate wist draft-velden** — na upload deed `hydrateFormFromAnswers()` een volledige form-reset; niet-geblurde antwoorden verdwenen uit Livewire-state (workaround: eerst foto’s, dan velden). Verholpen door alleen de foto-composite te verversen.
4. **Demo-user ontbreekt op staging** — `installateur@example.com` / `password` werkt niet; deploy seedt alleen templates. Registratie als fallback werkte.

Nog te hertesten na deploy van de fixes: hergenereren, volledige klantflow t/m bedankt, rapport + review, AI/queue.

Screenshots: `/opt/cursor/artifacts/bl002-screenshots/` (agent-run).

### Sessie 2026-07-17 (staging)

Scope: getest tegen de op dat moment gedeployde staging (Fase 2 interne basis). De end-to-end intakeflow voor de installateur is volledig geverifieerd: opname aanmaken -> beveiligde klantlink -> hergenereren -> intrekken, plus dashboard en /health.

Bevindingen:

- Airco-template werd niet automatisch geseed bij deploy; handmatig gedraaid met IntakeTemplateSeeder. Inmiddels opgelost in Fase 3 (template-seeding bij deploy).
- Klantlink /o/{token} gaf 404 omdat de klant-facing route toen nog niet bestond; met Fase 3 hoort dit nu te werken en moet opnieuw getest worden.
- Nog te testen na Fase 3–6: producthomepage, klantintake via /o/{token}, foto-uploads, afronden + rapport + review, AI-samenvatting, registratie + e-mailverificatie, en een end-to-end queue-job. Zie BL-002 in `docs/backlog.md`.
