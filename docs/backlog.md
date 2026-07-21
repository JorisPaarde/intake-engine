# Backlog — Digitale Opname

> **Documentversie:** 3.31 · **Laatste update:** 2026-07-21 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

De **enige backlog** van dit project: al het werk dat bewust niet in de afgeronde MVP-fasen 1–6 zit (zie `docs/implementation-plan.md`), plus nieuw ontdekt werk. Proces en statusregels: zie [AGENTS.md § Backlogproces](../AGENTS.md#backlogproces).

De items zijn gegroepeerd in **vijf epics**. Elke epic is herleid naar het vaste [hoofddoel](../AGENTS.md#hoofddoel-vast--niet-door-agents-aan-te-passen) (*met zo min mogelijk handelingen van aanvraag naar een bruikbaar dossier; voor iedere ontbrekende informatie de eenvoudigste manier van aanleveren*) en het vaste [ontwerpprincipe](../AGENTS.md#ontwerpprincipe-vast--niet-door-agents-aan-te-passen) (*niets vragen wat al bekend is of eenvoudiger kan worden vastgesteld; per ontbrekend gegeven de snelste en duidelijkste verzamelmethode*).

Status: `backlog` · `ready` · `in_progress` · `done` · `dropped` — prioriteit: `high` · `medium` · `low`

**Leeswijzer:** scan de epictabel en de overzichtstabel hieronder; open daarna alleen de detailsectie van het item waaraan je werkt.

## Epics

| Epic | Naam | Koppeling met hoofddoel / ontwerpprincipe |
|------|------|-------------------------------------------|
| E1 | Frictieloze basisflow | Elke haperende stap (mislukte foto-upload, SSL-waarschuwing, onbevestigde werking) kost de aanvrager extra handelingen of breekt het pad naar een bruikbaar dossier; kernmetrics moeten die frictie zichtbaar maken. |
| E2 | Communicatie zonder handwerk | Link doorsturen, afronding signaleren, nabellen en ongestructureerd om extra informatie vragen zijn handmatige installateurshandelingen; die moeten verdwijnen. |
| E3 | Vraag minder, verzamel slimmer | Directe toepassing van het ontwerpprincipe op de intake zelf: hergebruik wat bekend is, kies per gegeven de snelste en duidelijkste verzamelmethode. |
| E4 | AI bespaart beoordeelwerk | Samenvatting, aandachtspunten en fotokwaliteitscheck besparen de installateur leeswerk en de aanvrager een extra aanleverronde. AI blijft ondersteunend (docs/ai.md). |
| E5 | Bruikbaar dossier & klaar voor groei | Het dossier moet buiten de browser bruikbaar zijn en het product moet zonder extra handelingen te ervaren, beheren en opschalen zijn. |

Volgorde-advies: volg kolom **#** in de overzichtstabel hieronder. De rode draad: demo/staging afronden (BL-001) en daarna groei-/beheeritems; het eigen domein en geldige HTTPS zijn met BL-011 afgerond. Externe activering van de code-complete foto-afleiding blijft een DPIA/env-actie. Parallelisatie: zie [§ Parallelisatie](#parallelisatie) en kolom **Band**.

## Parallelisatie

Items in **verschillende parallel-bands** kunnen tegelijk door aparte agents/mensen worden gebouwd (andere codepaden of externe acties). Items in dezelfde **keten** zijn sequentieel. Kolom **Band** in de overzichtstabel verwijst hiernaar. (Bands van afgeronde items blijven in de tabel als geheugen.)

| Band | Type | Items (open) | Mag parallel met |
|------|------|--------------|------------------|
| **A** | Afronden (lopend) | BL-001 | D–I (staging-config/smoke; weinig codeconflict) |
| **D** | Infra (extern) | — (BL-011 done) | — |
| **F** | Open data / adres | — (BL-019 done) | — |
| **H** | AI-keten | — (BL-006/007/020 done) | — |
| **I** | Beheer / schaal | BL-010, BL-013 (BL-012 later) | Onderling parallel; met A–H zolang geen gedeelde deploy-/storage-wijziging botst |
| **J** | Klantwizard-verbeteringen | — (BL-021–BL-025 done) | — |
| **K** | Installateursweergave | — (BL-024 done) | — |
| **L** | Gerichte vervolgflow | — (BL-027 done) | — |
| **M** | Productmetrics | — (BL-026 done) | — |

Afgeronde bands (niet meer te plannen): **B** = BL-016 (prefill), **C** = BL-008 (HEIC), **E** = BL-004/BL-014/BL-015 (mail-keten), **G** = BL-005 (PDF), **H** = BL-006/BL-007/BL-020 (AI-keten), **K** = BL-024 (installateursgalerij); band J af: BL-021/BL-022/BL-023/BL-025 (wizard-keten) done; BL-009 purge done.

**Concrete parallel-startsets:**

1. **Nu parallel uitvoerbaar:** BL-001 afronden; SMTP/PDOK op staging aanzetten voor smoketests (BL-004/014/015/019/027).
2. **Na DPIA parallel activeren:** externe AI + foto-inferentie via staging-env en smoketest; geen resterend code-item.
3. **Laag-prioriteit parallel:** BL-010 · BL-013 · (BL-012 bij tweede klant).

## Overzicht

Geprioriteerd op het hoofddoel (herprioritering 2026-07-18): hoeveel handelingen bespaart of repareert een item in de kernflow van aanvrager en installateur, hoe direct, en voor hoeveel intakes? Kolom **#** is de aanbevolen uitvoeringsvolgorde (afhankelijkheden meegewogen); kolom **Band** = parallelgroep (zie [§ Parallelisatie](#parallelisatie)); `done`/`dropped` staan onderaan zonder volgnummer.

| # | ID | Item | Epic | Status | Prioriteit | Band |
|---|----|------|------|--------|------------|------|
| 1 | BL-001 | Demo-versie van de app | E5 | in_progress | medium | A |
| 2 | BL-010 | Production-deployworkflow (tags + eigen omgeving) | E5 | backlog | low | I · parallel |
| 3 | BL-012 | Multi-tenancy (companies) | E5 | backlog | low | I · later |
| 4 | BL-013 | S3 als mediadisk | E5 | backlog | low | I · parallel |
| — | BL-020 | Foto-gedreven afleiding en adaptieve vervolgvragen | E4 | done | medium | H (done) |
| — | BL-019 | Afleiden uit adres en openbare bronnen (luchtfoto, BAG) | E3 | done | medium | F (done) |
| — | BL-026 | Kernmetrics voor frictie en dossierbruikbaarheid | E1 | done | medium | M (done) |
| — | BL-027 | Gerichte aanvullende-informatieronde na beoordeling | E2 | done | high | L (done) |
| — | BL-025 | Wizard-responstijd: dubbele queries per Livewire-request terugdringen | E1 | done | low | J (done) |
| — | BL-006 | Externe LLM-provider (clientlaag; activering na DPIA + key) | E4 | done | medium | H (done) |
| — | BL-007 | AI-uitbreidingen: attention points, fotokwaliteit, accepteren/verwijderen | E4 | done | low | H (done) |
| — | BL-022 | Voortgang en "ontbreekt nog" kloppend en klikbaar maken | E1 | done | medium | J (done) |
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
| — | BL-011 | Eigen domein + geldig SSL voor staging | E1 | done | high | D (done) |
| — | BL-017 | Airco-template v2: vraag-voor-vraag audit op het ontwerpprincipe | E3 | done | high | — |
| — | BL-018 | Vraag-voor-vraag klantflow (één vraag per scherm) | E3 | done | high | — |
| — | BL-003 | Staging PHP-uploadlimieten verifiëren/verhogen | E1 | done | high | — |

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

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-21 · **Ref:** `README.md`, `docs/DEPLOYMENT.md`
- **Parallel:** band **D** (done).
- **Doel:** het tijdelijke `.cpanel.site`-domein (self-signed, "Technical Domain"-tussenscherm) vervangen door een eigen (sub)domein met Let's Encrypt. Daarna README-omgevingstabel bijwerken.
- **Waarom:** het tussenscherm en de browserwaarschuwing zijn twee extra handelingen (en een vertrouwensbreuk) vóór de aanvrager ook maar één vraag heeft gezien.
- **Resultaat:** `https://intake-engine.nl/` is gekoppeld aan de publieke cPanel-omgeving en antwoordt via geldig HTTPS zonder Technical Domain-tussenscherm. README, deploymentdocumentatie en beide server-env-sjablonen gebruiken de nieuwe canonical URL. De aparte productie-deployworkflow blijft bewust BL-010.

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

- **Status:** done · **Prioriteit:** medium · **Datum:** 2026-07-18 · **PR:** #31 · **Ref:** `docs/intake-engine.md`, `docs/functional-test-status.md`
- **Parallel:** band **J** (done) — na BL-023; vervolg is BL-025.
- **Doel:** drie verbeteringen op de bestaande voortgangs- en compleetheidsweergave:
  - **Percentage dat klopt met "klaar":** `ProgressCalculator` telt ook optionele onbeantwoorde vragen mee, waardoor een intake die klaar is om af te ronden op bv. 98% blijft hangen. Baseer het getoonde percentage op verplichte zichtbare vragen (of toon verplicht/optioneel gescheiden) zodat 100% = afronden kan.
  - **Ontbrekende vragen klikbaar:** de lijst "Nog niet alles is ingevuld" toont nu alleen labels; laat elk item naar de betreffende stap springen (`goToStep` bestaat al) in plaats van de aanvrager te laten terugbladeren.
  - **Leesbare instantienamen:** toon "Ruimte 2" in plaats van de rauwe `section_instance_key` (`room-2`) in de ontbrekend-lijst.
- **Resultaat:** `ProgressCalculator` baseert `%` alleen op verplichte zichtbare vragen (100% wanneer `CompletenessChecker` compleet is, optionele leeg mag); ontbrekende items hebben `instance_label` (zelfde patroon als wizard: “Ruimtes 2”); klikbare knoppen via `IntakeWizard::goToMissing`. Staging-smoketest als `todo`.
- **Waarom (hoofddoel):** de laatste meters vóór afronden kosten nu zoekwerk: een misleidend percentage en een niet-navigeerbare foutlijst zijn extra handelingen op het moment dat de aanvrager al bijna klaar was — precies waar afhakers vallen.
- **Kaders:** `CompletenessChecker` blijft de enige poort voor afronden; dit is presentatie/navigatie, geen wijziging van compleetheidsregels.
- **Afhankelijkheden:** geen harde; na BL-023 (done) in band J plannen wegens gedeelde bestanden.

### BL-025 — Wizard-responstijd: dubbele queries per Livewire-request terugdringen

- **Status:** done · **Prioriteit:** low · **Datum:** 2026-07-18 *(verbeterronde 2026-07-18)*
- **Parallel:** band **J** (done) — raakt `IntakeWizard` (puur intern).
- **Doel:** `IntakeWizard` haalt per Livewire-request meerdere keren dezelfde data op: `intake()` doet telkens een verse `findOrFail` en `version()` laadt telkens de volledige sections/questions/options/rules-graaf, terwijl `steps()`, `render()`, `currentStep()` en de visibility-checks elkaar per request herhaaldelijk aanroepen. Memoizeer per request (met bewuste invalidatie na saves) en meet de responstijd van autosave/"Volgende" vóór en na.
- **Resultaat:** request-lokale memoization van `intake()` / `version()` / `steps()` in `IntakeWizard`, met invalidatie (`forgetIntakeDerivedCaches()`) na antwoord-saves en uploads. Gedrag ongewijzigd; bestaande featuretests groen.
- **Waarom (hoofddoel):** elke vraag is een server-roundtrip (autosave + stapnavigatie); onnodig trage responses voelen op mobiel als wachten per vraag — frictie op precies het pad dat we het lichtst willen maken.
- **Kaders:** gedrag ongewijzigd (pure performance); let op Livewire-hydration en stale state na `SaveIntakeAnswer`/uploads; bestaande featuretests blijven de poort.
- **Afhankelijkheden:** geen harde; na de andere band-J-items zodat er niet in hetzelfde bestand geparallelliseerd wordt.

### BL-026 — Kernmetrics voor frictie en dossierbruikbaarheid

- **Status:** done · **Prioriteit:** medium · **Datum:** 2026-07-20 · **Ref:** `docs/metrics.md`
- **Parallel:** band **M** — parallel met productwerk; raakt vooral privacyveilige events en een interne meetweergave.
- **Doel:** meet per intake de uitkomsten waarop het product wordt gestuurd: afrondingspercentage, doorlooptijd, aantal klantacties, uitvalpunt, aantal aanvullende contact-/informatierondes, oordeel `enough_info`, en tijd van aanvraag tot installateursbesluit.
- **Waarom:** zonder deze metingen is niet aantoonbaar of een wijziging werkelijk minder werk oplevert. De bestaande activity events dekken losse gebeurtenissen, maar nog geen samenhangende funnel of beslissnelheid.
- **Kaders:** geen tokens, vrije klanttekst of foto-inhoud in analytics; gebruik bestaande identifiers/timestamps en expliciete gebeurtenistypen; definieer elke metric in documentatie zodat cijfers reproduceerbaar blijven; interne toegang voor installateurs/beheerders.
- **Acceptatie:** een testbare metrics-service levert de definities per intake en geaggregeerd; een interne weergave toont ten minste completion, mediane doorlooptijd, acties, aanvullende rondes, `enough_info` en beslissnelheid; nulmeting en staging-smoke zijn vastgelegd.
- **Afhankelijkheden:** BL-027 levert het expliciete aantal aanvullende informatierondes; zonder dat item mag de metric als `0/onbekend` worden weergegeven.
- **Resultaat:** `IntakeMetricsService` leidt zonder extra analytics-opslag per intake en geaggregeerd completion, mediane klanttijd/-acties, uitvalpunt, rondes, `enough_information` bij de **eerste** beoordeling en tijd tot eerste beoordeling af. `/metrics` is auth+verified, filtert 30/90/alles, sluit demo's uit en toont geen PII/vrije tekst/tokens. `answer_saved` registreert vanaf nu alleen de veilige vraag-/instantiekey; historische intakes gebruiken een antwoordrecord-fallback. Exacte definities, lokale nulmeting en staging-smokechecklist staan in `docs/metrics.md`; staging-smoke blijft `todo` tot deploy.

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

### BL-027 — Gerichte aanvullende-informatieronde na beoordeling

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-20
- **Parallel:** band **L** (done).
- **Doel:** als het dossier nog niet genoeg informatie bevat, formuleert de installateur één of meer concrete vervolgvragen, foto- of documentopdrachten, verstuurt het systeem die in één klantvriendelijke ronde, en opent de klant alleen de ontbrekende stappen. Na aanvullen wordt hetzelfde dossier opnieuw ter beoordeling aangeboden.
- **Waarom:** `need_more_info` registreert nu alleen een besluit. De installateur moet daarna buiten het systeem achterhalen wat ontbreekt, contact opnemen en losse antwoorden terugplaatsen; dat is precies het nawerk dat het productdoel wil verwijderen.
- **Kaders:** installateur blijft beslisser; vragen zijn expliciet en bewerkbaar; bestaande klanttoken en privacyregels hergebruiken; geen volledige intake opnieuw doorlopen; elke ronde en doorlooptijd wordt als privacyveilig activity event vastgelegd voor BL-026.
- **Acceptatie:** installateur kan gerichte vervolgitems toevoegen en versturen; klant ziet uitsluitend die items, kan tekst/foto's/documenten aanleveren en afronden; status en notificatie doorlopen opnieuw de reviewketen; rapport behoudt eerdere antwoorden en markeert de nieuwe bron/ronde; featuretests dekken tekst, foto, PDF, verlopen token en maximaal toegestane rondes.
- **Afhankelijkheden:** SMTP voor echte bezorging; de flow moet ook met de bestaande kopieerbare link bruikbaar blijven.
- **Resultaat:** `need_more_info` vereist 1–5 concrete tekst-, foto- of PDF-documentitems en zet de intake op `awaiting_customer`; dezelfde geldige token opent een aparte vervolgmodus met alleen die items. Tekst autosavet; foto's gebruiken dezelfde normalisatie/private storage; PDF's worden op server-MIME én `%PDF-`-signatuur gecontroleerd en eveneens privé bewaard. Na complete aanvulling wordt ronde + privacyveilig event vastgelegd, rapport/PDF herbouwd en de intake opnieuw `completed` met installateursnotificatie. Installateurdetail toont alle rondes, antwoorden, foto's en documenten. Standaard max. 3 rondes, 5 foto's of 3 documenten per item; SMTP blijft fail-soft met de kopieerbare klantlink als fallback. Featuretests dekken tekst, foto, PDF, ongeldige documentinhoud, mail, rapport, gesloten token na afronding, verlopen token en rondelimiet; staging-smoke staat als `todo`.

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

- **Status:** done · **Prioriteit:** medium · **Datum:** 2026-07-18 · **PR:** #30 · **Ref:** `docs/intake-engine.md`, `docs/functional-test-status.md`
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

- **Status:** done *(code; staging/privacy-gate open)* · **Prioriteit:** medium · **Datum:** 2026-07-20 · **Ref:** ADR-0007, `docs/intake-engine.md`, `docs/database.md`
- **Parallel:** band **F** — parallel met A/D/E/G/H/I; gebruikt BL-016-kaders (voorzet, geen verborgen aanname).
- **Doel:** het adres is al bekend bij het aanmaken van de opname (`intakes.address_*`); gebruik dat om vragen te schrappen of te verifiëren i.p.v. ze te stellen:
  - **Satelliet-/luchtfoto** (bijv. Google Maps Static API of PDOK-luchtfoto) tonen in het installateursrapport en als context bij de buitenunit-/gevelvragen — kan `facade_overview_photo` deels vervangen of de aanvrager alleen om bevestiging vragen ("klopt dit beeld van uw woning?");
  - **BAG/open data:** bouwjaar (`build_year`) en gebouwtype (`building_type`) zijn vaak uit openbare registers af te leiden; toon als voorzet die de aanvrager alleen bevestigt (kader BL-016: prefill is een voorzet, geen verborgen aanname).
- **Kaders:** afgeleide waarden zijn deterministisch of door de aanvrager bevestigd; API-keys via `.env`, nooit in git; kosten/quota van externe API's afwegen (PDOK/BAG is gratis en Nederlands, Google Maps betaald). Privacy: adres alleen naar externe API sturen als daar een verwerkingsgrondslag voor is — meenemen in dezelfde DPIA-lijn als BL-006.
- **Resultaat:** authenticated adres-autocomplete via PDOK Locatieserver vult straat, postcode en plaats in één selectie. Na aanmaken haalt een fail-soft verrijkingsactie BAG-verblijfsobject/pand op en bewaart bouwjaar, gebruiksdoel, gebruiksoppervlakte, coördinaten en perceelreferentie met bron/zekerheid. Airco **v4** slaat `build_year` alleen over bij een eenduidig BAG-antwoord. Bij coördinaten haalt de server ook `Actueel_orthoHR` via PDOK WMS op als gevalideerde private JPEG; installateursdetail, HTML en PDF tonen die met centrumstip, schaalcontext, bron en onzekerheid. WMS-falen laat BAG intact; purge verwijdert media. De optionele gevelfoto vervalt bewust niet: bovenaanzicht bewijst gevel, route, obstakels en montageplek niet.
- **Resterende gate (niet-code):** staging-smoke + privacy/grondslag formeel accorderen vóór echte klantdata; zo nodig `PDOK_ENABLED=false` of alleen `PDOK_AERIAL_ENABLED=false`.
- **Afhankelijkheden:** geen harde; rapportintegratie kan los van de klantflow. Bij externe API's: DPIA-afweging (zie BL-006).

## Epic E4 — AI bespaart beoordeelwerk

AI mag nooit bron van waarheid zijn (docs/ai.md, ADR-0005), maar kan wél handelingen schrappen: de samenvatting bespaart de installateur leeswerk, aandachtspunten-voorstellen versnellen de beoordeling, en een fotokwaliteitscheck voorkomt dat de aanvrager later een tweede aanleverronde moet doen.

### BL-006 — Externe LLM-provider (na DPIA)

- **Status:** done *(clientlaag; activering geblokkeerd op DPIA + key)* · **Prioriteit:** medium · **Datum:** 2026-07-18 · **Ref:** ADR-0005, `docs/ai.md`
- **Doel:** OpenAI (of vergelijkbaar) client achter `AiClientInterface` naast null/fake/heuristic.
- **Resultaat:** `OpenAiClient` (OpenAI-compatibel, Laravel `Http`, JSON-mode) achter `AiClientInterface`; provider-keuze op `AI_PROVIDER`; `AiInputRedactor` verwijdert e-mail/telefoon vóór verzending; config `AI_BASE_URL`/`AI_MODEL`/`AI_API_KEY`/`AI_TIMEOUT_SECONDS`. Standaard `null`; getest met `Http::fake()`.
- **Resterende gate (niet-code):** DPIA/akkoord + key in `.env` door producteigenaar. Géén echte PII naar de provider vóór die er zijn.

### BL-007 — AI-uitbreidingen

- **Status:** done · **Prioriteit:** low · **Datum:** 2026-07-18 · **Ref:** [ai.md § Aandachtspunten](../docs/ai.md#aandachtspunten-voorstellen-bl-007) + § Fotokwaliteit
- **Doel:** `SuggestAttentionPoints`, `AssessPhotoUsability`, en UI waarmee de installateur AI-voorstellen accepteert of verwijdert. AI blijft ondersteunend, nooit bron van waarheid; niets blokkeert de kernflow.
- **Resultaat:** `SuggestAttentionPoints` (heuristisch, mirror van `SummarizeIntake`) → aandachtspunten met `source=ai`/`status=proposed`; installateur accepteert (→ in rapport) of verwijdert; idempotent, soft-fail. `AssessPhotoUsability` (lokaal, GD) → niet-blokkerende "foto te donker/klein"-hint voor de klant + kwaliteitslabel voor de installateur (`intake_uploads.usability_verdict`). Werkt met `heuristic` (of straks `openai`); provider `null` = geen voorstellen.
- **Waarom (hoofddoel):** `AssessPhotoUsability` geeft de aanvrager direct feedback zolang die tóch al bezig is — één handeling nu i.p.v. een extra ronde later.

### BL-020 — Foto-gedreven afleiding en adaptieve vervolgvragen

- **Status:** done · **Prioriteit:** medium · **Datum:** 2026-07-20 · **PR:** (deze PR)
- **Parallel:** band **H** — **ná** BL-006, parallel met BL-007; template-kant mag aansluiten op afgeronde BL-016/v2/v3.
- **Doel:** foto's niet alleen opslaan maar er informatie uit **afleiden**, zodat vragen vervallen of juist gericht gesteld worden. Voorbeelden (richting, geen letterlijke scope):
  - **Meterkastfoto:** herken of er een vrije groep is; zit de kast vol → stel gericht de vervolgvragen die daarbij horen (uitbreiding groepenkast, 1-fase/3-fase) en sla `free_group_known` als vraag over;
  - **Ruimtefoto's:** schat afmetingen/volume van de kamer in → kamermaatvragen (BL-017) vervallen of worden een te bevestigen voorzet;
  - **Route-/gevelfoto's:** schat leidinglengte en boringen in als voorzet voor de installateur.
- **Kaders (ADR-0005, docs/ai.md):** AI-uitkomsten zijn altijd een **voorzet** — de aanvrager of installateur bevestigt; deterministische regels (`show`/`require`) blijven de enige poort voor verplichte velden. Een AI-afleiding mag een vraag *invullen als voorzet* of een *conditionele vervolgvraag activeren via een bevestigd antwoord*, maar nooit stil een verplicht veld wegnemen. Foto-analyse loopt async (ADR-0004) en mag de flow nooit blokkeren: geen of trage analyse = gewoon de vraag stellen.
- **Uitvoering (gefaseerd):** eerst de template-kant (vragen conditioneel maken op een bevestigbaar afleidingsantwoord, via BL-017-versie), dan `AssessPhoto*`-acties achter `AiClientInterface`, dan de klantflow-integratie ("wij zien op uw foto X — klopt dat?").
- **Afhankelijkheden:** BL-006-clientlaag is er (activering wacht op DPIA + key); een **multimodale** LLM productief is nog nodig voor betrouwbare beeldherkenning. BL-007 legde de `AssessPhotoUsability`-basis (done); BL-017/BL-018 voor de template- en flowkant.
- **Resultaat:** airco v5 markeert `fusebox_photo` voor multimodale beoordeling. `AssessFuseboxPhotos` verstuurt na expliciete privacyflag maximaal twee private meterkastfoto's via de bestaande providerinterface, valideert een beperkte vrije-groep-/fase-uitkomst en vult alleen een hoge-zekerheidswaarde als zichtbare, door de klant te bevestigen voorzet in. Onzekere output levert een concrete herhaalfoto-instructie; klantantwoorden worden nooit overschreven. Dossier/HTML/PDF tonen het afgeleide feit met provider, runreferentie, bron en verplichte installateurscontrole. Dezelfde afbeeldingshash is idempotent; verwijderen van bewijs wist afleiding. OpenAI-beeldinput gebruikt data-URL's alleen in transit, nooit in DB/logs. Runtime blijft standaard uit; DPIA, key, env-activatie en staging-smoke staan in deployment/teststatus.

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
- **Parallel:** band **I** — parallel met productwerk; afstemmen met BL-013 bij gedeelde deploy-/hostingkeuzes.
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
| BL-020 | 2026-07-20 | (deze PR) — bevestigbare meterkastfoto-afleiding + airco v5; externe activering na DPIA |
| BL-025 | 2026-07-18 | #34 — wizard request-caching (herstel van gesloten #32) |
| BL-007 | 2026-07-18 | (deze PR) — heuristische aandachtspunten + accept/verwijder + fotokwaliteit |
| BL-006 | 2026-07-18 | (deze PR) — `OpenAiClient` + redactie achter `AiClientInterface` (activering na DPIA + key) |
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
