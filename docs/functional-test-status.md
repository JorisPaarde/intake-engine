# Functionele teststatus

> **Documentversie:** 1.9 · **Laatste update:** 2026-07-18 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Handmatig bijgehouden overzicht van wat functioneel is getest (en wat nog niet).

Bijwerken door wie de test daadwerkelijk heeft uitgevoerd: een menselijke tester **of** een testende agent (bijv. een agent die de app via een browser bedient). Niet invullen op basis van alleen implementatie — er moet echt functioneel getest zijn. Implementerende agents voegen alleen nieuwe `todo`-regels toe voor functionaliteit die zij introduceren.

Laatste testsessie: 2026-07-18 (staging via headless Chromium/Playwright; BL-002 hertest na deploy PR #14)

| Onderdeel | Status | Getest op | Notities |
|-----------|--------|-----------|----------|
| Deploy-pipeline (push -> Actions -> rsync -> activate -> live) | pass | 2026-07-18 | Atomische symlink-swap werkt; PR #14 deploy success |
| /health (app boot + DB-verbinding) | pass | 2026-07-18 | JSON ok; `php_upload` 512M/512M (BL-003) |
| /login rendert | pass | 2026-07-18 | Toont loginformulier |
| Auth-beveiliging dashboard/intakes | pass | 2026-07-18 | Uitgelogd → redirect `/login` (na dismiss 428-interstitial) |
| Dashboard weergave | pass | 2026-07-18 | Bereikbaar na registratie |
| Opname aanmaken (Airco) | pass | 2026-07-18 | Opgeslagen, detail + klantlink |
| Beveiligde klantlink genereren | pass | 2026-07-18 | Token-URL `/o/{64}` |
| Klantlink hergenereren | pass | 2026-07-18 | Na fix #14 (`type=submit`) — nieuw token gegenereerd |
| Klantlink intrekken | pass | 2026-07-18 | Status Geannuleerd + flash “Klantlink ingetrokken…” |
| Automatische klantlink-mail (BL-004) | todo | - | Na SMTP in staging `shared/.env`: opname aanmaken → mail bij klant; “Opnieuw mailen”; hergenereren mailt nieuwe link; bij `MAIL_MAILER=log` flash over config + geen mail |
| Migraties + logs op server | pass | 2026-07-17 | Alle migraties Ran; geen errors in logs |
| Airco-template beschikbaar | pass | 2026-07-18 | Selecteerbaar bij aanmaken |
| Airco-template v2 (BL-017) | todo | - | Na deploy: nieuwe opname pin’t v2; geen kamermaten-vragen; keuzelijsten i.p.v. vrije tekst buiten/route/condens; `free_group_known` / gevel optioneel; oude intakes blijven op v1 |
| Homepage / (producthomepage Fase 3) | pass | 2026-07-18 | “Digitale Opname” producthomepage (geen Laravel-welcome) |
| Registratie /register | pass | 2026-07-18 | Formulier werkt; landt op `/dashboard` |
| E-mailverificatie flow | pass | 2026-07-18 | Geen `/verify-email`-blokkade op staging na register (of niet afgedwongen) |
| Klant-intakepagina /o/{token} (Fase 3) | pass | 2026-07-18 | Wizard end-to-end (8 stappen, 1 binnenunit) — *retest was vóór deploy BL-018; hertest nodig* |
| Vraag-voor-vraag klantflow (BL-018) | todo | - | Na deploy: één vraag per scherm, sectietitel als markering, Volgende/Vorige, conditionele vraag verschijnt pas na relevant antwoord, hervatten op juiste vraag |
| Installateur-prefill bij aanmaken (BL-016) | todo | - | Na deploy: "alvast invullen" op opname-aanmaken (airco v3 request-vragen); klant ziet ze met "alvast ingevuld — controleer"; intake blijft `sent` tot klant start |
| Repeatable-prefill ruimtes (BL-016) | todo | - | Na deploy: bij ≥2 binnenunits neemt ruimte 2 `floor_level` over van ruimte 1 als bewerkbare voorzet ("Overgenomen van Ruimtes 1"); pas bij Volgende opgeslagen; ruimte 1 nooit voorgevuld |
| Foto-uploads (Fase 4) | pass | 2026-07-18 | JPEG-upload + preview + “Foto opgeslagen” op ruimtestap |
| HEIC/HEIF foto-upload (BL-008) | todo | - | Na deploy op staging met echte iPhone-foto: HEIC kiezen/maken, upload slaat op als JPEG, preview werkt, geen handmatige conversie nodig |
| Afronden + bedankt-scherm (Fase 5) | pass | 2026-07-18 | Na boolean-fix #14: volledige flow (incl. Ja/Nee) → **Bedankt** |
| HTML-rapport + installateur-review (Fase 5) | pass | 2026-07-18 | Rapport-iframe + review `prepare_quote` opgeslagen |
| AI-samenvatting in rapport (Fase 6) | blocked | 2026-07-18 | Geen “AI-voorstel” — staging `AI_PROVIDER=null` (soft-fail by design) |
| Queue-worker (cron) | todo | - | Niet end-to-end bevestigd (geen zichtbaar AI-resultaat) |
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

### Sessie 2026-07-18 (staging) — BL-002 hertest na PR #14

Scope: volledige hertest Fase 3–5 na deploy van de boolean-/regenerate-fixes (#14). Uitgevoerd door testende agent (Cursor/Playwright-Chromium), zie PR #15. *Deze hertest liep vóór de deploy van BL-018 (#18) en BL-017 (#21); die flow-/template-wijzigingen hebben nog een eigen hertest nodig (aparte `todo`-regels hierboven).*

**Pass:** homepage, health, auth, registratie, opname aanmaken, klantlink genereren/hergenereren/intrekken, klantwizard end-to-end (incl. foto’s + Ja/Nee), afronden → Bedankt, HTML-rapport, installateur-review (`prepare_quote`).

**Blocked:** AI-samenvatting — verwacht bij `AI_PROVIDER=null` (soft-fail by design).

**Open/bekend:** demo-user niet geseeded op staging (deploy seedt alleen templates; registratie als fallback); queue-worker niet los end-to-end bewezen zonder zichtbaar AI-resultaat.

BL-002 → **done**.

### Sessie 2026-07-18 (staging) — eerste BL-002 ronde (vóór fixes)

Bugs gevonden en gefixt in PR #14:

1. **Boolean-validatie (blokkerend voor afronden)** — `AnswerValueReader` eiste `is_bool()` terwijl Livewire-radio’s `"1"`/`"0"` sturen; `next()`/`complete()` bleven hangen op verplichte Ja/Nee-stappen.
2. **Klantlink hergenereren** — `<x-secondary-button>` had implicit `type="button"`, dus de POST firede niet.
3. **Foto-hydrate wist draft-velden** — `hydrateFormFromAnswers()` deed een volledige form-reset; verholpen door alleen de foto-composite te verversen.

### Sessie 2026-07-17 (staging)

Scope: getest tegen de op dat moment gedeployde staging (Fase 2 interne basis). De end-to-end intakeflow voor de installateur is volledig geverifieerd: opname aanmaken -> beveiligde klantlink -> hergenereren -> intrekken, plus dashboard en /health.

Bevindingen:

- Airco-template werd niet automatisch geseed bij deploy; handmatig gedraaid met IntakeTemplateSeeder. Inmiddels opgelost in Fase 3 (template-seeding bij deploy).
- Klantlink /o/{token} gaf 404 omdat de klant-facing route toen nog niet bestond; met Fase 3 hoort dit nu te werken en moet opnieuw getest worden.
- Nog te testen na Fase 3–6: producthomepage, klantintake via /o/{token}, foto-uploads, afronden + rapport + review, AI-samenvatting, registratie + e-mailverificatie, en een end-to-end queue-job. Zie BL-002 in `docs/backlog.md`.
