# Backlog — Digitale Opname

> **Documentversie:** 3.20 · **Laatste update:** 2026-07-18 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

De **enige backlog** van dit project: al het werk dat bewust niet in de afgeronde MVP-fasen 1–6 zit (zie `docs/implementation-plan.md`), plus nieuw ontdekt werk. Proces en statusregels: zie [AGENTS.md § Backlogproces](../AGENTS.md#backlogproces).

De items zijn gegroepeerd in **vijf epics**. Elke epic is herleid naar het vaste [hoofddoel](../AGENTS.md#hoofddoel-vast--niet-door-agents-aan-te-passen) (*met zo min mogelijk handelingen van aanvraag naar een bruikbaar dossier; voor iedere ontbrekende informatie de eenvoudigste manier van aanleveren*) en het vaste [ontwerpprincipe](../AGENTS.md#ontwerpprincipe-vast--niet-door-agents-aan-te-passen) (*niets vragen wat al bekend is of eenvoudiger kan worden vastgesteld; per ontbrekend gegeven de snelste en duidelijkste verzamelmethode*).

Status: `backlog` · `ready` · `in_progress` · `done` · `dropped` — prioriteit: `high` · `medium` · `low`

**Leeswijzer:** scan de epictabel en de overzichtstabel hieronder; open daarna alleen de detailsectie van het item waaraan je werkt.

## Epics

| Epic | Naam | Koppeling met hoofddoel / ontwerpprincipe |
|------|------|-------------------------------------------|
| E1 | Frictieloze basisflow | Elke haperende stap (mislukte foto-upload, SSL-waarschuwing, onbevestigde werking) kost de aanvrager extra handelingen of breekt het pad naar een bruikbaar dossier. |
| E2 | Communicatie zonder handwerk | Link doorsturen, afronding signaleren en nabellen zijn nu handmatige installateurshandelingen; die moeten verdwijnen. |
| E3 | Vraag minder, verzamel slimmer | Directe toepassing van het ontwerpprincipe op de intake zelf: hergebruik wat bekend is, kies per gegeven de snelste en duidelijkste verzamelmethode. |
| E4 | AI bespaart beoordeelwerk | Samenvatting, aandachtspunten en fotokwaliteitscheck besparen de installateur leeswerk en de aanvrager een extra aanleverronde. AI blijft ondersteunend (docs/ai.md). |
| E5 | Bruikbaar dossier & klaar voor groei | Het dossier moet buiten de browser bruikbaar zijn en het product moet zonder extra handelingen te ervaren, beheren en opschalen zijn. |

Volgorde-advies: volg kolom **#** in de overzichtstabel hieronder. De rode draad: eerst lopend werk afronden (BL-001; BL-002/BL-004/BL-005/BL-008/BL-009/BL-014/BL-015/BL-016/BL-021/BL-023/BL-024 done), dan drempelloze aanlevering (BL-011 domein/SSL) en het verder gladstrijken van de bestaande flow (BL-022 — verbeterronde 2026-07-18) — daarna slimme afleiding (BL-019, BL-006, BL-020) en tot slot groei-/beheeritems. Parallelisatie: zie [§ Parallelisatie](#parallelisatie) en kolom **Band**.

## Parallelisatie

Items in **verschillende parallel-bands** kunnen tegelijk door aparte agents/mensen worden gebouwd (andere codepaden of externe acties). Items in dezelfde **keten** zijn sequentieel. Kolom **Band** in de overzichtstabel verwijst hiernaar. (Bands van afgeronde items blijven in de tabel als geheugen.)

| Band | Type | Items (open) | Mag parallel met |
|------|------|--------------|------------------|
| **A** | Afronden (lopend) | BL-001 | D–I (staging-config/smoke; weinig codeconflict) |
| **D** | Infra (extern) | BL-011 | Alles (vooral producteigenaar/host) |
| **F** | Open data / adres | BL-019 | A, D, H, I; BAG-prefill bouwt voort op afgeronde BL-016-kaders |
| **H** | AI-keten | BL-006 → daarna BL-007 + BL-020 | A, D, F, I. Binnen H: BL-007 en BL-020 parallel **ná** BL-006 (+ DPIA) |
| **I** | Beheer / schaal | BL-010, BL-013 (BL-012 later) | Onderling parallel; met A–H zolang geen gedeelde deploy-/storage-wijziging botst |
| **J** | Klantwizard-verbeteringen | BL-022 → BL-025 | A, D, F, H, I. Binnen J sequentieel: raken `IntakeWizard` + wizard-view; BL-021/BL-023 done |
| **K** | Installateursweergave | — (BL-024 done) | — |

Afgeronde bands (niet meer te plannen): **B** = BL-016 (prefill), **C** = BL-008 (HEIC), **E** = BL-004/BL-014/BL-015 (mail-keten), **G** = BL-005 (PDF), **K** = BL-024 (installateursgalerij); band J deels: BL-021 (multiselect) + BL-023 (auto-doorgaan); BL-009 purge done.

**Concrete parallel-startsets:**

1. **Nu parallel bouwbaar:** BL-011 (extern) · BL-022 (vervolg band J) · BL-019 — naast afronden van BL-001; SMTP op staging aanzetten voor mail-smoketests (BL-004/014/015).
2. **Na DPIA + BL-006:** BL-007 en BL-020 parallel.
3. **Laag-prioriteit parallel:** BL-010 · BL-013 · BL-025 (na band-J-voorgangers) · (BL-012 bij tweede klant).

## Overzicht

Geprioriteerd op het hoofddoel (herprioritering 2026-07-18): hoeveel handelingen bespaart of repareert een item in de kernflow van aanvrager en installateur, hoe direct, en voor hoeveel intakes? Kolom **#** is de aanbevolen uitvoeringsvolgorde (afhankelijkheden meegewogen); kolom **Band** = parallelgroep (zie [§ Parallelisatie](#parallelisatie)); `done`/`dropped` staan onderaan zonder volgnummer.

| # | ID | Item | Epic | Status | Prioriteit | Band |
|---|----|------|------|--------|------------|------|
| 1 | BL-001 | Demo-versie van de app | E5 | in_progress | medium | A |
| 2 | BL-011 | Eigen domein + geldig SSL voor staging | E1 | backlog | high | D · parallel |
| 3 | BL-022 | Voortgang en "ontbreekt nog" kloppend en klikbaar maken | E1 | backlog | medium | J · parallel |
| 4 | BL-019 | Afleiden uit adres en openbare bronnen (satellietbeeld, BAG) | E3 | backlog | medium | F · parallel |
| 5 | BL-006 | Externe LLM-provider (na DPIA) | E4 | backlog | medium | H · parallel† |
| 6 | BL-020 | Foto-gedreven afleiding en adaptieve vervolgvragen | E4 | backlog | medium | H · na BL-006 |
| 7 | BL-007 | AI-uitbreidingen: attention points, fotokwaliteit, accepteren/verwijderen | E4 | backlog | low | H · na BL-006 |
| 8 | BL-025 | Wizard-responstijd: dubbele queries per Livewire-request terugdringen | E1 | backlog | low | J · na BL-022 |
| 9 | BL-010 | Production-deployworkflow (tags + eigen omgeving) | E5 | backlog | low | I · parallel |
| 10 | BL-012 | Multi-tenancy (companies) | E5 | backlog | low | I · later |
| 11 | BL-013 | S3 als mediadisk | E5 | backlog | low | I · parallel |
| — | BL-023 | Eén tik minder per vraag: automatisch door na eenduidige keuze | E3 | done | medium | J (done) |
| — | BL-021 | Foto's: meerdere tegelijk uploaden en galerijkeuze niet blokkeren | E1 | done | high | J (done) |
| — | BL-024 | Leesbaar dossier: vraaglabels i.p.v. keys in installateursweergave | E5 | done | low | K (done) |
| — | BL-014 | Afrondingsnotificatie voor de installateur | E2 | done | medium | E (done) |
| — | BL-015 | Herinnering bij stilliggende intake | E2 | done | medium | E (done) |
| — | BL-005 | PDF-export van rapporten | E5 | done | medium | G (done) |
| — | BL-009 | Purge-job voor soft-deleted intakes (bewaartermijn) | E5 | done | low | I (done) |
| — | BL-004 | Automatische e-mail van klantlink (SMTP) | E2 | done | medium | E (done) |
| — | BL-016 | Hergebruik bekende gegevens (prefill) | E3 | done | high | B (done) |
| — | BL-008 | HEIC-ondersteuning bij foto-uploads | E1 | done | high | C (done) |
| — | BL-002 | Functionele hertest staging (Fase 3–6) | E1 | done | high | A (done) |
| — | BL-017 | Airco-template v2: vraag-voor-vraag audit op het ontwerpprincipe | E3 | done | high | — |
| — | BL-018 | Vraag-voor-vraag klantflow (één vraag per scherm) | E3 | done | high | — |
| — | BL-003 | Staging PHP-uploadlimieten verifiëren/verhogen | E1 | done | high | — |

† BL-006 parallel met productwerk, maar geblokkeerd tot DPIA/akkoord.

## Epic E1 — Frictieloze basisflow

De flow van Fase 1–6 belooft "zo min mogelijk handelingen", maar dat geldt alleen als elke stap ook echt werkt. Een mislukte iPhone-foto of een SSL-tussenscherm kost de aanvrager juist éxtra handelingen — het tegendeel van het hoofddoel. Foto's zijn bovendien onze snelste verzamelmethode (ontwerpprincipe), dus uploads moeten op elk toestel betrouwbaar zijn.

### BL-002 — Functionele hertest staging (Fase 3–6)

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-18 · **PR:** #14 (fixes) + docs-afronding · **Ref:** `docs/functional-test-status.md`
- **Doel:** de sinds de testsessie van 2026-07-17 gedeployde functionaliteit handmatig verifiëren op staging: producthomepage `/`, klantintake `/o/{token}`, foto-uploads, afronden + rapport + review, AI-samenvatting via queue, registratie + e-mailverificatie, end-to-end queue-job.
- **Resultaat:** kernflow Fase 3–5 **pass** (incl. hergenereren, intrekken, foto's, afronden → bedankt, HTML-rapport, installateur-review). Tijdens de test bugs gevonden en gefixt (#14: boolean-validatie, regenerate-knop, foto-hydrate). AI-samenvatting **blocked** (`AI_PROVIDER=null`, soft-fail by design). Queue-worker niet los end-to-end bewezen; demo-user niet geseeded op staging. **Let op:** deze hertest liep vóór de deploy van BL-018/BL-017 — die flow-/template-wijzigingen hebben nog een eigen hertest nodig (zie `todo`-regels in `docs/functional-test-status.md`).
- **Afhankelijkheden:** geen meer — BL-003 is done (uploadlimieten op staging ok).

### BL-003 — Staging PHP-uploadlimieten verifiëren/verhogen

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-18 · **PR:** #12 (limieten + `/health`), docs-afronding #13
- **Doel:** PHP-limieten ≥ app-limiet (5 MB): minimaal `upload_max_filesize=10M`, `post_max_size=12M`; gemeten waarden documenteren in `docs/uploads.md`.
- **Resultaat:** staging web-SAPI via `GET /health` → `php_upload`: `upload_max_filesize=512M`, `post_max_size=512M`, `max_file_uploads=20` (ruim boven minimum). `public/.user.ini` (10M/12M) blijft in git als vangnet; host staat hoger. Gemeten waarden in `docs/uploads.md`.
- **Waarom:** te lage PHP-limieten breken mobiele foto-uploads stil — en een mislukte upload is voor de aanvrager de duurste handeling die er is.

### BL-008 — HEIC-ondersteuning bij foto-uploads

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-18 · **PR:** #24 · **Ref:** `docs/functional-test-status.md`
- **Doel:** iPhones maken standaard HEIC-foto's; de allowlist is nu jpeg/png/webp. Onderzoek server-side conversie (Imagick op cPanel?) of client-side conversie vóór upload. De aanvrager mag nooit zelf hoeven converteren of instellingen omzetten.
- **Resultaat:** upload-input accepteert jpeg/png/webp/heic/heif; server-side MIME-detectie blijft leidend (incl. ISO BMFF-brand-sniffing voor HEIC/HEIF bij `application/octet-stream`). HEIC/HEIF wordt met Imagick automatisch naar JPEG omgezet (auto-orient, metadata strippen, max lange zijde, kwaliteitsstappen binnen app-limiet). Opgeslagen bestanden blijven jpeg/png/webp; preview-routes blijven ongewijzigd. Staging iPhone-smoketest staat als `todo` in `docs/functional-test-status.md`.

### BL-011 — Eigen domein + geldig SSL voor staging

- **Status:** backlog · **Prioriteit:** high *(opgehoogd 2026-07-18 bij hoofddoel-herprioritering: dit kost élke aanvrager twee handelingen vóór de eerste vraag)*
- **Parallel:** band **D** — parallel met alle code-items (extern: producteigenaar/host).
- **Doel:** het tijdelijke `.cpanel.site`-domein (self-signed, "Technical Domain"-tussenscherm) vervangen door een eigen (sub)domein met Let's Encrypt. Daarna README-omgevingstabel bijwerken.
- **Waarom:** het tussenscherm en de browserwaarschuwing zijn twee extra handelingen (en een vertrouwensbreuk) vóór de aanvrager ook maar één vraag heeft gezien.
- **Afhankelijkheden:** extern — er moet een eigen (sub)domein aan het hostingaccount gekoppeld worden; actie producteigenaar/host.

### BL-021 — Foto's: meerdere tegelijk uploaden en galerijkeuze niet blokkeren

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-18 · **PR:** #29 · **Ref:** `docs/uploads.md`, `docs/functional-test-status.md`
- **Parallel:** band **J** (done) — kettingkop; vervolg is BL-023 → BL-022 → BL-025.
- **Doel:** twee verbeteringen op de bestaande foto-upload in de klantwizard:
  - **Meerdere foto's in één keer selecteren:** de file-input heeft nu geen `multiple`, terwijl vragen tot `meta.max_files = 5` foto's toestaan — de aanvrager tikt nu per foto opnieuw "Foto maken of kiezen". Multiselect + upload per bestand (één mislukte foto blokkeert de rest niet).
  - **Galerijkeuze niet blokkeren:** de input hardcodet `capture="environment"`, wat op veel mobiele browsers direct de camera afdwingt. Wie de foto's al gemaakt heeft (of even rondloopt en daarna uploadt) kan er nu niet bij — beide paden (camera én galerij) moeten open staan.
- **Resultaat:** file-input heeft `multiple` en geen `capture`; `IntakeWizard::uploadPhotosForComposite` verwerkt elk bestand apart (succes blijft staan bij gedeeltelijke fout); UI toont resterende slots / “maximum bereikt”; `max_files` blijft server-side in `StoreIntakeUpload`. Staging-smoketest als `todo` in `docs/functional-test-status.md`.
- **Waarom (hoofddoel):** airco v2/v3 vraagt tot ~20 foto's per intake (ruimtes 5+3 per unit, buiten 5+3, route 5, meterkast 3, afvoer 3). Elke foto is nu een aparte tik-cyclus; multiselect en galerijkeuze halveren de duurste handelingenreeks van de hele intake.
- **Kaders:** bestaande server-side pijplijn per bestand blijft leidend (validatie, MIME-detectie, HEIC→JPEG-normalisatie uit BL-008); `max_files` server-side handhaven; per-bestand-foutmelding zodat de aanvrager alleen de mislukte foto opnieuw doet.
- **Afhankelijkheden:** geen — puur klantwizard (`IntakeWizard::updatedPhotoFiles` + upload-blok in de wizard-view).

### BL-022 — Voortgang en "ontbreekt nog" kloppend en klikbaar maken

- **Status:** backlog · **Prioriteit:** medium *(verbeterronde 2026-07-18)*
- **Parallel:** band **J** (ketenkop na BL-023) — parallel met A/D/F/H/I; binnen J vóór BL-025.
- **Doel:** drie verbeteringen op de bestaande voortgangs- en compleetheidsweergave:
  - **Percentage dat klopt met "klaar":** `ProgressCalculator` telt ook optionele onbeantwoorde vragen mee, waardoor een intake die klaar is om af te ronden op bv. 98% blijft hangen. Baseer het getoonde percentage op verplichte zichtbare vragen (of toon verplicht/optioneel gescheiden) zodat 100% = afronden kan.
  - **Ontbrekende vragen klikbaar:** de lijst "Nog niet alles is ingevuld" toont nu alleen labels; laat elk item naar de betreffende stap springen (`goToStep` bestaat al) in plaats van de aanvrager te laten terugbladeren.
  - **Leesbare instantienamen:** toon "Ruimte 2" in plaats van de rauwe `section_instance_key` (`room-2`) in de ontbrekend-lijst.
- **Waarom (hoofddoel):** de laatste meters vóór afronden kosten nu zoekwerk: een misleidend percentage en een niet-navigeerbare foutlijst zijn extra handelingen op het moment dat de aanvrager al bijna klaar was — precies waar afhakers vallen.
- **Kaders:** `CompletenessChecker` blijft de enige poort voor afronden; dit is presentatie/navigatie, geen wijziging van compleetheidsregels.
- **Afhankelijkheden:** geen harde; na BL-023 (done) in band J plannen wegens gedeelde bestanden.

### BL-025 — Wizard-responstijd: dubbele queries per Livewire-request terugdringen

- **Status:** backlog · **Prioriteit:** low *(verbeterronde 2026-07-18)*
- **Parallel:** band **J** — als laatste in de keten (raakt dezelfde component; puur intern).
- **Doel:** `IntakeWizard` haalt per Livewire-request meerdere keren dezelfde data op: `intake()` doet telkens een verse `findOrFail` en `version()` laadt telkens de volledige sections/questions/options/rules-graaf, terwijl `steps()`, `render()`, `currentStep()` en de visibility-checks elkaar per request herhaaldelijk aanroepen. Memoizeer per request (met bewuste invalidatie na saves) en meet de responstijd van autosave/"Volgende" vóór en na.
- **Waarom (hoofddoel):** elke vraag is een server-roundtrip (autosave + stapnavigatie); onnodig trage responses voelen op mobiel als wachten per vraag — frictie op precies het pad dat we het lichtst willen maken.
- **Kaders:** gedrag ongewijzigd (pure performance); let op Livewire-hydration en stale state na `SaveIntakeAnswer`/uploads; bestaande featuretests blijven de poort.
- **Afhankelijkheden:** geen harde; na de andere band-J-items zodat er niet in hetzelfde bestand geparallelliseerd wordt.

## Epic E2 — Communicatie zonder handwerk

Tussen "installateur maakt opname aan" en "installateur beoordeelt dossier" zitten nu drie handmatige handelingen: de link zelf versturen, het dashboard checken op afgeronde intakes, en stilgevallen aanvragers nabellen. Elk daarvan kan het systeem overnemen.

### BL-004 — Automatische e-mail van klantlink (SMTP)

- **Status:** done · **Prioriteit:** medium · **Datum:** 2026-07-18 · **PR:** #25
- **Doel:** klantlink automatisch mailen i.p.v. alleen kopieerbaar maken. Vereist werkende SMTP-configuratie (staging heeft nu `MAIL_MAILER=log`); daarna ook registratie/e-mailverificatie betrouwbaar.
- **Resultaat:** na aanmaken (en na token-hergenereren) stuurt `SendCustomerIntakeLink` een Nederlandse mailable naar `customer_email`; detailpagina heeft **Opnieuw mailen**. Kopieerbare `#customer-link` blijft fallback. Bij `MAIL_MAILER=log` wordt mail **overslagen** (geen tokens in logs, ADR-0002); soft-fail bij SMTP-fouten. Demo-intakes mailen nooit. Activity-event `customer_link_mailed` zonder token/URL.
- **Nog te doen op staging:** SMTP zetten in `shared/.env` (zie [DEPLOYMENT § Handmatige acties](DEPLOYMENT.md#handmatige-acties-producteigenaar) / § Mail) + smoke-test; zie `todo` in `docs/functional-test-status.md`.
- **Afhankelijkheden:** SMTP-account op host of externe mailprovider (voor echte bezorging).
- **Let op:** tokens nooit in logs (ADR-0002); kopieerbare link blijft bestaan als fallback.

### BL-014 — Afrondingsnotificatie voor de installateur

- **Status:** done · **Prioriteit:** medium · **Datum:** 2026-07-18 · **PR:** #26
- **Doel:** zodra de klant afrondt, krijgt de installateur een signaal (mail en/of dashboard-markering) zodat de beoordeling direct kan starten. Bespaart het periodiek handmatig checken van het dashboard.
- **Resultaat:** dashboard markeert en sorteert **Nieuw afgerond** (`status=completed` + `reviewed_at` null). Na afronden stuurt `SendInstallerIntakeCompleted` een mailable naar de creator; skip bij demo/`MAIL_MAILER=log`; activity-event `installer_completion_mailed` zonder PII. Staging-smoke wacht op SMTP (zelfde als BL-004).
- **Afhankelijkheden:** mailvariant vereist SMTP (BL-004-kaders); dashboard-deel werkt zonder.

### BL-015 — Herinnering bij stilliggende intake

- **Status:** done · **Prioriteit:** medium · **Datum:** 2026-07-18 · **PR:** #26
- **Doel:** scheduled command: klant kreeg een link maar rondde niet af binnen N dagen → één automatische herinnering met dezelfde hervat-link. Bespaart de installateur het nabellen en de aanvrager het terugzoeken van de link.
- **Resultaat:** daily `intakes:send-reminders`; `INTAKE_REMINDER_DAYS` (default 3); kolom `reminder_sent_at`; max. één mail; stopt bij demo/ingetrokken/verlopen/niet-klanttoegankelijk; skip bij `MAIL_MAILER=log` (ADR-0002); activity-event `customer_reminder_mailed`.
- **Afhankelijkheden:** SMTP voor echte bezorging (zelfde als BL-004).
- **Niet doen:** herhaald mailen; maximaal één herinnering per intake, en stoppen bij ingetrokken/verlopen token.

## Epic E3 — Vraag minder, verzamel slimmer

De meest directe toepassing van het ontwerpprincipe: *de applicatie vraagt niets wat al bekend is of eenvoudiger kan worden vastgesteld*. Elke geschrapte of slimmer gestelde vraag is een blijvende besparing voor élke toekomstige aanvrager.

### BL-016 — Hergebruik bekende gegevens (prefill)

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-18 · **Ref:** [intake-engine.md § Prefill](../docs/intake-engine.md#prefill-van-bekende-gegevens-bl-016)
- **Doel:** gegevens die al bekend zijn nooit opnieuw aan de aanvrager vragen:
  - wat de installateur bij het aanmaken al invulde (bijv. aanleiding/klantcontext) vooraf tonen of overslaan;
  - afleidbare waarden berekenen i.p.v. uitvragen;
  - binnen repeatable secties (ruimtes) zinvolle antwoorden van de vorige instantie als voorzet aanbieden.
- **Resultaat:** deterministische prefill via vraag-`meta`, altijd als bewerkbare, gemarkeerde voorzet die de aanvrager bevestigt (geen LLM):
  - **Installateur-prefill** (`installer_prefillable`): de installateur beantwoordt bekende `request`-vragen bij het aanmaken; opgeslagen met `intake_answers.prefill_source = 'installer'` en in de wizard getoond als "alvast ingevuld — controleer". Zet de intake niet op `in_progress`.
  - **Repeatable-prefill** (`prefill_from_previous`): `IntakePrefillResolver` biedt in ruimte 2..n het antwoord van de vorige ruimte aan (airco: `floor_level`); pas bij "Volgende" opgeslagen als eigen antwoord.
  - Airco **v3** gepubliceerd (v2-vragenset + vlaggen; ADR-0001). Nieuwe migratie `prefill_source`.
- **Bewust nog niet (was derde deeldoel):** afleidbare/berekende waarden (bouwjaar, gebouwtype, geometrie) vergen externe bronnen (adres/BAG/foto's) en vallen onder **BL-019** en **BL-020** — daar opgepakt, met dezelfde voorzet-kaders.
- **Kaders:** prefill is een voorzet, geen verborgen aanname — de aanvrager ziet en bevestigt wat is overgenomen. Deterministisch, geen LLM in deze keten (`docs/intake-engine.md`).

### BL-017 — Airco-template v2: vraag-voor-vraag audit op het ontwerpprincipe

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-18 · **PR:** #21
- **Doel:** elke vraag in de airco-template toetsen aan het ontwerpprincipe: is dit al bekend of afleidbaar (schrappen)? Is er een snellere/duidelijkere verzamelmethode (foto i.p.v. meetvraag, keuzelijst i.p.v. vrije tekst, boolean i.p.v. open vraag)? Feedback van installateurs meenemen.
- **Resultaat:** `database/data/templates/airco/v2.php` + seeder publiceert v1 én v2 (nieuwe intakes → latest = v2; ADR-0001). Concrete wijzigingen: kamermaten → `room_size_indication`; vrije tekst → keuzelijsten (`outdoor_location`, `outdoor_accessibility`, `pipe_route_description`, `drain_location`, `floor_level`); afstanden ontdubbeld (alleen optionele `pipe_distance_indication`); `facade_overview_photo` en `free_group_known` optioneel; `distance_to_indoor` / `fusebox_distance` / exacte maten geschrapt. Verdere afleiding volgt in BL-019/BL-020.
- **Afhankelijkheden:** geen harde; installateurs-feedback kan later tot v3 leiden.

### BL-018 — Vraag-voor-vraag klantflow (één vraag per scherm)

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-18 · **PR:** #18
- **Doel:** de klantwizard toont nu een hele sectie per scherm; de producteigenaar wil vragen **stap voor stap** stellen: één vraag (of één logisch mini-cluster, zoals een foto-opdracht met bijbehorende controle­vraag) per scherm, met autosave per antwoord en duidelijke voortgang.
- **Waarom (hoofddoel):** één vraag per scherm voelt lichter, werkt beter op mobiel en maakt conditionele logica direct zichtbaar (vervolgvraag verschijnt pas als die relevant is) — minder scrollen en minder afhaken.
- **Kaders:** de datastructuur (secties → vragen) blijft ongewijzigd; dit is een presentatielaag bovenop de bestaande engine. Sectietitels blijven als hoofdstukmarkering zichtbaar. Regels (`show`/`require`) evalueren per antwoord, zodat overgeslagen vragen nooit getoond worden.
- **Resultaat:** `IntakeStepBuilder` bouwt één stap per zichtbare vraag; wizard toont sectietitel + “Vraag X van Y”; hervatten via `current_question_key` / `current_section_instance_key`; conditionele vragen verschijnen/verdwijnen live uit de stappenlijst. Mini-clusters (foto + controlevraag) nog niet als apart meta-mechanisme — elke vraag is nu één scherm.
- **Afhankelijkheden:** geen harde; combineert goed met BL-017 (minder vragen) en BL-016 (prefill).

### BL-023 — Eén tik minder per vraag: automatisch door na eenduidige keuze

- **Status:** done · **Prioriteit:** medium · **Datum:** 2026-07-18 · **Ref:** `docs/intake-engine.md`, `docs/functional-test-status.md`
- **Parallel:** band **J** (done) — na BL-021; vervolg is BL-022 → BL-025.
- **Doel:** de bestaande vraag-voor-vraag-flow (BL-018) één handeling per vraag lichter maken:
  - **Auto-doorgaan bij eenduidige keuzes:** `single_choice` en `boolean` saven al direct (`wire:model.live`), maar de aanvrager moet daarna alsnog "Volgende" tikken. Ga na de keuze automatisch door (met korte visuele bevestiging); "Vorige" blijft altijd werken om te corrigeren.
  - **Enter = Volgende** bij tekst-/nummervelden, zodat het toetsenbord niet dicht hoeft voor de knop.
  - **Niet** auto-doorgaan bij `multi_choice`, foto's en `long_text` — daar is de laatste invoer niet eenduidig "klaar".
- **Resultaat:** `IntakeWizard::maybeAutoAdvanceAfterChoice` na save van `.value`/`.bool` (alleen single_choice/boolean; niet op laatste stap); bevestiging via “Opgeslagen” op het volgende scherm; `advanceFromEnter` voor short_text/number (sync vóór `next` omdat `wire:model.blur` Enter niet meeneemt); Vorige ongewijzigd. Staging-smoketest als `todo`.
- **Waarom (hoofddoel):** airco v2/v3 telt per intake (1 unit) zo'n 17 `single_choice`/`boolean`-schermen; dat zijn nu ~17 "Volgende"-tikken die geen informatie toevoegen. Bij meerdere units loopt dat verder op.
- **Kaders:** conditionele vragen blijven live evalueren; auto-doorgaan mag een nét verschenen vervolgvraag nooit overslaan (bestaand `realignToActiveStep`-pad is het ankerpunt). Verplichte-veldcontrole van `next()` blijft ongewijzigd.
- **Afhankelijkheden:** geen harde; in band J na BL-021 plannen wegens gedeelde bestanden.

### BL-019 — Afleiden uit adres en openbare bronnen (satellietbeeld, BAG)

- **Status:** backlog · **Prioriteit:** medium
- **Parallel:** band **F** — parallel met A/D/E/G/H/I; gebruikt BL-016-kaders (voorzet, geen verborgen aanname).
- **Doel:** het adres is al bekend bij het aanmaken van de opname (`intakes.address_*`); gebruik dat om vragen te schrappen of te verifiëren i.p.v. ze te stellen:
  - **Satelliet-/luchtfoto** (bijv. Google Maps Static API of PDOK-luchtfoto) tonen in het installateursrapport en als context bij de buitenunit-/gevelvragen — kan `facade_overview_photo` deels vervangen of de aanvrager alleen om bevestiging vragen ("klopt dit beeld van uw woning?");
  - **BAG/open data:** bouwjaar (`build_year`) en gebouwtype (`building_type`) zijn vaak uit openbare registers af te leiden; toon als voorzet die de aanvrager alleen bevestigt (kader BL-016: prefill is een voorzet, geen verborgen aanname).
- **Kaders:** afgeleide waarden zijn deterministisch of door de aanvrager bevestigd; API-keys via `.env`, nooit in git; kosten/quota van externe API's afwegen (PDOK/BAG is gratis en Nederlands, Google Maps betaald). Privacy: adres alleen naar externe API sturen als daar een verwerkingsgrondslag voor is — meenemen in dezelfde DPIA-lijn als BL-006.
- **Afhankelijkheden:** geen harde; rapportintegratie kan los van de klantflow. Bij externe API's: DPIA-afweging (zie BL-006).

## Epic E4 — AI bespaart beoordeelwerk

AI mag nooit bron van waarheid zijn (docs/ai.md, ADR-0005), maar kan wél handelingen schrappen: de samenvatting bespaart de installateur leeswerk, aandachtspunten-voorstellen versnellen de beoordeling, en een fotokwaliteitscheck voorkomt dat de aanvrager later een tweede aanleverronde moet doen.

### BL-006 — Externe LLM-provider (na DPIA)

- **Status:** backlog · **Prioriteit:** medium · **Ref:** ADR-0005, `docs/ai.md`
- **Parallel:** band **H** (ketenkop) — parallel met A/D–G/I qua codepad; **geblokkeerd** tot DPIA/akkoord. Ontgrendelt daarna BL-007 + BL-020 parallel.
- **Doel:** OpenAI (of vergelijkbaar) client achter `AiClientInterface` naast null/fake/heuristic.
- **Blokkerend:** DPIA/akkoord en redactiestrategie voor persoonsgegevens — géén PII naar een provider vóór die er zijn.

### BL-007 — AI-uitbreidingen

- **Status:** backlog · **Prioriteit:** low · **Ref:** `docs/ai.md`
- **Parallel:** band **H** — **ná** BL-006, parallel met BL-020 (heuristic-prototype mag eerder).
- **Doel:** `SuggestAttentionPoints`, `AssessPhotoUsability`, en UI waarmee de installateur AI-voorstellen accepteert of verwijdert. AI blijft ondersteunend, nooit bron van waarheid; niets blokkeert de kernflow.
- **Waarom (hoofddoel):** `AssessPhotoUsability` geeft de aanvrager direct feedback ("foto te donker — maak er nog één") zolang die tóch al bezig is — dat is één handeling nu i.p.v. een hele extra ronde later.
- **Afhankelijkheden:** BL-006 voor zinvolle kwaliteit (heuristic kan als tussenstap).

### BL-020 — Foto-gedreven afleiding en adaptieve vervolgvragen

- **Status:** backlog · **Prioriteit:** medium
- **Parallel:** band **H** — **ná** BL-006, parallel met BL-007; template-kant mag aansluiten op afgeronde BL-016/v2/v3.
- **Doel:** foto's niet alleen opslaan maar er informatie uit **afleiden**, zodat vragen vervallen of juist gericht gesteld worden. Voorbeelden (richting, geen letterlijke scope):
  - **Meterkastfoto:** herken of er een vrije groep is; zit de kast vol → stel gericht de vervolgvragen die daarbij horen (uitbreiding groepenkast, 1-fase/3-fase) en sla `free_group_known` als vraag over;
  - **Ruimtefoto's:** schat afmetingen/volume van de kamer in → kamermaatvragen (BL-017) vervallen of worden een te bevestigen voorzet;
  - **Route-/gevelfoto's:** schat leidinglengte en boringen in als voorzet voor de installateur.
- **Kaders (ADR-0005, docs/ai.md):** AI-uitkomsten zijn altijd een **voorzet** — de aanvrager of installateur bevestigt; deterministische regels (`show`/`require`) blijven de enige poort voor verplichte velden. Een AI-afleiding mag een vraag *invullen als voorzet* of een *conditionele vervolgvraag activeren via een bevestigd antwoord*, maar nooit stil een verplicht veld wegnemen. Foto-analyse loopt async (ADR-0004) en mag de flow nooit blokkeren: geen of trage analyse = gewoon de vraag stellen.
- **Uitvoering (gefaseerd):** eerst de template-kant (vragen conditioneel maken op een bevestigbaar afleidingsantwoord, via BL-017-versie), dan `AssessPhoto*`-acties achter `AiClientInterface`, dan de klantflow-integratie ("wij zien op uw foto X — klopt dat?").
- **Afhankelijkheden:** BL-006 (externe multimodale LLM + DPIA) voor betrouwbare beeldherkenning; BL-007 legt de `AssessPhotoUsability`-basis; BL-017/BL-018 voor de template- en flowkant.

## Epic E5 — Bruikbaar dossier & klaar voor groei

Het hoofddoel eindigt bij een **bruikbaar dossier**: bruikbaar in de offerte-flow van de installateur (ook buiten de browser), te ervaren door prospects zonder accountsetup, en veilig te beheren en op te schalen zodra meer bedrijven meedoen.

### BL-005 — PDF-export van rapporten

- **Status:** done · **Prioriteit:** medium · **Datum:** 2026-07-18 · **PR:** #26
- **Doel:** naast het HTML-rapport (`generated_reports`) een PDF-pad, zodat het dossier direct in de offerte-/archiefflow van de installateur past zonder knip- en plakwerk. **Async** job (ADR-0004); HTML blijft bron.
- **Resultaat:** lichte Dompdf-export via `GenerateIntakePdfJob` na afronden; opslag op `MEDIA_DISK` (`pdf_disk`/`pdf_path`/`pdf_generated_at`); detailpagina **Download PDF** + opnieuw genereren; demo’s skippen PDF; hard purge ruimt PDF-bestanden op.

### BL-001 — Demo-versie van de app

- **Status:** in_progress · **Prioriteit:** medium *(#1 in de uitvoeringsvolgorde: restwerk is klein — staging-flag + smoke-test — en lopend werk afronden gaat vóór nieuw werk starten)* · **Ref:** [issue #5](https://github.com/JorisPaarde/intake-engine/issues/5)
- **Parallel:** band **A** (afronden) — restwerk is staging-config + smoke-test; parallel met code-sporen D–I.
- **Doel:** publiek of semi-publiek demopad zodat prospects/installateurs het product kunnen ervaren zonder eigen accountsetup of echte klantdata — het hoofddoel ("zo min mogelijk handelingen") toegepast op de allereerste kennismaking.
- **Invulling (deze PR):** homepage **"Start demo"** → tijdelijke airco-intake + klantlink (`is_demo`, TTL via `DEMO_TTL_HOURS`, watermerk, geen AI-job, hourly `intakes:purge-demos`). Geen account nodig; fictieve `@demo.invalid`-e-mail. Feature-flag `DEMO_ENABLED` (default uit; aanzetten op staging).
- **Nog te doen na deploy:** `DEMO_ENABLED=true` in staging `shared/.env` (zie [DEPLOYMENT § Handmatige acties](DEPLOYMENT.md#handmatige-acties-producteigenaar)), smoke-test Start demo → wizard → watermerk; daarna status → `done`.
- **Afhankelijkheden:** geen — klantflow (Fase 3), uploads (Fase 4) en rapport (Fase 5) zijn af.
- **Niet doen in demo:** echte mail naar willekeurige adressen, persistente PII van bezoekers zonder TTL.

### BL-009 — Purge-job voor soft-deleted intakes

- **Status:** done · **Prioriteit:** low · **Datum:** 2026-07-18 · **PR:** #26 · **Ref:** `docs/database.md` (bewaartermijn)
- **Doel:** bewaartermijn bekrachtigen en implementeren: 30 dagen na soft delete hard purge van dossier inclusief storage (foto's). Scheduled job + tests.
- **Resultaat:** daily `intakes:purge-deleted`; `INTAKE_SOFT_DELETE_RETENTION_DAYS` (default 30); `HardDeleteIntake` verwijdert uploads (incl. soft-deleted) + PDF + `forceDelete`. Soft-delete-UI voor intakes ontbreekt nog (purge is klaar voor wanneer die er is).

### BL-024 — Leesbaar dossier: vraaglabels i.p.v. keys in installateursweergave

- **Status:** done · **Prioriteit:** low · **Datum:** 2026-07-18 · **PR:** #28
- **Parallel:** band **K** (done) — raakte alleen installer-views + lichte presentatiebouwsteen.
- **Doel:** de foto-galerij op de intake-detailpagina toont als bijschrift nu de rauwe `question_key` en `section_instance_key` (bv. `room_photos · room-2`). Toon het vraaglabel uit de templateversie plus een leesbare instantienaam ("Foto's van de ruimte · Ruimte 2") en groepeer foto's per sectie/ruimte, zoals het HTML-rapport dat al doet.
- **Resultaat:** `InstallerPhotoGalleryBuilder` groepeert uploads per sectie/instantie (koppen zoals `Ruimtes 2`, zelfde patroon als de wizard) en toont vraaglabels uit de gepinde templateversie als bijschrift; geen datamodelwijziging.
- **Waarom (hoofddoel):** het dossier is pas bruikbaar als de installateur het zonder vertaalslag leest; nu decodeert hij bij elke beoordeling zelf keys naar betekenis — leeswerk dat het dossier zelf kan wegnemen.
- **Kaders:** labels komen uit de gepinde templateversie van de intake (geen hardcoded airco-teksten — de engine blijft data-gedreven); geen datamodelwijziging.
- **Afhankelijkheden:** geen — presentatie in `resources/views/installer/intakes/show.blade.php` + `InstallerPhotoGalleryBuilder`.

### BL-010 — Production-deployworkflow

- **Status:** backlog · **Prioriteit:** low *(verlaagd 2026-07-18 bij hoofddoel-herprioritering: bespaart nu geen handelingen; oppakken zodra een eerste echte klant/productiegang concreet is — zelfde trigger als BL-012. BL-009 purge is al done.)* · **Ref:** `docs/DEPLOYMENT.md`
- **Parallel:** band **I** — parallel met productwerk; afstemmen met BL-011/BL-013 bij gedeelde deploy-/hostingkeuzes.
- **Doel:** `deploy-production.yml` getriggerd op tags (`v*`), `PRODUCTION_*`-secrets, eigen `apps/intake-engine-production`-boom en database. Eerste release taggen als `v0.x` en CHANGELOG `[Unreleased]` afsluiten.

### BL-012 — Multi-tenancy (companies)

- **Status:** backlog · **Prioriteit:** low · **Ref:** ADR-0006
- **Parallel:** band **I** · later — niet parallel starten vóór concrete tweede klant; raakt breed (users/intakes).
- **Doel:** bewust afwezig in MVP. Pas oppakken bij een concrete tweede klant/bedrijf: `companies`-tabel + tenant-scope op intakes en users.

### BL-013 — S3 als mediadisk

- **Status:** backlog · **Prioriteit:** low · **Ref:** `docs/uploads.md`
- **Parallel:** band **I** — parallel met A/D–H; afstemmen met afgeronde BL-008 als dezelfde mediapipeline geraakt wordt.
- **Doel:** `MEDIA_DISK=s3` + AWS-vars; bestaande rijen behouden `disk`+`path`. Pas nodig bij storagegroei of vertrek van cPanel.

## Afgerond / vervallen

`done`- en `dropped`-items blijven in de overzichtstabel en detailsecties hierboven staan als geheugen (met datum + PR).

| ID | Afgerond | PR |
|----|----------|-----|
| BL-024 | 2026-07-18 | #28 — vraaglabels + groepering foto-galerij installateur |
| BL-014 | 2026-07-18 | #26 — afrondingsmail + dashboard “Nieuw afgerond” |
| BL-015 | 2026-07-18 | #26 — `intakes:send-reminders` + `reminder_sent_at` |
| BL-005 | 2026-07-18 | #26 — Dompdf PDF-export + download |
| BL-009 | 2026-07-18 | #26 — `intakes:purge-deleted` + `HardDeleteIntake` |
| BL-004 | 2026-07-18 | #25 — klantlink-mail + Opnieuw mailen; SMTP op staging nog te zetten |
| BL-008 | 2026-07-18 | #24 — HEIC/HEIF → JPEG (Imagick) |
| BL-016 | 2026-07-18 | #22 — prefill (installateur + repeatable), airco v3 |
| BL-002 | 2026-07-18 | #14 (fixes) + hertest na deploy |
| BL-017 | 2026-07-18 | #21 |
| BL-018 | 2026-07-18 | #18 |
| BL-003 | 2026-07-18 | #12 (+ staging-verificatie via `/health`, docs #13) |
