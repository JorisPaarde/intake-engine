# Functionele teststatus

> **Documentversie:** 1.27 · **Laatste update:** 2026-07-22 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Handmatig bijgehouden overzicht van wat functioneel is getest (en wat nog niet).

Bijwerken door wie de test daadwerkelijk heeft uitgevoerd: een menselijke tester **of** een testende agent (bijv. een agent die de app via een browser bedient). Niet invullen op basis van alleen implementatie — er moet echt functioneel getest zijn. Implementerende agents voegen alleen nieuwe `todo`-regels toe voor functionaliteit die zij introduceren.

Laatste testsessie: 2026-07-21 (remote; gescheiden staging/production, domeinrouting, TLS, database en storage)

| Onderdeel | Status | Getest op | Notities |
|-----------|--------|-----------|----------|
| Deploy-pipeline (push -> Actions -> rsync -> activate -> live) | pass | 2026-07-18 | Atomische symlink-swap werkt; PR #14 deploy success |
| Omgevingsscheiding staging/production (BL-010/011) | pass | 2026-07-21 | Publieke DNS, geldig TLS en HTTP→HTTPS 301 gecontroleerd. `intake-engine.nl/health` meldt `environment=production`; `staging.intake-engine.nl/health` meldt `environment=staging`. Beide: eigen app-key, sessiecookie, database, storage, releaseboom en twee cronjobs. Productionkopie behield 16 users/20 intakes; production runtime startte met 0 sessies/0 jobs. Environmentguard blokkeerde een bewust verkeerde target vóór activatie. GitHub productionworkflow zelf nog na merge via tag/dispatch smoke-testen. |
| Productionworkflow via `v*`/handmatige dispatch (BL-010) | todo | - | Na merge: production environment/secrets controleren, handmatige dispatch of release-tag uitvoeren, Actions groen en `/health` opnieuw `production`; staging mag niet wijzigen. |
| /health (app boot + DB-verbinding) | pass | 2026-07-18 | JSON ok; `php_upload` 512M/512M (BL-003) |
| /login rendert | pass | 2026-07-18 | Toont loginformulier |
| Auth-beveiliging dashboard/intakes | pass | 2026-07-18 | Uitgelogd → redirect `/login` (na dismiss 428-interstitial) |
| Dashboard weergave | pass | 2026-07-18 | Bereikbaar na registratie |
| Begeleide leidingroute — backend (BL-029) | todo | - | Met `AI_ROUTE_ANALYSIS_ENABLED=true` + `AI_PROVIDER=openai` + key op staging: foto toevoegen levert een `route_analysis`-AiRun (model = `AI_ROUTE_MODEL`), synthese een `route_synthesis`-AiRun; bij lage zekerheid volgt een tweede run met `AI_ROUTE_REVIEW_MODEL`. Zonder de vlag: geen AI-calls, wel opgeslagen segment. Alleen na DPIA/akkoord met fictieve foto's. |
| Begeleide leidingroute — UI (BL-029) | todo | - | Nog te bouwen: klant-wizard (markeer binnenunit-positie → beoordeling → steeds één vervolgfoto) en installateur-goedkeuring van de voorgestelde/alternatieve route. |
| Dev-admin `/dev` toegang (BL-028) | todo | - | Op staging: ingelogd → `/dev` bereikbaar, nav-link "Dev" zichtbaar. In productie (of `DEV_ADMIN_ENABLED=false`): `/dev` geeft 404 en nav-link ontbreekt. Uitgelogd → redirect `/login`. |
| Dev-admin dienststatus (BL-028) | todo | - | `/dev` toont per externe dienst enabled/key/base-URL/timeout en laatst-gelukt tijd; geo-diensten met opgeslagen feiten worden groen, ongebruikte grijs, ontbrekende key amber. Geen live calls. |
| Dev-admin opname-inspector (BL-028) | todo | - | `/dev/intakes` zoekt op adres/uuid; detail toont externe feiten (PDOK/BAG), AI-runs, antwoorden, uploads en de activiteiten-tijdlijn van één opname. |
| Dev-admin AI-runs/activiteit/health (BL-028) | todo | - | `/dev/ai-runs` en `/dev/activity` filteren en tonen de zojuist gegenereerde runs/events; `/dev/health` toont DB/queue-diepte/cache/storage/uploads/HEIC/versies. |
| Productmetrics `/metrics` (BL-026) | pass | lokaal 2026-07-20; staging nog todo | Authenticated weergave met periodefilter, zes kerncijfers, uitvalpunten en per-opname-links gecontroleerd; eerste beoordeling met `need_more_info` telt als 0,0% direct genoeg. Desktop en 390 px zonder pagina-overflow; tabel scrolt intern; geen nieuwe browserwarnings/-errors. Na deploy dezelfde smoke volgens `docs/metrics.md`. |
| Opname aanmaken (Airco) | pass | 2026-07-18 | Opgeslagen, detail + klantlink |
| Adres-autocomplete + BAG-verrijking (BL-019) | todo | - | Na deploy: typ adres → selecteer PDOK-suggestie → postcode/plaats gevuld; detail toont bouwjaar/gebruiksdoel/oppervlakte/locatie/perceel met bron; handmatige invoer blijft werken bij PDOK-storing |
| Airco v4: BAG-bouwjaar vervangt vraag (BL-019) | todo | - | Met eenduidig pand: klantwizard toont geen `build_year`-vraag en rapport bevat BAG-bouwjaar; zonder match/meerdere panden/storing blijft de vraag zichtbaar en dossier toont onzekerheid |
| PDOK-luchtfoto in dossier/PDF (BL-019) | todo | lokaal live pass 2026-07-20; staging nog todo | Lokaal met echte PDOK-services: Damrak 1 → 900×600 beeld, marker/bron/maat/BAG-feiten, desktop + 390 px zonder overflow of consolefouten. Na deploy dezelfde detail/PDF-, WMS-fallback- en purgecheck op staging. |
| Beveiligde klantlink genereren | pass | 2026-07-18 | Token-URL `/o/{64}` |
| Klantlink hergenereren | pass | 2026-07-18 | Na fix #14 (`type=submit`) — nieuw token gegenereerd |
| Klantlink intrekken | pass | 2026-07-18 | Status Geannuleerd + flash “Klantlink ingetrokken…” |
| Automatische klantlink-mail (BL-004) | todo | - | Na SMTP in staging `shared/.env`: opname aanmaken → mail bij klant; “Opnieuw mailen”; hergenereren mailt nieuwe link; bij `MAIL_MAILER=log` flash over config + geen mail |
| Afrondingsnotificatie installateur (BL-014) | todo | - | Na SMTP: klant rondt af → mail bij installateur; dashboard toont “Nieuw afgerond” + amber markering bovenaan (ook zonder SMTP) |
| Herinnering stilliggende intake (BL-015) | todo | - | Na SMTP + cron: intake > N dagen open → één herinneringsmail; geen tweede; ingetrokken/verlopen/afgerond geen mail |
| PDF-export rapport (BL-005) | todo | - | Na afronden + queue: knop **Download PDF** op detail; opnieuw genereren; bestand opent als PDF |
| Soft-delete purge (BL-009) | todo | - | Scheduler/daily `intakes:purge-deleted`; dossiers >30 dagen soft-deleted verdwijnen inclusief foto’s/PDF (UI soft-delete volgt later) |
| Migraties + logs op server | pass | 2026-07-17 | Alle migraties Ran; geen errors in logs |
| Airco-template beschikbaar | pass | 2026-07-18 | Selecteerbaar bij aanmaken |
| Airco-template v2 (BL-017) | todo | - | Na deploy: nieuwe opname pin’t v2; geen kamermaten-vragen; keuzelijsten i.p.v. vrije tekst buiten/route/condens; `free_group_known` / gevel optioneel; oude intakes blijven op v1 |
| Homepage / (producthomepage Fase 3) | pass | 2026-07-18 | “Digitale Opname” producthomepage (geen Laravel-welcome) |
| Registratie /register | pass | 2026-07-18 | Formulier werkt; landt op `/dashboard` |
| E-mailverificatie flow | pass | 2026-07-18 | Geen `/verify-email`-blokkade op staging na register (of niet afgedwongen) |
| Klant-intakepagina /o/{token} (Fase 3) | pass | 2026-07-18 | Wizard end-to-end (8 stappen, 1 binnenunit) — *retest was vóór deploy BL-018; hertest nodig* |
| Vraag-voor-vraag klantflow (BL-018) | todo | - | Na deploy: één vraag per scherm, sectietitel als markering, Volgende/Vorige, conditionele vraag verschijnt pas na relevant antwoord, hervatten op juiste vraag |
| Auto-doorgaan na keuze + Enter (BL-023) | todo | - | Na deploy: single_choice/boolean gaat automatisch door na keuze (Opgeslagen-bevestiging); Enter op tekst/nummer = Volgende; multi_choice/foto/long_text niet; Vorige blijft werken; laatste stap geen auto-afronden |
| Voortgang + ontbreekt-lijst (BL-022) | todo | - | Na deploy: % bereikt 100 bij alleen verplichte vragen klaar (optioneel leeg mag); bij geblokkeerd afronden zijn ontbrekende items klikbaar en tonen “Ruimtes 2” i.p.v. `room-2` |
| Installateur-prefill bij aanmaken (BL-016) | todo | - | Na deploy: "alvast invullen" op opname-aanmaken (airco v3 request-vragen); klant ziet ze met "alvast ingevuld — controleer"; intake blijft `sent` tot klant start |
| Repeatable-prefill ruimtes (BL-016) | todo | - | Na deploy: bij ≥2 binnenunits neemt ruimte 2 `floor_level` over van ruimte 1 als bewerkbare voorzet ("Overgenomen van Ruimtes 1"); pas bij Volgende opgeslagen; ruimte 1 nooit voorgevuld |
| Foto-uploads (Fase 4) | pass | 2026-07-18 | JPEG-upload + preview + “Foto opgeslagen” op ruimtestap |
| Foto multiselect + galerij (BL-021) | todo | - | Na deploy: meerdere foto's in één keer kiezen; op mobiel camera én galerij (geen geforceerde camera); één mislukte foto blokkeert de rest niet; max_files wordt gehandhaafd |
| HEIC/HEIF foto-upload (BL-008) | todo | - | Na deploy op staging met echte iPhone-foto: HEIC kiezen/maken, upload slaat op als JPEG, preview werkt, geen handmatige conversie nodig |
| Leesbare foto-galerij installateur (BL-024) | todo | - | Na deploy: opname-detail toont vraaglabels + groepen (bv. “Ruimtes 2” / “Foto’s van de ruimte”), geen rauwe `question_key`/`room-2` |
| Afronden + bedankt-scherm (Fase 5) | pass | 2026-07-18 | Na boolean-fix #14: volledige flow (incl. Ja/Nee) → **Bedankt** |
| HTML-rapport + installateur-review (Fase 5) | pass | 2026-07-18 | Rapport-iframe + review `prepare_quote` opgeslagen |
| Dossierbronnen + onzekerheden + volgende stap (BL-019) | todo | - | HTML/PDF toont klant/contact, automatisch verzamelde feiten met PDOK/BAG-bron en zekerheid, open onzekerheden en voorstel volgende stap |
| Gerichte aanvullende informatieronde (BL-027) | todo | - | Review `need_more_info` met tekst + foto-opdracht → klantmail/dezelfde link → klant ziet alleen vervolgitems, rondt af → dossier toont ronde/bron/antwoord/foto en dashboard markeert opnieuw als te beoordelen; test ook handmatige linkfallback bij mailconfig |
| Gericht PDF-document opvragen (BL-027) | pass | lokaal 2026-07-20; staging nog todo | Featuretest: documentopdracht, herstel na ongeldige PDF, upload, afronden, auth-link, forced download en HTML/PDF-dossier groen. Gegenereerde 5-pagina-PDF visueel gecontroleerd: documentkaart met prompt, bestandsnaam, bron en ronde zonder clipping. Live documentstap desktop + 390 px zonder overflow of browserwarnings/-errors; route geeft `application/pdf`, attachment en `nosniff`. Na deploy dezelfde smoke met een echte PDF. |
| AI-samenvatting in rapport (Fase 6) | blocked | 2026-07-18 | Geen “AI-voorstel” — staging `AI_PROVIDER=null` (soft-fail by design) |
| AI-aandachtspunten voorstellen + accept/verwijder (BL-007) | todo | - | Met `AI_PROVIDER=heuristic`: opnamepagina → "AI-aandachtspunten voorstellen" → accepteren (komt in rapport) / verwijderen (blijft weg); `null` = geen voorstellen |
| Fotokwaliteit-hint klant + label installateur (BL-007) | todo | - | Donkere/kleine foto in klantflow → niet-blokkerende hint, afronden blijft mogelijk; installateursgalerij toont kwaliteitslabel |
| Externe LLM-provider (BL-006) | todo | - | Alleen ná DPIA + `AI_API_KEY`: `AI_PROVIDER=openai` levert samenvatting/aandachtspunten; controleer dat geen e-mail/telefoon in de payload staat |
| Airco v5 meterkastfoto-afleiding (BL-020) | todo | - | Eerst lokaal met `AI_PROVIDER=fake` + flag: meterkastfoto → `free_group_known` staat als foto-inschatting klaar en blijft corrigeerbaar; dossier toont fase/vrije groep, bron `AI-fotoanalyse` en onzekerheid; foto verwijderen wist voorzet/fact. Daarna alleen ná DPIA met fictieve stagingbeelden en multimodaal model: hoge zekerheid, onduidelijk beeld met concrete herhaalinstructie, providerfout soft-fail en geen beeldbytes/data-URL in logs/DB. |
| Queue-worker (cron) | todo | - | Niet end-to-end bevestigd (geen zichtbaar AI-resultaat) |
| Demo-login `installateur@example.com` | fail | 2026-07-18 | Credentials matchen niet — `DatabaseSeeder` draait niet bij deploy (alleen IntakeTemplateSeeder) |
| Publieke demo “Start demo” (BL-001) | todo | - | Na deploy (demo standaard aan): knop op `/` voor gasten, redirect `/o/{token}`, banner (AI aan; e-mail/PDF/dashboard uit), afronden toont AI-voorstel op bedankt-scherm + registratielink. Ingelogd: geen demoknop. Als `shared/.env` nog `DEMO_ENABLED=false` heeft: op `true` of verwijderen + `config:cache`. |
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

### Sessie 2026-07-20 (lokaal) — BL-027 documentopdracht

Een open vervolgrond is omgezet naar antwoordvorm **Document (PDF)** met een synthetisch document op private storage. De klantweergave toont prompt, bestandsnaam, resterende slots en verwijderactie op desktop en 390 px zonder horizontale pagina-overflow of nieuwe browserwarnings/-errors. De documentroute retourneert `200`, `Content-Type: application/pdf`, `Content-Disposition: attachment` en `X-Content-Type-Options: nosniff`. Het opnieuw gegenereerde dossier telt 5 A4-pagina's; alle pagina's zijn visueel gecontroleerd en de documentkaart met prompt, bestandsnaam, bron en ronde blijft bij elkaar zonder clipping. Staging blijft `todo` tot deploy.

### Sessie 2026-07-20 (lokaal) — BL-026 metrics

`/metrics?period=all` gecontroleerd als installateur met vier privacyveilige testintakes. De eerste beoordeling van opname #4 was `need_more_info`; de kaart **Direct genoeg informatie** en de rij tonen daarom 0,0% / **Nee**, ook na latere dossiermutaties. Desktop en 390 px renderen zonder pagina-overflow; alleen de brede per-opnametabel scrolt horizontaal binnen zijn eigen container. Er kwamen geen nieuwe browserwarnings of -errors bij. Staging blijft `todo` tot deploy.

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
