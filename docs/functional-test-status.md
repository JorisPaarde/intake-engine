# Functionele teststatus

> **Documentversie:** 1.3 · **Laatste update:** 2026-07-18 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Handmatig bijgehouden overzicht van wat functioneel is getest (en wat nog niet).

**Niet** invullen via geautomatiseerde agent-implementatie; bijwerken door de testende agent of tester. Implementerende agents voegen alleen nieuwe `todo`-regels toe voor functionaliteit die zij introduceren.

Laatste testsessie: 2026-07-18 (staging via headless Chromium/Playwright; na deploy PR #14)

| Onderdeel | Status | Getest op | Notities |
|-----------|--------|-----------|----------|
| Deploy-pipeline (push -> Actions -> rsync -> activate -> live) | pass | 2026-07-18 | PR #14 deploy success (~39s) |
| /health (app boot + DB-verbinding) | pass | 2026-07-18 | JSON ok; `php_upload` 512M/512M (BL-003) |
| /login rendert | pass | 2026-07-18 | Toont loginformulier |
| Auth-beveiliging dashboard/intakes | pass | 2026-07-18 | Uitgelogd → redirect `/login` (na dismiss 428-interstitial) |
| Dashboard weergave | pass | 2026-07-18 | Bereikbaar na registratie |
| Opname aanmaken (Airco) | pass | 2026-07-18 | Opgeslagen, detail + klantlink |
| Beveiligde klantlink genereren | pass | 2026-07-18 | Token-URL `/o/{64}` |
| Klantlink hergenereren | pass | 2026-07-18 | Na fix `type=submit` — nieuw token gegenereerd |
| Klantlink intrekken | pass | 2026-07-18 | Status Geannuleerd + flash “Klantlink ingetrokken…” |
| Migraties + logs op server | pass | 2026-07-17 | Alle migraties Ran; geen errors in logs |
| Airco-template beschikbaar | pass | 2026-07-18 | Selecteerbaar bij aanmaken |
| Homepage / (producthomepage Fase 3) | pass | 2026-07-18 | “Digitale Opname” producthomepage |
| Registratie /register | pass | 2026-07-18 | Formulier werkt; landt op `/dashboard` |
| E-mailverificatie flow | pass | 2026-07-18 | Geen `/verify-email`-blokkade op staging na register |
| Klant-intakepagina /o/{token} (Fase 3) | pass | 2026-07-18 | Wizard 8 stappen (1 binnenunit) end-to-end |
| Foto-uploads (Fase 4) | pass | 2026-07-18 | JPEG-upload + preview; zichtbaar in installer-galerij |
| Afronden + bedankt-scherm (Fase 5) | pass | 2026-07-18 | Na boolean-fix: volledige flow → **Bedankt** |
| HTML-rapport + installateur-review (Fase 5) | pass | 2026-07-18 | Rapport-iframe + review `prepare_quote` opgeslagen |
| AI-samenvatting in rapport (Fase 6) | blocked | 2026-07-18 | Geen “AI-voorstel” — staging `AI_PROVIDER=null` (soft-fail by design) |
| Queue-worker (cron) | todo | - | Niet end-to-end bevestigd zonder zichtbaar AI-resultaat |
| Demo-login `installateur@example.com` | fail | 2026-07-18 | Ontbreekt op staging (deploy seedt alleen templates); registratie als fallback |

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

Scope: volledige hertest Fase 3–6 na deploy van boolean-/regenerate-fixes.

**Pass:** homepage, health, auth, registratie, opname aanmaken, klantlink genereren/hergenereren/intrekken, klantwizard end-to-end (incl. foto’s + Ja/Nee), afronden → Bedankt, HTML-rapport, installateur-review.

**Blocked:** AI-samenvatting (verwacht bij `AI_PROVIDER=null`).

**Open/bekend:** demo-user niet geseeded op staging; queue-worker niet los end-to-end bewezen.

BL-002 → **done**.

### Sessie 2026-07-18 (staging) — eerste BL-002 ronde (vóór fixes)

Bugs gevonden (gefixt in PR #14):

1. Boolean-validatie blokkeerde “Volgende” (`AnswerValueReader` eiste `is_bool`, Livewire stuurt `"1"`/`"0"`).
2. “Nieuwe link genereren” POSTte niet (`x-secondary-button` default `type=button`).
3. Foto-hydrate wist draft-velden (volledige form-reset).

### Sessie 2026-07-17 (staging)

Scope: Fase 2 interne basis — opname aanmaken → klantlink → hergenereren → intrekken, dashboard, /health. Zie eerdere notities in git-historie.
