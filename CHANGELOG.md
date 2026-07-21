# Changelog

Alle noemenswaardige wijzigingen aan dit project. Bijhouden is verplicht per PR — zie [AGENTS.md](AGENTS.md).

## [Unreleased]

### Changed

- BL-001: publieke demo staat standaard **aan** (`DEMO_ENABLED` default `true` in alle env-sjablonen). Bezoekers zien **Start demo** zonder handmatige staging-flag; `DEMO_ENABLED=false` blijft de opt-out bij misbruik. Bestaande `shared/.env` met expliciet `false` moet die regel verwijderen of op `true` zetten. Ingelogde gebruikers zien de demoknop niet (wel **Open dashboard**). In de demo (banner + bedankt-scherm) staat expliciet welke volledige-app-stappen uitstaan: e-mail, AI, PDF, installateursdashboard/aanvulling.

### Added

- Het HTML/PDF-dossier bevat nu de werkelijk aangeleverde intake- en vervolgfotografie plus gerichte PDF-documenten, gegroepeerd met vraaglabel, originele bestandsnaam, klantbron en aanvullingsronde. De beveiligde HTML-preview gebruikt geautoriseerde private-media-routes; alleen tijdens PDF-rendering worden beelden in-memory als data-URI ingebed, zodat bestandsbytes niet in `generated_reports.html` worden verdubbeld.
- Een korte, deterministische dossiersamenvatting staat nu altijd op de installateurspagina en in HTML/PDF. De samenvatting gebruikt alleen bekende antwoorden en labels uit de gepinde templateversie en blijft beschikbaar als de optionele AI-provider uitstaat of faalt.
- BL-020 foto-afleiding: airco **v5** markeert de meterkastfoto voor multimodale beoordeling. `AssessFuseboxPhotos` gebruikt maximaal twee private beelden via `AiClientInterface`/`OpenAiClient`, valideert een beperkte vrije-groep-/fase-inschatting en vult alleen bij hoge zekerheid `free_group_known` als zichtbare `prefill_source=ai`-voorzet in; klantantwoord wint altijd. Onzeker beeld levert een concrete herhaalfoto-instructie. Dossier/HTML/PDF tonen bron, provider/model, bewijs en verplichte controle; gelijke beeldhash is idempotent en verwijderen van bewijs wist de afleiding. Beeldbytes/data-URL's worden niet opgeslagen of gelogd. `AI_PHOTO_INFERENCE_ENABLED=false` blijft de default tot DPIA/key/staging-smoke.
- BL-026 productmetrics: authenticated `/metrics` met cohorten van 30/90 dagen of alles, aggregate en per-opname cijfers voor afronding, mediane invultijd/klantacties, uitvalpunt, aanvullende rondes, `enough_information` en tijd tot eerste installateursbesluit. Nieuwe privacyveilige `answer_saved`-events bevatten alleen vraag-/instantiekeys; oudere opnames gebruiken een antwoordrecord-fallback. Definities en nulmeting staan in `docs/metrics.md`.
- BL-027 gerichte aanvullende informatieronde: `need_more_info` vereist concrete tekst-, foto- of PDF-documentopdrachten, opent dezelfde klantlink alleen voor die items en brengt het aangevulde dossier daarna opnieuw naar `completed` voor beoordeling. Genummerde rondes bewaren antwoorden, foto's, documenten en herkomst in rapport/PDF en installateursdetail; PDF's worden op server-MIME én bestandssignatuur gecontroleerd en privé aangeboden. Klant- en installateursmails blijven fail-soft; privacyveilige events maken ronde/aantal/type meetbaar; standaard maximaal 3 rondes, 5 items, 5 foto's of 3 documenten per item.
- BL-019 adres/open-data: authenticated PDOK-adresautocomplete vult straat/postcode/plaats met één selectie; fail-soft BAG-verrijking bewaart gebouwjaar, gebruiksdoel, gebruiksoppervlakte, locatie en perceel als `intake_external_facts` met bron, referentie, zekerheid en tijdstip (ADR-0007). Airco v4 slaat de bouwjaarvraag over bij een eenduidig BAG-resultaat. De server haalt daarnaast de actuele `Actueel_orthoHR`-luchtfoto op rond de BAG-locatie, valideert en bewaart die privé, markeert het adrescentrum en embedt het beeld met bron/waarschuwing in installateursdetail, HTML en PDF. WMS-falen laat BAG-resultaten intact; hard purge verwijdert ook de luchtfoto. De optionele gevelfoto blijft bestaan omdat bovenaanzicht gevel/route/obstakels niet bewijst.
- BL-007 AI-uitbreidingen (ondersteunend, nooit bron van waarheid — ADR-0005): `SuggestAttentionPoints` (heuristisch, mirror van `SummarizeIntake`) stelt aandachtspunten voor (`intake_attention_points.status` = `proposed`/`accepted`/`dismissed`, bron `ai`); de installateur accepteert (→ in rapport) of verwijdert ze op de opnamepagina, idempotent en soft-fail; async na afronding + on-demand knop. `AssessPhotoUsability` beoordeelt elke foto **lokaal met GD** (`intake_uploads.usability_verdict`) → niet-blokkerende "te donker/te klein"-hint voor de klant + kwaliteitslabel voor de installateur.
- BL-006 externe LLM-provider: `OpenAiClient` (OpenAI-compatibel, Laravel `Http`, JSON-mode) achter `AiClientInterface`, gekozen via `AI_PROVIDER=openai`; `AiInputRedactor` verwijdert e-mail/telefoon vóór verzending; config `AI_BASE_URL`/`AI_MODEL`. **Standaard uit**; activering vereist key in `.env` + DPIA/akkoord. Getest met `Http::fake()` (geen echte calls).
- BL-025 wizard-perf: request-lokale memoization van `intake()` / `version()` / `steps()` in `IntakeWizard`, met invalidatie na saves/uploads (gedrag ongewijzigd).
- BL-022 voortgang en ontbreekt-lijst: percentage alleen over verplichte zichtbare vragen (100% ≈ afronden kan); ontbrekende items klikbaar (`goToMissing`) met leesbare instantielabels (“Ruimtes 2”).
- BL-023 wizard-navigatie: na `single_choice`/`boolean` automatisch door naar de volgende vraag (bevestiging “Opgeslagen”); Enter op `short_text`/`number` = Volgende; geen auto-doorgaan bij multi_choice/foto/long_text of op de laatste stap; Vorige blijft werken.
- BL-021 foto-upload in de klantwizard: `multiple` selectie (tot `meta.max_files`), geen `capture`-force zodat camera én galerij open blijven, en per-bestand upload zodat één mislukte foto de rest van de selectie niet blokkeert.
- BL-024 leesbare installateursgalerij: opname-detail groepeert foto’s per sectie/instantie (`InstallerPhotoGalleryBuilder`) en toont vraaglabels uit de gepinde templateversie i.p.v. rauwe `question_key` / `section_instance_key`.
- BL-014 afrondingsnotificatie: na afronden mailt `SendInstallerIntakeCompleted` de installateur (soft-fail / skip bij `MAIL_MAILER=log`); dashboard markeert en sorteert **Nieuw afgerond** (completed + nog niet beoordeeld).
- BL-015 herinnering stilliggende intake: daily `intakes:send-reminders` stuurt max. één herinneringsmail met hervat-link na `INTAKE_REMINDER_DAYS` (default 3); kolom `reminder_sent_at`; stopt bij demo/ingetrokken/verlopen/afgerond; geen tokens in logs (ADR-0002).
- BL-009 hard purge soft-deleted intakes: daily `intakes:purge-deleted` na `INTAKE_SOFT_DELETE_RETENTION_DAYS` (default 30) verwijdert dossier + foto’s + PDF via `HardDeleteIntake` (ook hergebruikt door demo-purge).
- BL-005 PDF-export: async `GenerateIntakePdfJob` (Dompdf) na afronden; kolommen `pdf_disk`/`pdf_path`/`pdf_generated_at` op `generated_reports`; download + opnieuw genereren op de detailpagina; HTML blijft bron.
- BL-004 automatische klantlink-mail: na aanmaken (en na token-hergenereren) stuurt `SendCustomerIntakeLink` een Nederlandse mailable; detailpagina heeft **Opnieuw mailen**. Kopieerbare link blijft fallback. Bij `MAIL_MAILER=log` wordt mail overgeslagen (geen tokens in logs, ADR-0002); soft-fail bij SMTP-fouten; demo-intakes mailen nooit. Activity-event `customer_link_mailed` zonder token/URL.
- BL-008 HEIC/HEIF-ondersteuning bij foto-uploads: server-side MIME-detectie met ISO BMFF-brand-sniffing, automatische Imagick-conversie naar JPEG (auto-orient, metadata strippen, resize/kwaliteit binnen uploadlimiet), opslagmetadata op het genormaliseerde bestand en previews via de bestaande routes. CI voegt `imagick` toe; tests gebruiken `tests/Fixtures/sample.heic`.
- BL-016 prefill van bekende gegevens (deterministisch, altijd als bewerkbare voorzet — geen LLM). Twee bronnen via vraag-`meta`: `installer_prefillable` (installateur vult `request`-vragen alvast in bij het aanmaken → `intake_answers.prefill_source='installer'`, in de wizard getoond als "alvast ingevuld — controleer") en `prefill_from_previous` (ruimte 2..n neemt `floor_level` over van de vorige ruimte via `IntakePrefillResolver`, pas opgeslagen bij "Volgende"). Airco **v3** gepubliceerd (v2-vragenset + vlaggen; ADR-0001). Nieuwe kolom `intake_answers.prefill_source`. Afleidbare waarden uit externe bronnen blijven BL-019/BL-020.
- BL-017 airco-template **v2**: audit op het ontwerpprincipe — minder verplichte schermen (kamermaten → één groottekeuze, vrije tekst → keuzelijsten, afstanden ontdubbeld, gevel-/groepenvragen optioneel). Seeder publiceert v1+v2; nieuwe intakes pinnen op v2 (ADR-0001).
- BL-018 vraag-voor-vraag klantflow: één zichtbare vraag per scherm (sectietitel als hoofdstukmarkering), autosave per antwoord, hervatten op vraag-cursor (`current_question_key` / `current_section_instance_key`), conditionele vragen worden overgeslagen tot ze relevant zijn.

### Fixed

- Productmetric **Direct genoeg informatie** gebruikt nu de eerste installateursbeoordeling in plaats van de later overschreven reviewrij. Review-events bewaren daarvoor alleen beslissing en `enough_information`; een dossier dat eerst een vervolgronde nodig had telt niet achteraf alsnog als direct compleet.
- Een lokaal als te donker of te klein gemarkeerde intake- of vervolgfotografie geeft nu niet alleen een kwaliteitsmelding, maar herhaalt ook de concrete foto-opdracht uit de gepinde template of installateursvraag: hoe opnieuw fotograferen én wat exact in beeld moet staan. De melding wordt na verversen of hervatten opnieuw opgebouwd uit het opgeslagen kwaliteitsverdict; een geldige AI-herhaalinstructie uit de meterkastbeoordeling blijft eveneens zichtbaar.
- De HTML-rapportpreview gebruikt een afgeschermde, geautoriseerde endpoint in plaats van een groot `srcdoc`-attribuut; dit voorkomt de browserfout bij het initialiseren van de ingesloten preview en houdt het rapport uit de parent-markup.
- Klantlayout laadt niet langer een tweede losse Alpine-instance naast Livewire; dit verwijdert de consolewaarschuwing en voorkomt dubbel uitgevoerde Alpine-handlers.
- `DatabaseSeeder` kon geen demo-intakes maken doordat model-events bewust uitstaan en de UUID alleen in een `creating`-event werd gezet; `CreateIntake` kent de UUID nu zelf toe, met het model-event als fallback voor overige aanmaakpaden.
- Klantintake: verplichte Ja/Nee-vragen (Livewire `"1"`/`"0"`) werden niet als beantwoord gezien → “Volgende”/afronden geblokkeerd (`AnswerValueReader`).
- Foto-upload ververste alleen nog de betreffende form-composite i.p.v. de hele form-state te wissen.
- “Nieuwe link genereren” diende het formulier niet in (`x-secondary-button` default `type=button` → nu `type=submit`).

### Added

- BL-001 publieke demo: homepage **Start demo** (`POST /demo/start`) maakt een tijdelijke airco-intake + klantlink (`is_demo`, TTL, watermerk, geen AI-job); hourly purge via `intakes:purge-demos`. Feature-flag `DEMO_ENABLED` (nu standaard aan; zie Changed hierboven).
- `public/.user.ini` met PHP upload-limieten (`upload_max_filesize=10M`, `post_max_size=12M`) voor cPanel/LiteSpeed web-requests (BL-003).
- `/health` exposeert `php_upload` (ini + app-limiet) zodat staging-limieten remote te meten zijn zonder SSH.
- `AGENTS.md`: projectgeheugen met vast hoofddoel en vast ontwerpprincipe (alleen door producteigenaar aan te passen), snelstart-leesroutine en taakrouting (gericht lezen i.p.v. alles doorzoeken), documentkaart (bron van waarheid per onderwerp), versioneringsregels en verplicht onderhoudsprotocol voor agents.
- Documentversieheaders (`Documentversie` + `Laatste update`) op alle beheerde docs, README en AGENTS.md.
- Fase 6 AI-slice: `ai_runs`, null/fake/heuristic clients, `SummarizeIntake` job na afronding, AI-voorstel in HTML-rapport (soft-fail).
- Fase 5: `CompletenessChecker`, `CompleteIntake`, HTML-rapport (`generated_reports`), system attention points, klant-afronden + bedankt-scherm, installer review (`SubmitIntakeReview` / `ReviewDecision`).
- `docs/backlog.md` + GitHub issue #5: demo-versie van de app (backlog).
- Fase 4 foto-uploads: private storage, Livewire upload/preview/verwijderen, beveiligde serve-routes, validatie, installer-galerij.
- Deploy activeert na migraties ook `IntakeTemplateSeeder` (idempotente template-reference-data).
- Fase 3 klantintake: beveiligde link `/o/{token}`, Livewire-stappenflow, autosave, hervatten, conditionele vragen, voortgang.
- Producthomepage op `/` met korte uitleg, navigatie naar login/register en dashboard voor ingelogde gebruikers.
- Fase 2 interne basis: intake-schema, airco-template v1, dashboard, opname aanmaken, klantlink kopiëren/intrekken/herniewen, seeddata, feature tests.

### Changed

- BL-010/011 omgevingsscheiding: `https://intake-engine.nl/` gebruikt een eigen production-releaseboom, `.env`, app-key, sessiecookie, database, private storage en cronjobs; `https://staging.intake-engine.nl/` blijft volledig apart voor `main`-deploys. Nieuwe `deploy-production.yml` draait op `v*`-tags of handmatige dispatch met `PRODUCTION_*`-secrets. `activate.sh` controleert doelomgeving en release-retentie, en verwijdert stale Laravel-runtimecaches vóór migraties om cross-environment activatie te voorkomen. README v1.29, deploymentdocs v2.0, backlog v3.32, teststatus v1.22 en AGENTS v1.5 leggen beheer, rollback en verificatie vast.
- `docs/backlog.md` v3.22 + `docs/ai.md` v1.2 + `docs/database.md` v1.6 + `docs/functional-test-status.md` v1.14 + README v1.20: BL-006/BL-007 → `done` (AI-aandachtspunten + fotokwaliteit + externe provider-clientlaag); band H deels afgerond.
- `docs/backlog.md` v3.21 + `docs/intake-engine.md` v1.7 + `docs/functional-test-status.md` v1.14 + README v1.19: BL-022 → `done` (voortgang op verplichte vragen + klikbare ontbreekt-lijst); band J rest BL-025; staging-smoketest als `todo`.
- `docs/backlog.md` v3.20 + `docs/intake-engine.md` v1.6 + `docs/functional-test-status.md` v1.13 + README v1.18: BL-023 → `done` (auto-doorgaan + Enter); band J-keten bijgewerkt (volgende: BL-022); staging-smoketest als `todo`.
- `docs/backlog.md` v3.19 + `docs/uploads.md` v1.6 + `docs/functional-test-status.md` v1.12 + README v1.17: BL-021 → `done` (multiselect + galerijkeuze); band J-keten bijgewerkt (volgende: BL-023); staging-smoketest als `todo`.
- `docs/backlog.md` v3.18 + `docs/uploads.md` v1.5 + `docs/functional-test-status.md` v1.11: BL-024 → `done` (vraaglabels + groepering foto-galerij installateur); staging-smoketest als `todo`.
- `docs/backlog.md` v3.17 + README v1.16: verbeterronde op bestaande functionaliteit, getoetst aan het hoofddoel (geen nieuwe features) — vijf nieuwe items: BL-021 (foto's multiselect + galerijkeuze niet blokkeren), BL-022 (voortgang op verplichte vragen + klikbare "ontbreekt nog"-lijst + leesbare ruimtenamen), BL-023 (auto-doorgaan na eenduidige keuze, Enter = Volgende), BL-024 (vraaglabels i.p.v. keys in installateursweergave), BL-025 (wizard-responstijd: dubbele queries per Livewire-request). Nieuwe parallel-bands J (klantwizard-keten) en K (installateursweergave); uitvoeringsvolgorde bijgewerkt.
- `docs/backlog.md` v3.16 + `docs/database.md` v1.5 + `docs/DEPLOYMENT.md` v1.7 + `docs/functional-test-status.md` v1.10 + `docs/ARCHITECTURE.md` v1.2 + README v1.15: BL-014/015/005/009 → `done`; scheduler/mail/PDF/retention gedocumenteerd.
- `docs/DEPLOYMENT.md` v1.6 + AGENTS.md v1.4 + README v1.14 + `docs/backlog.md` v3.15: checklist **Handmatige acties producteigenaar** (SMTP, `DEMO_ENABLED`, domein/SSL, cron; optioneel AI/productie/S3).
- `docs/backlog.md` v3.14 + `docs/DEPLOYMENT.md` v1.5 + `docs/functional-test-status.md` v1.9 + README v1.13: BL-004 → `done` (code); SMTP-smoke op staging als todo.
- `docs/backlog.md` v3.13: parallelisatie — bands A–I (herberekend na BL-002/BL-008/BL-016 done), kolom **Band** in overzichtstabel, **Parallel**-regels per open item; concrete parallel-startsets.
- `docs/backlog.md` v3.12 + `docs/uploads.md` v1.4 + `docs/functional-test-status.md` v1.8 + README v1.12: BL-008 → `done` voor code delivery; staging iPhone-smoketest toegevoegd als functionele test-todo.
- `docs/backlog.md` v3.11 + `docs/intake-engine.md` v1.5 + `docs/database.md` v1.4 + README v1.11: BL-016 → `done` (prefill + airco v3 + `prefill_source`-kolom); nieuwe `todo`-regels in `docs/functional-test-status.md`.
- `docs/backlog.md` v3.10 + `docs/functional-test-status.md` v1.6 + README v1.10: BL-002 → `done` — staging kernflow Fase 3–5 hertest groen na deploy #14 (hergenereren/intrekken/afronden/rapport/review pass; AI-samenvatting blocked bij `AI_PROVIDER=null`). BL-018/BL-017-flow nog los te hertesten.
- `docs/backlog.md` v3.9: BL-017 → `done` (airco-template v2).
- `docs/intake-engine.md` v1.4 + `docs/database.md` v1.3: airco v2-documentatie en seeddata.
- README → v1.9: status BL-017.
- `AGENTS.md` v1.3: onderhoudsplicht voor **Tips voor cloud-agents** in het protocol + DoD-checklist; tips uitgebreid met staging/Playwright-lessen (cPanel 428-cookie, token-charset, Livewire blur/live, demo/AI-valkuilen).
- `AGENTS.md` v1.2: sectie **Tips voor cloud-agents** (PHP 8.4, Composer, Vite-build, sqlite-tests, repo-shortcuts) zodat volgende agents sneller kunnen bootstrapen.
- `docs/backlog.md` v3.8: BL-018 → `done` (vraag-voor-vraag klantflow, PR #18); overzichtstabel met uitvoeringsvolgorde behouden.
- `docs/intake-engine.md` v1.3 + `docs/database.md` v1.2: klantflow één vraag per scherm; wizard-cursor-kolommen.
- `docs/backlog.md` v3.6: volledige herprioritering van alle open items getoetst aan het hoofddoel (handelingen besparen/repareren in de kernflow). Overzichtstabel heeft nu een expliciete uitvoeringsvolgorde (kolom #). Opgehoogd naar high: BL-008 (HEIC), BL-011 (eigen domein/SSL), BL-016 (prefill). Verlaagd naar low: BL-009 (purge-job) en BL-010 (production-deploy), beide met her-ophoogtrigger zodra echte klantdata/productiegang concreet is.
- `docs/backlog.md` v3.5: feedback producteigenaar verwerkt — te veel intakevragen en slimmer verzamelen. Nieuwe items BL-018 (vraag-voor-vraag klantflow), BL-019 (afleiden uit adres/openbare bronnen: satellietbeeld, BAG) en BL-020 (foto-gedreven afleiding en adaptieve vervolgvragen, bv. meterkastfoto → vrije groep); BL-017 (template-audit) prioriteit medium → high met concrete schrap-/vervangkandidaten; volgorde-advies bijgewerkt.
- `docs/intake-engine.md` v1.2: uitbreidingspunten verwijzen naar backlog-items (BL-016 t/m BL-020); geplande vraag-voor-vraag flow (BL-018) genoemd bij de sectieweergave.
- `docs/backlog.md` v3.4: BL-001 → `in_progress` (Start demo-pad).
- `docs/functional-test-status.md` v1.3: todo-regels voor publieke demo + purge (BL-001).
- `docs/DEPLOYMENT.md` v1.4 + `.env.staging.example` / `.env.production.example`: `DEMO_*`-flags.
- README → v1.6: BL-001 demo-pad genoemd.
- `docs/uploads.md` v1.3 + `docs/DEPLOYMENT.md` v1.3: staging-meting 2026-07-18 via `/health` (`512M`/`512M`); BL-003 afgerond (PR #13).
- `docs/functional-test-status.md` v1.2: sessie 2026-07-18 (BL-002 browserhertest) met bevindingen.
- `docs/backlog.md` v3.3: BL-003 → `done`, BL-002 → `in_progress` (merge PR #13 + BL-002-fixes).
- README → v1.5: BL-003 done; BL-002 browserhertest bezig.
- `docs/uploads.md` v1.2 + `docs/DEPLOYMENT.md` v1.2: upload-limieten via `.user.ini` als voorkeur; meetinstructie via `/health`.
- `docs/backlog.md` v3.1: BL-003 → `in_progress`.
- README → v1.4: statusregel BL-003 bijgewerkt.
- Documentstructuur ontdubbeld ("één bron per feit"): de geheugenkaart in AGENTS.md is nu de enige volledige documentkaart — de README-documentatietabel is vervangen door een verwijzing plus drie snelle ingangen; werkafspraken (branching, kwaliteit) hebben AGENTS.md § Werkafspraken als enige bron; README-secties Storage/Queues/Logging samengevoegd tot één verwijzende "Runtime"-sectie. README → v1.3, AGENTS.md → v1.1.
- `docs/backlog.md` v3.0: alle items gegroepeerd in vijf epics (E1 frictieloze basisflow, E2 communicatie zonder handwerk, E3 vraag minder/verzamel slimmer, E4 AI bespaart beoordeelwerk, E5 bruikbaar dossier & groei), elk expliciet herleid naar het vaste hoofddoel en ontwerpprincipe in AGENTS.md; nieuwe items BL-014 (afrondingsnotificatie), BL-015 (herinnering stilliggende intake), BL-016 (prefill/hergebruik bekende gegevens), BL-017 (airco-template v2 audit); BL-008 (HEIC) prioriteit low → medium.
- `docs/backlog.md` v2.0: geherstructureerd tot de enige projectbacklog met stabiele ID's (BL-001 t/m BL-013), status, prioriteit en afhankelijkheden; alle bekende uitgestelde items (demo, hertest, uploadlimieten, SMTP, PDF, externe LLM, HEIC, purge-job, production deploy, domein/SSL, multi-tenancy, S3) opgenomen.
- `docs/implementation-plan.md` gemarkeerd als afgeronde historie (Fase 1–6 klaar); nieuw werk hoort in de backlog.
- `docs/functional-test-status.md`: kapotte opmaak in sessienotities hersteld; todo-regels toegevoegd voor Fase 5/6-functionaliteit.
- README: status bijgewerkt (Fase 1–6 gemerged naar `main`), verwijzing naar AGENTS.md toegevoegd.
- `docs/ARCHITECTURE.md`: opgeloste trade-offs (Livewire, timezone, `MEDIA_DISK`) verwijderd; open trade-offs verwijzen naar backlog-ID's.

### Config

- `INTAKE_FOLLOW_UP_MAX_ROUNDS`, `INTAKE_FOLLOW_UP_MAX_ITEMS`, `INTAKE_FOLLOW_UP_MAX_PHOTOS` voor BL-027-limieten.
- `INTAKE_REMINDER_DAYS`, `INTAKE_SOFT_DELETE_RETENTION_DAYS` in `.env*.example` + `config/intake.php` (`reminder`, `retention`).
- Dependency `barryvdh/laravel-dompdf` voor PDF-export (BL-005).
- `.env*.example` + `docs/DEPLOYMENT.md` § Mail: SMTP-placeholders voor BL-004; bij `MAIL_MAILER=log` geen klantlink-mail (ADR-0002).
- `config/intake.php` splitst upload-input (`accepted_mimes`/`accepted_extensions`, incl. HEIC/HEIF) van opgeslagen types (`stored_mimes`/`stored_extensions`) en voegt `uploads.conversion` toe.
- `AI_PROVIDER`, `AI_API_KEY`, `AI_TIMEOUT_SECONDS`, `config/ai.php`
- `INTAKE_UPLOAD_MAX_KB`, `INTAKE_UPLOAD_MAX_FILES`
- `config/intake.php` uploads-sectie

### Known limitations

- Geen externe LLM-provider nog (alleen null/fake/heuristic); OpenAI e.d. later na DPIA.
- Staging-mails (klantlink, afrondingsnotificatie, herinnering) wachten op echte SMTP in `shared/.env` (bij `MAIL_MAILER=log` skip).
- Soft-delete-UI voor intakes ontbreekt nog; BL-009-purge is klaar zodra dossiers soft-deleted worden.
- Demo-user `installateur@example.com` ontbreekt op staging (deploy seedt alleen templates).
- Multi-tenancy bewust afwezig.
- Demo-versie: code geleverd (BL-001); staging-flag + smoke nog open.

## [0.1.0] — infrastructuur + Fase 1 docs

### Added

- Fase 1 documentatie: productdoel, feitelijke stack, schema-ontwerp, intake-engine, uploads, AI-roadmap, implementatieplan.
- ADRs: templateversionering, klanttoegang zonder account, uploadbeveiliging, sync/async, AI uitgesteld, geen multi-tenancy in MVP.
- `docs/database.md` met Mermaid ER-diagram (ontwerp).
- Laravel-skelet, Breeze Blade auth, Livewire package, Pest/Pint/PHPStan.
- CI (Pint, PHPStan, Pest) en staging deploy via GitHub Actions + `deploy/activate.sh`.

### Changed

- README stackversies gecorrigeerd (Laravel 13.20).
- Architectuurdoc bijgewerkt aan auditbevindingen.
