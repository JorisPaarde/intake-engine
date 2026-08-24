# Backlog — Digitale Opname

> **Documentversie:** 4.22 · **Laatste update:** 2026-08-24 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

De **enige backlog** van dit project: al het werk dat bewust niet in de afgeronde MVP-fasen 1–6 zit (zie `docs/implementation-plan.md`), plus nieuw ontdekt werk. Proces en statusregels: zie [AGENTS.md § Backlogproces](../AGENTS.md#backlogproces).

De MVP-bouwstenen staan historisch onder E1–E5. De productfase E6–E10 is op 2026-07-30 geïmplementeerd en volgt het [productmodel](product-model.md): één centrale technische opname na een bestaande aanvraag, meerdere bijdragers, beslisgereedheid en voor airco afzonderlijke koel-, condens- en stroomverbindingen.

Status: `backlog` · `ready` · `in_progress` · `done` · `dropped` — prioriteit: `high` · `medium` · `low`

**Leeswijzer:** scan de epictabel en de overzichtstabel hieronder; open daarna alleen de detailsectie van het item waaraan je werkt.

## Epics

### Geïmplementeerde dossierfase

| Epic | Naam | Resultaat |
|------|------|-----------|
| E6 | Centrale opname en beslisgereedheid | Eén technisch dossier met bewijs, herkomst, onzekerheden en beslisstatus per gebied; vragenlijstcompleetheid is alleen taakcompleetheid. |
| E7 | Klant-, installateur- en hybride bijdragen | De installateur kiest wie opneemt; zelf uitvoeren vereist geen klantlink; later kan altijd één afgebakende klanttaak worden gestuurd. |
| E8 | Airco-opstellingen en verbindingen | Gewenste ruimtes, kandidaatposities en installatieopties met afzonderlijke koel-, condens- en stroomroutes. |
| E9 | Automatisch vaststellen en uitzonderingen beoordelen | Bestaande bronnen en AI vullen het dossier; sterke afleidingen worden automatisch gebruikt en alleen beslissende uitzonderingen worden voorgelegd. |
| E10 | Aantoonbare tijd- en ritbesparing | Metingen op op-afstand-offerbaar, installateurstijd, klantinspanning, aanvullingsrondes, locatiebezoeken en montageverrassingen. |

### Geïmplementeerde MVP-fundering

| Epic | Naam | Historische functie |
|------|------|---------------------|
| E1 | Frictieloze basisflow | Betrouwbare klantwizard, uploads, omgevingen en eerste funnelmetrics. |
| E2 | Communicatie zonder handwerk | E-mail, herinneringen, afrondingssignaal en gerichte vervolgrondes. |
| E3 | Vraag minder, verzamel slimmer | Prefill, adaptieve vragen en openbare bronverrijking. |
| E4 | AI bespaart beoordeelwerk | Samenvatting, aandachtspunten, foto-afleiding en routebackend. |
| E5 | Bruikbaar dossier & klaar voor groei | PDF, tenancy, branding, demo, beheer en schaalbaarheid. |

BL-030 en BL-035 t/m BL-042 zijn in één uitbreidende implementatie geleverd. Hun eerdere afhankelijkheidsbanden blijven alleen in de detailsecties als historische ontwerpvolgorde herkenbaar. Open operationeel werk staat bij BL-001; S3-mediadisk (BL-013) is app-klaar en wacht op eventuele env-omschakeling; externe AI-activering blijft een DPIA-/configuratiegate en is geen open implementatie-item.

## Overzicht

Geprioriteerd op totale installateurstijd, vermeden ritten, technische zekerheid en veilige stapsgewijze migratie. `done`/`dropped` staan zonder volgnummer.

**Nummering:** BL-063–065 zijn gereserveerd voor draft PR’s [#74](https://github.com/JorisPaarde/intake-engine/pull/74) / [#75](https://github.com/JorisPaarde/intake-engine/pull/75) (AI-prefill); niet hergebruiken. Nieuwe items starten bij BL-066.

| # | ID | Item | Epic | Status | Prioriteit | Band / afhankelijkheid |
|---|----|------|------|--------|------------|-------------------------|
| — | BL-035 | Centrale dossierkern en migratiebrug | E6 | done | high | N (done) |
| — | BL-039 | Airco: ruimtes, plaatsingsopties en installatieopties | E8 | done | high | P (done) |
| — | BL-036 | Beslisgereed installateursdossier en volgende acties | E6 | done | high | N (done) |
| — | BL-037 | Installateur voert opname volledig zelf uit | E7 | done | high | O (done) |
| — | BL-038 | Afgebakende klanttaken en hybride workflow | E7 | done | high | O (done) |
| — | BL-049 | Contextgebonden foto’s en technische notities | E7 | done | high | O (done) |
| — | BL-040 | Koel-, condens- en stroomverbindingen + routebrug | E8 | done | high | P (done) |
| — | BL-041 | AI-synthese, gerichte vervolgtaken en uitzonderingsreview | E9 | done | high | Q (done) |
| — | BL-042 | Uitkomstmetrics en montagefeedback | E10 | done | medium | R (done) |
| — | BL-030 | Foto-varianten: dossier + AI-analyse (JPEG, tokens/storage) | E9 | done | high | H (done) |
| ∥ | BL-001 | Demo-versie van de app | E5 | in_progress | medium | A · operationeel |
| — | BL-043 | Publieke productfunnel en interesse-CTA | E5 | done | medium | A (done) |
| — | BL-044 | Hervatbare MySQL-dossiermigratie | E5 | done | high | deployherstel (done) |
| — | BL-045 | Eenvoudige installateurstaal op de productfunnel | E5 | done | medium | A (done) |
| — | BL-046 | Brede productbelofte op de productfunnel | E5 | done | medium | A (done) |
| — | BL-050 | Productfunnel in JPWebcreation-huisstijl | E5 | done | medium | A (done) |
| — | BL-051 | Demo-PDF op aanvraag als lead | E5 | done | medium | A (done) |
| — | BL-066 | Demo beëindigen: bevestiging + verlopen-pagina | E5 | ready | high | A · product/demo/UX |
| — | BL-067 | Demo-rolkeuze: installateur primair | E5 | ready | high | A · product/demo/UX |
| — | BL-068 | Demo-create: geen ‘mailen’ als mail uit staat | E5 | ready | high | A · product/demo/UX |
| — | BL-069 | Geen vragenlijst-100% als ‘opname compleet’ | E6 | ready | high | A · product/demo/UX |
| — | BL-070 | Demo-tour: één progressielaag | E5 | ready | medium | A · product/demo/UX |
| — | BL-071 | Rest-UI op language.md | E5 | ready | high | A · product/demo/UX |
| — | BL-072 | Production-release van Unreleased | E5 | ready | medium | A · operationeel |
| — | BL-052 | Gecontroleerd eenvoudig Nederlands in de app-UI | E5 | done | medium | A (done) |
| — | BL-053 | Mobiele werkplek: acties eerst, info dicht | E5 | done | high | O · bij BL-037 |
| — | BL-054 | Sticky CTA = echte handeling | E5 | done | high | O · bij BL-053 |
| — | BL-055 | Open punten tikken door naar werkblok | E5 | done | high | O · bij BL-054 |
| — | BL-056 | Sticky/open punten inkorten + mobiele volgorde | E5 | done | high | O · bij BL-054 |
| — | BL-057 | Bewijsfoto’s bij het object | E5 | done | medium | O · bij BL-049/053 |
| — | BL-058 | Licht afronden na voorstelgoedkeuring | E5 | done | medium | O · bij BL-054 |
| — | BL-059 | Ruimtes bewerken na aanmaken | E7 | done | high | O · bij BL-037/054 |
| — | BL-060 | Plaatsingen bewerken na aanmaken | E8 | done | high | O · bij BL-039/054 |
| — | BL-061 | AI-uitzondering → 1-klik klanttaak | E7 | done | high | O · bij BL-038/041 |
| — | BL-062 | Open punt / foto → vraag klant | E7 | done | high | O · bij BL-054/049 |
| — | BL-047 | Gestructureerde adresregistratie en BAG-herstel | E3 | done | high | F (done) |
| — | BL-048 | Openingszin hergebruiken en broninformatie terugbrengen | E3 | done | high | F (done) |
| — | BL-013 | S3 als mediadisk | E5 | done | low | I · operationeel |
| — | BL-029 | Begeleide leidingroute volgens één globale routeflow | E4 | dropped | high | Vervangen door ADR-0012 / BL-040; backend blijft |
| — | BL-034 | Splitconfiguratie als installateursaandachtspunt | E4 | done | medium | H (done) |
| — | BL-033 | Postcode-eerst adresaanvulling bij nieuwe opname | E3 | done | high | F (done) |
| — | BL-012 | Multi-accountplatform voor installatiebedrijven | E5 | done | high | I (done) |
| — | BL-031 | White-label branding uit installateurslogo | E5 | done | high | I (done) |
| — | BL-032 | Modern, strak en Apple-achtig productdesign | E5 | done | high | I (done) |
| — | BL-028 | Dev-admin: staging-inzage in dienststatus en opname-data | E5 | done | medium | I (done) |
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
| — | BL-010 | Production-deployworkflow (tags + eigen omgeving) | E5 | done | low | I (done) |
| — | BL-017 | Airco-template v2: vraag-voor-vraag audit op het ontwerpprincipe | E3 | done | high | — |
| — | BL-018 | Vraag-voor-vraag klantflow (één vraag per scherm) | E3 | done | high | — |
| — | BL-003 | Staging PHP-uploadlimieten verifiëren/verhogen | E1 | done | high | — |

## Epic E6 — Centrale opname en beslisgereedheid

### BL-035 — Centrale dossierkern en migratiebrug

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-30 · **PR:** deze PR · **Band:** N · **Ref:** [product-model.md](product-model.md), ADR-0011
- **Doel:** maak de opname technisch leidend zonder de huidige productieflow of gepinde templateversies te breken.
- **Scope:**
  - introduceer generieke, tenantgebonden objecten voor dossierwaarnemingen/conclusies, bewijslinks en bijdrageopdrachten;
  - ieder record bewaart actor/bron, vaststellingswijze, zekerheid, status en tijdstip;
  - koppel bestaande `intake_answers`, `intake_external_facts`, `intake_uploads` en installateurswaarnemingen via een migratiebrug aan dossieronderwerpen;
  - houd bestaande vraag-/upload-FK's tijdens de migratie intact;
  - maak expliciet onderscheid tussen **taak compleet** en **technisch beslisgereed**;
  - lever services waarmee domeinen een beslisgebied en blokkerende onzekerheid kunnen registreren.
- **Acceptatie:** bestaande intakes blijven leesbaar en af te ronden; nieuwe records zijn tenantgescope en auditbaar; één feit kan zonder duplicatie als bewijs bij een dossierobject worden gebruikt; geen documentatie of UI claimt dat een toekomstige tabel al bestaat.
- **Niet in scope:** nieuwe klant- of installateurs-UI, airco-opstellingen, verwijderen van de huidige template-engine.
- **Resultaat:** uitbreidende migration met dossieronderwerpen, records, bewijslinks, bijdragentaken en beslisgebieden; bestaande antwoorden, bronnen, uploads en vervolgrondes worden idempotent gebridged. Gepinde templates en legacyrapport/review blijven werken; tenant- en cross-company-invarianten zijn getest.

### BL-036 — Beslisgereed installateursdossier en volgende acties

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-30 · **PR:** deze PR · **Band:** N · **Afhankelijk:** BL-035 + BL-039
- **Doel:** vervang “100% compleet” als installateurswaarheid door een dossier dat direct laat zien welke beslissing mogelijk is.
- **Scope:** status per beslisgebied; installatievoorstel + bewijs; alleen conflicten/uitzonderingen prominent; kostenbepalende risico's; primaire acties **Offerte voorbereiden**, **Prijsindicatie**, **Gerichte aanvulling**, **Locatiebezoek**, **Afwijzen**.
- **Beoordeling:** de installateur keurt het complete voorstel en de uitzonderingen goed; geen checkbox per automatisch feit. Correcties blijven bron en voorgaande AI-/bronconclusie bewaren.
- **Acceptatie:** een klanttaak kan afgerond zijn terwijl één technisch beslisgebied openstaat; iedere blokker toont welk bewijs ontbreekt en welke volgende actie zinvol is; bestaande reviewbeslissingen migreren of mappen reproduceerbaar.
- **Resultaat:** `DecisionReadinessService` berekent acht gebieden en volgende acties; de technische werkplek toont gereed, controle, blokkade, bewijs, kostenrisico en integraal voorstel los van wizardpercentage. Bestaande reviews blijven historisch leesbaar.

### BL-069 — Geen vragenlijst-100% als ‘opname compleet’

- **Status:** ready · **Prioriteit:** high · **Datum:** 2026-08-24 · **Epic:** E6 · **Band:** A (product/demo/UX)
- **Aanleiding:** AGENTS.md/product-model verbieden één vragenlijstpercentage als technische waarheid. Na de verkorte klantroute toont het installateursoverzicht `100% compleet` terwijl het dossier 2/8 is.
- **Doel:** taakcompleetheid en klaar-voor-offerte gescheiden in de UI. Nooit 100% compleet tonen als synoniem voor een afgeronde technische opname.
- **Scope:** installateursoverzicht en gerelateerde progressielabels; scheiding taak-% vs. beslisgebieden / volgende actie; demo- en productpaden.
- **Acceptatie:** after short customer demo path, overview does not say the opname is 100% complete; decision areas / next action remain the source of truth.

## Epic E7 — Klant-, installateur- en hybride bijdragen

### BL-037 — Installateur voert opname volledig zelf uit

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-30 · **PR:** deze PR · **Band:** O · **Afhankelijk:** BL-035
- **Doel:** bied vanaf **Nieuwe opname** de keuze **Zelf de opname uitvoeren**, zonder klantlink aan te maken of te versturen.
- **Scope:** mobiele camera-first werkweergave, vrije navigatie, zelf ruimtes, contextgebonden notities en foto's toevoegen, technische conclusies direct vastleggen en AI-controles op de achtergrond.
- **UX-regel:** geen eenvoudige klantinstructies of verplichte foto bij een eigen technische notitie; de installateur kan direct naar elk relevant dossieronderdeel springen.
- **Acceptatie:** een volledige opname en offertebasis kan zonder actieve klanttoken worden gemaakt; tokenroutes blijven ontoegankelijk zolang geen klanttaak bestaat; alle tenant- en private-mediaregels blijven gelden.
- **Resultaat:** startkeuze **Zelf de opname uitvoeren**, uitgeschakelde klanttoegang en directe mobiele werkplek voor ruimtes, posities, opties, routes, technische notities en foto-upload. BL-049 heeft de oorspronkelijke losse onderwerp-/methodekeuze daarna vervangen door objectgebonden acties. Eerste installateursbijdrage zet de lifecycle correct; installer-only-tokenroutes geven 404.

### BL-038 — Afgebakende klanttaken en hybride workflow

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-30 · **PR:** deze PR · **Band:** O · **Afhankelijk:** BL-037
- **Doel:** maak klantbijdragen optionele taken binnen het dossier in plaats van één verplichte volledige intake.
- **Scope:** bestaande klantflow als taakset; klantboodschap **Met uw hulp kunnen we uw airco sneller plaatsen**; link alleen bij klanttaken; installateur kan zelf beginnen en later één taak sturen; klant ziet uitsluitend open klanttaken; klantopname kan door installateur worden aangevuld.
- **Hergebruik:** generaliseer `intake_follow_up_rounds/items` zodat gerichte taken vóór én na een eerste afronding mogelijk zijn; behoud beveiligde token-, autosave-, upload- en hervatprincipes.
- **Acceptatie:** tests voor volledig klant, volledig installateur, hybride, en installateur-start → één latere klantfoto; wisselen van workflow maakt geen dubbel dossier of tweede waarheid.
- **Resultaat:** gerichte tekst-/foto-/PDF-taken delen de bestaande beveiligde vervolgflow, activeren toegang alleen voor open klantwerk en zetten haar na afronding weer uit. Klant-, installateur- en hybride bijdragen landen in hetzelfde dossier; tests dekken de vier kernscenario's.

### BL-049 — Contextgebonden foto’s en technische notities

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-31 · **PR:** deze PR · **Band:** O · **Volgt op:** BL-035/037/040
- **Aanleiding:** de werkplek vroeg de installateur bij **Camera en bewijs** en **Vakwaarneming** zelf een intern dossieronderwerp, vrije sleutel en vaststellingsmethode te kiezen. De lijst mengde opname, ruimtes, posities en verbindingen; telefonisch verkregen informatie kon daardoor als definitieve vakwaarneming worden opgeslagen en foto en conclusie leken dezelfde handeling.
- **Resultaat:** foto’s en technische notities worden rechtstreeks vanaf de betreffende ruimte, positie of verbinding toegevoegd. De route bepaalt het onderwerp; de server maakt sleutel, methode en herkomst. Een routefoto blijft tegelijk routesegment. Bij een gewone ruimte- of positiefoto mag beeld-AI maximaal drie korte, beslisrelevante constateringen voorstellen; alleen voorstellen boven de configureerbare zekerheidsgrens verschijnen en blijven `proposed` totdat de installateur **Klopt** kiest of de tekst aanpast. Telefonisch verkregen informatie heeft geen handmatige snelweg meer naar een 100%-zekere vakwaarneming.
- **UX:** de losse kaarten **Camera en bewijs** en **Vakwaarneming** zijn verwijderd. De acties heten **Foto maken** en **Technische notitie**; bronlabels worden automatisch getoond. De bronsectie heet **Woninggegevens** met een korte uitleg zonder dossierjargon.
- **Acceptatiebewijs:** featuretests bewaken context- en tenantgrenzen, servergegenereerde sleutels/methoden, foto- en AI-evidence, minimale zekerheid, `proposed` → door installateur bevestigd/aangepast en afwezigheid van interne velden. Externe beeld-AI blijft standaard uit, soft-fail en achter de bestaande DPIA-/budgetgate; staging- en mobiele controle staat als `todo` in `functional-test-status.md`.

## Epic E8 — Airco-opstellingen en verbindingen

### BL-039 — Airco: ruimtes, plaatsingsopties en installatieopties

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-30 · **PR:** deze PR · **Band:** P · **Afhankelijk:** BL-035 · **Ref:** ADR-0012
- **Doel:** modelleer de technische oplossing los van de huidige aanname “aantal gewenste ruimtes = vooraf gekozen aantal binnenunits”.
- **Scope:** gewenste ruimtes; kandidaatposities voor binnen-/buitenunit, voeding en afvoer; installatieopties die posities koppelen; single-split, multi-split of meerdere single-splits als voorstellen; rangschikking, status en bewijs.
- **Migratie:** airco v9 en bestaande `room-*`-antwoorden blijven bruikbaar; maak een nieuwe templateversie alleen voor de bijdrageflow, wijzig gepubliceerde versies nooit.
- **Acceptatie:** twee slaapkamers kunnen meerdere installatieopties hebben; klant kiest geen configuratie; AI/installateur kan voorstellen toevoegen en de installateur selecteert of corrigeert.
- **Resultaat:** persistente gewenste ruimtes, binnen-/buiten-/voedings-/afvoerposities en gevalideerde single-/multi-/meerdere-single-splitopties. Airco v10 behoudt de repeatable compatibiliteitskey maar vraagt klantgericht om gewenste ruimtes en geen unitpositie.

### BL-040 — Koel-, condens- en stroomverbindingen + routebrug

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-30 · **PR:** deze PR · **Band:** P · **Afhankelijk:** BL-039 + BL-030 · **Vervangt resterende BL-029-scope**
- **Doel:** maak per installatieoptie de drie kosten- en uitvoeringsbepalende routes volwaardig.
- **Scope:** verbindingstype `refrigerant`, `condensate`, `power`; concrete eindpunten; segmenten; lengteklasse, doorvoeren/obstakels, bereikbaarheid, onzekerheid en kostenimpact; per binnenunit eigen condensroute; gedeelde of aparte stroomroute waar passend.
- **Routebrug:** koppel `pipe_route_sessions/segments` aan één verbinding; hergebruik fotoanalyse/modeltiering, maar bouw geen globale routewizard. Start een foto-voor-fotolus alleen als kandidaatposities bestaan en het ontbrekende segment de beslissing kan veranderen.
- **Stroomveiligheid:** meterkast, groep/capaciteit, kabelroute en het systeemafhankelijke aansluitpunt horen bij één stroomverbinding; klant voert geen elektrische handelingen uit; eindcontrole installateur.
- **Acceptatie:** single-split en twee-slaapkamer/multi-split zijn modelleerbaar; iedere verbinding heeft eigen bewijs/open punten; een onoplosbare route leidt onderbouwd tot locatiebezoek.
- **Resultaat:** verbindingen hebben concrete optie-eindpunten, eigen status/segmenten/obstakels/onzekerheden/kostenimpact en vereiste stroomveiligheidscontrole. Iedere binnenpositie vereist koel én condens; stroom is apart. Een routesessie is uniek per verbinding, schrijft synthese terug en heropent veilig bij nieuw bewijs.

## Epic E9 — Automatisch vaststellen en uitzonderingen beoordelen

### BL-041 — AI-synthese, gerichte vervolgtaken en uitzonderingsreview

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-30 · **PR:** deze PR · **Band:** Q · **Afhankelijk:** BL-036/038/040 + BL-030
- **Doel:** laat AI de opname zoveel mogelijk voorbereiden zonder bevestigingsadministratie of autonome eindbeslissing.
- **Scope:** combineer aanvraaggegevens, BAG/PDOK, luchtfoto, EP-Online, 3DBAG, klant-/installateursbewijs en routes; stel plaatsingen/installatieopties voor; leg conclusies alleen automatisch vast wanneer een objectspecifieke serverregel bronkwaliteit, evidence, consistentie en impact als vrijwel zeker kwalificeert; genereer alleen de kleinste veilige taak die een blokkerende onzekerheid kan oplossen.
- **Review:** toon voorstel, afwijkingen, conflicten en open kosten-/veiligheidspunten; installateur corrigeert of keurt het geheel goed. Bewaar model/prompt/evidence/confidence en de delta naar de uiteindelijke keuze.
- **Gates:** bestaande DPIA-, budget-, privacy-, beeldvariant- en soft-failregels blijven verplicht. Providerfalen resulteert in handmatige dossierwerking, nooit in dataverlies of blokkade.
- **Acceptatie:** geen bron- of AI-veld-voor-veld-confirmatiescherm; onzekere of tegenstrijdige feiten worden niet stil toegepast; dezelfde context is idempotent; een vervolgtaak heeft aantoonbare besliswaarde.
- **Resultaat:** begrensde, geschoonde dossiersynthese met maximaal twaalf analysekopieën; harde referentie-, cardinaliteits-, route- en evidencevalidatie; idempotente vervanging van alleen AI-kandidaten; uitzonderingen en maximaal drie nog door installateur te versturen klanttaakvoorstellen. Geselecteerde/menselijke objecten en klanttoegang worden nooit autonoom gewijzigd.

## Epic E10 — Aantoonbare tijd- en ritbesparing

### BL-042 — Uitkomstmetrics en montagefeedback

- **Status:** done · **Prioriteit:** medium · **Datum:** 2026-07-30 · **PR:** deze PR · **Band:** R · **Afhankelijk:** BL-036/041
- **Doel:** bewijs dat de opname installateurstijd en locatiebezoeken vermindert zonder montagekwaliteit te verslechteren.
- **Scope:** percentage op afstand offerbaar; prijsindicatie versus definitieve offerte; actieve installateurstijd; klanttijd/-acties; gerichte rondes; percentage met locatiebezoek; redenen voor locatiebezoek; AI-voorstel versus definitieve keuze; verrassingen/meerwerk bij montage.
- **Meting:** splits wachttijd, klantinvultijd en actieve installateurstijd; behoud privacyveilige events; voeg na plaatsing een korte uitkomstregistratie toe.
- **Acceptatie:** definities zijn reproduceerbaar in `metrics.md`; historische BL-026-cijfers blijven herkenbaar; dashboards labelen duidelijk welke metrics al gemeten en welke nog onvoldoende geïnstrumenteerd zijn.
- **Resultaat:** één expliciete uitkomst per opname met offerte/indicatie/bezoek/plaatsing, handmatige actieve minuten, gecontroleerde bezoekredenen, vergeleken voorstel en deltacodes, montageverrassing en geselecteerde optie. `/metrics` toont reproduceerbare percentages/medianen en reden-/afwijkingsverdeling zonder klantinhoud.

## Epic E1 — Frictieloze basisflow

Historische MVP-epic: maakte de oorspronkelijke klantwizard, uploads en omgevingen betrouwbaar. De resultaten blijven de fundering voor de nieuwe bijdrageworkflows.

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

Historische MVP-epic: automatiseerde linkmail, afrondingssignaal, herinneringen en gerichte vervolgrondes. BL-038 hergebruikt die bouwstenen voor optionele klanttaken.

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

Historische MVP-epic: bouwde prefill, adaptieve vragen en automatische BAG/PDOK-, luchtfoto-, EP-Online- en 3DBAG-verrijking. ADR-0011 maakt die bestaande keten dossierbron in plaats van losse wizardoptimalisatie.

### BL-033 — Postcode-eerst adresaanvulling bij nieuwe opname

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-26 · **PR:** #51 + #52
- **Parallel:** band **F** — bouwt voort op BL-019 en raakt alleen de installerflow voor een nieuwe opname plus de bestaande PDOK-adreslookup.
- **Doel:** bij het aanmaken eerst postcode, huisnummer en optionele toevoeging invullen; de applicatie zoekt daarna het exacte BAG/PDOK-adres en vult straat en plaats aan. Dit voorkomt vrije adreszoektekst en dubbele invoer.
- **Kaders:** postcode en huisnummer worden server-side gevalideerd en genormaliseerd; meerdere toevoegingen blijven expliciet selecteerbaar; de gekozen lookup-ID en zichtbare adresvelden moeten bij elkaar passen. PDOK blijft fail-soft: storing of geen resultaat toont een duidelijke handmatige adresfallback en blokkeert het aanmaken niet. Geen API-call tijdens renderen; automatisch zoeken start pas na complete geldige invoer en een korte debounce.
- **Acceptatie:** postcode staat vóór adresvelden; `1234 AB + 10 (+ toevoeging)` levert via het bestaande authenticated/throttled endpoint één of meer veilige adressuggesties; keuze vult straat/postcode/plaats en lookup-ID; wijziging van postcode/huisnummer wist een oude selectie; toetsenbord en statusfeedback zijn toegankelijk; validatie en featuretests dekken exact resultaat, meerdere toevoegingen, ongeldige invoer, PDOK-storing en handmatige fallback.
- **Resultaat:** geldige postcode + huisnummer starten zonder extra knop automatisch de exacte lookup; toevoegingswijzigingen zoeken opnieuw. Debounce, abort en request-identiteitscontrole voorkomen dubbele of verouderde resultaten en automatische resultaten verplaatsen de focus niet.

### BL-047 — Gestructureerde adresregistratie en BAG-herstel

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-30 · **PR:** #60 · **Band:** F · **Volgt op:** BL-019/033
- **Aanleiding:** `2037 GR` + `273` kon als vrije tekst `Bernadottelaan, 273, 273` worden bewaard. De invoer valideerde het losse huisnummer wel, maar `CreateIntake` sloeg het niet op; de open BAG-route vergeleek daarna opnieuw de samengestelde tekst. Een test accepteerde ten onrechte dat alleen de optionele Kadaster-key dit achteraf kon repareren.
- **Resultaat:** postcode, huisnummer en toevoeging zijn persistente intakevelden; suggestiekeuze synchroniseert de canonieke toevoeging; PDOK/BAG zoekt en matcht op die gestructureerde identiteit en schrijft de BAG-spelling terug. De hervatbare migratie normaliseert alleen het exacte dubbele-huisnummerpatroon en vult veilig afleidbare bestaande huisnummers aan. Bij `not_found`/`unavailable` kan de installateur de adrescontrole opnieuw starten.
- **Acceptatiebewijs:** één ketentest voert exact `2037 GR` + `273` door requestnormalisatie, opslag, open PDOK-zoekquery, BAG-object en canonieke dossierregel, met `BAG_API_ENABLED=false`. Een aparte migratietest dekt backfill, hervatten en rollback.

### BL-048 — Openingszin hergebruiken en broninformatie terugbrengen

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-30 · **PR:** #61 · **Band:** F · **Volgt op:** BL-016/019/047
- **Aanleiding:** de installateur kon in `request_reason` al schrijven dat twee airco’s twee slaapkamers op zolder moeten koelen, maar `DeriveIntentFromRequest` draaide pas wanneer de klant zélf die vraag opsloeg. De klant kreeg daardoor opnieuw de vragen naar functie, aantal ruimtes en ruimtetype. De installateursweergave behandelde daarnaast ruwe BAG-logistiek zoals coördinaten, perceelreferentie en volledige gebruiksoppervlakte als even belangrijk als bouwjaar en isolatie.
- **Resultaat:** een begrensde lokale parser verwerkt alleen evidente Nederlandse formuleringen direct na aanmaken en bij het openen van een oudere nog actieve klantlink. De exacte voorbeeldzin levert `cooling`, twee gewenste ruimtes, twee slaapkamers en voor beide `floor_level=attic`; zolder is de verdieping en geen derde ruimte. De bron `request_text` geldt voor gepinde templates als sterke tekstafleiding, zonder externe AI-call. De primaire bronweergave toont alleen energielabel/isolatie, bouwjaar, relevante 3D-context en meterkastbeoordeling; ruwe brondata blijft voor audit en dev-admin bewaard en de luchtfoto staat ingeklapt.
- **EP-Online:** de bestaande verrijking blijft fail-soft en vereist op de omgeving `EP_ONLINE_ENABLED=true` plus een RVO-key. Bij resultaat vervalt de isolatievraag en toont het dossier de labelletter, isolatie-indicatie en beschikbare energiebehoefte. Zonder key of zonder geregistreerd label blijft de klantvraag staan.
- **Acceptatiebewijs:** unit- en featuretests voeren letterlijk `Ik wil twee airco’s om m’n slaapkamers op zolder te koelen.` door parser, HTTP-aanmaak, opslag en klantstappen met externe tekst-AI uit. Zij bewijzen ook dat beide slaapkamertaken de zolderverdieping overnemen. EP-Online-tests bewijzen de isolatie-afleiding en de opgeschoonde presenter; staging-smokes blijven als `todo` in `functional-test-status.md`.

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

Historische MVP-epic: leverde samenvatting, aandachtspunten, fotokwaliteit/-afleiding en de routebackend. BL-041 bouwt daarop door met herleidbare technische voorstellen en uitzonderingsreview; de installateur blijft beslissen.

### BL-034 — Splitconfiguratie als installateursaandachtspunt

- **Status:** done · **Prioriteit:** medium · **Datum:** 2026-07-26 · **PR:** #53
- **Doel:** de klant geen technische single-/multi-splitkeuze laten maken, maar de installateur bij meerdere binnenunits expliciet laten beoordelen of één multi-split of meerdere single-splits passend zijn.
- **Resultaat:** `CompletenessChecker` maakt bij meer dan één binnenunit het deterministische systeemaandachtspunt `review_split_configuration`, inclusief het gekozen aantal. Bij één binnenunit verschijnt geen extra punt. De buitenunit- en leidingroutesecties blijven éénmalig en vragen dus niet per binnenunit dezelfde foto's.

### BL-006 — Externe LLM-provider (na DPIA)

- **Status:** done *(clientlaag; activering geblokkeerd op DPIA + key)* · **Prioriteit:** medium · **Datum:** 2026-07-18 · **Ref:** ADR-0005, `docs/ai.md`
- **Doel:** OpenAI (of vergelijkbaar) client achter `AiClientInterface` naast null/fake/heuristic.
- **Resultaat:** `OpenAiClient` (OpenAI-compatibel, Laravel `Http`, JSON-mode) achter `AiClientInterface`; provider-keuze op `AI_PROVIDER`; `AiInputRedactor` verwijdert e-mail/telefoon vóór verzending; config `AI_BASE_URL`/`AI_MODEL`/`AI_API_KEY`/`AI_TIMEOUT_SECONDS`. Standaard `null`; getest met `Http::fake()`.
- **Resterende gate (niet-code):** DPIA/akkoord + key in `.env` door producteigenaar. Géén echte PII naar de provider vóór die er zijn.

### BL-007 — AI-uitbreidingen

- **Status:** done · **Prioriteit:** low · **Datum:** 2026-07-18 · **Ref:** [ai.md § Aandachtspunten](../docs/ai.md#aandachtspunten-voorstellen-bl-007) + § Fotokwaliteit
- **Doel:** `SuggestAttentionPoints`, `AssessPhotoUsability`, en UI waarmee de installateur AI-voorstellen accepteert of verwijdert. AI blijft ondersteunend, nooit bron van waarheid; niets blokkeert de kernflow.
- **Resultaat:** `SuggestAttentionPoints` gebruikt prompt v2 en een privacybegrensde integrale dossiercontext (antwoorden + labels/prefillbron, BAG/PDOK/EP-Online/3DBAG-feiten met provenance, uploadkwaliteit, aanvullende rondes, systeemsignalen, review en leidingrouteanalyses) → aandachtspunten met `source=ai`/`status=proposed`. Analyse start automatisch bij afronding en opnieuw na een aanvullende ronde; de handmatige knop/endpoint is verwijderd. De installateur accepteert (→ in rapport) of verwijdert; idempotent, soft-fail en bestaande beslissingen blijven behouden. `AssessPhotoUsability` (lokaal, GD) → niet-blokkerende "foto te donker/klein"-hint voor de klant + kwaliteitslabel voor de installateur (`intake_uploads.usability_verdict`). Provider `null` = geen voorstellen, zonder blokkade.
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

Historische MVP-epic: leverde rapport/PDF, demo, tenancy, branding, beheer en deployfundering.

### BL-005 — PDF-export van rapporten

- **Status:** done · **Prioriteit:** medium · **Datum:** 2026-07-18 · **PR:** #26
- **Doel:** naast het HTML-rapport (`generated_reports`) een PDF-pad, zodat het dossier direct in de offerte-/archiefflow van de installateur past zonder knip- en plakwerk. **Async** job (ADR-0004); HTML blijft bron.
- **Resultaat:** lichte Dompdf-export via `GenerateIntakePdfJob` na afronden; opslag op `MEDIA_DISK` (`pdf_disk`/`pdf_path`/`pdf_generated_at`); detailpagina **Download PDF** + opnieuw genereren; demo’s skippen PDF; hard purge ruimt PDF-bestanden op.

### BL-001 — Demo-versie van de app

- **Status:** in_progress · **Prioriteit:** medium · **Band:** A (operationeel, parallel) · **Ref:** [issue #5](https://github.com/JorisPaarde/intake-engine/issues/5)
- **Plan:** [bl-001-interactive-installer-demo.md](plans/bl-001-interactive-installer-demo.md)
- **Doel:** publiek of semi-publiek demopad zodat prospects/installateurs het product kunnen ervaren zonder eigen accountsetup of echte klantdata — het hoofddoel ("zo min mogelijk handelingen") toegepast op de allereerste kennismaking.
- **Nieuwe invulling (begeleide flow):** **Probeer de demo** → tijdelijke tenant/user → dashboard met welkomstpopup → *Nieuwe opname* waarin de installateur zelf postcode/huisnummer intypt → rolkeuze-modal i.p.v. mail (*Doorgaan als klant* / *Zelf de opname doen*) → verkorte klantwizard of werkplek met optioneel voorbeelddossier; coachmark-popups op elke stap.
- **Scenario (optioneel laden):** vaste BAG-/luchtfoto-/EP-Online-/3DBAG-voorbeeldcontext, twee gewenste ruimtes, synthetisch beeldbewijs, multi-splitvoorstel, koel-/condens-/stroomroutes, vooraf berekende AI-synthese en één voorgestelde meterkasttaak — als snelle boost naast live verrijking/AI.
- **Kaders:** `is_demo`, standaard-TTL twee uur, hourly hard purge inclusief tijdelijke demo-tenant en orphaned demo-workspaces; geen echte klantdata of klantmail. Adresverrijking en AI (foto/tekst/synthese) draaien wanneer die integraties aan staan. PDF alleen op vrijwillige aanvraag (BL-051).
- **Acceptatie:** start op dashboard; create toont postcode-lookup + BAG-verrijking; branch zonder mail; beide paden begeleid met AI zichtbaar waar aan; sample-dossier op verzoek; isolatie/TTL; tests groen.
- **Resultaat code:** begeleidde installateursstart, rolkeuze, verkorte klantroute, live verrijking/AI in demo, `LoadDemoSurveyScenario`, Alpine/native coachmarks en bijgewerkte Pest-dekking. Homepage-CTAs onderscheiden gast (**Probeer de demo** / **Inloggen**), actieve demosessie (**Verder in demo** / **Demo beëindigen**) en echt account (**Mijn opnames**); **Demo beëindigen** blijft in de app-nav zichtbaar zolang de demosessie loopt.
- **Na deploy:** staging-smoke homepage → create (verrijking zichtbaar) → branch → beide paden (foto-/tekst-AI) → sample-dossier → klanttaak → PDF-aanvraag; daarna BL-001 op `done`. Controleer ook terugkeer naar `/` tijdens demosessie en **Demo beëindigen**. Resterende smoke moet ook een pad **zonder** *Toon voorbeelddossier* bevatten. UX-items BL-066–071 hoeven niet te wachten op merge van AI-prefill draft PR’s #74/#75.

### BL-051 — Demo-PDF op aanvraag als lead

- **Status:** done · **Prioriteit:** medium · **Datum:** 2026-08-24 · **PR:** #77 · **Band:** A (bij BL-001) · **Epic:** E5
- **Doel:** laat de installateur aan het eind van de demo een PDF van het demorapport ontvangen door een e-mailadres in te vullen; dat adres is meteen een productlead.
- **Gedrag:** formulier op demo-werkplek en dossierpagina; bouwt/vernieuwt rapport-HTML; genereert PDF synchroon via `GenerateIntakePdf` (automatische PDF-jobs blijven demos skippen); stuurt PDF naar de prospect; slaat `product_interests` op met `source=demo_pdf_request` en notificeert `PRODUCT_INTEREST_MAIL_TO` (default `info@jpwebcreation.nl`).
- **Kaders:** honeypot + bestaande interest-throttle; bij `MAIL_MAILER=log` wel lead + downloadbare PDF, geen mailqueue; geen echte klantdata in de lead — alleen het vrijwillig opgegeven adres.
- **Acceptatie:** PDF-mail + lead-mail in tests met `Mail::fake()`; staging-smoke met SMTP zodra mailer niet `log` is.
- **Resultaat:** `RequestDemoReportPdf` + route `demo.report-pdf` + UI-component op werkplek/dossier; featuretests dekken PDF-mail, lead-mail, honeypot, `MAIL_MAILER=log` en afwijzing van niet-demo-opnames. Interne leadinbox wordt niet meer in de UI-copy getoond.

### BL-066 — Demo beëindigen: bevestiging + verlopen-pagina

- **Status:** ready · **Prioriteit:** high · **Datum:** 2026-08-24 · **Epic:** E5 · **Band:** A (product/demo/UX)
- **Aanleiding:** één klik op Demo beëindigen (geen confirm) droeg een actieve opname; daarna kale Laravel `404 | Not Found` op `/intakes/{id}/opname`.
- **Doel:** per ongeluk afsluiten voorkomen; bij verlopen/beëindigde demo een Nederlandse pagina met ‘start opnieuw’, geen framework-404.
- **Scope:** confirm-dialog vóór **Demo beëindigen**; Nederlandse expired/ended-demo-pagina met CTA; geen Laravel-404 voor verlopen demosessies.
- **Acceptatie:** confirm-dialog vóór einde; expired/ended demo nooit 404; CTA terug naar homepage/nieuwe demo.

### BL-067 — Demo-rolkeuze: installateur primair

- **Status:** ready · **Prioriteit:** high · **Datum:** 2026-08-24 · **Epic:** E5 · **Band:** A (product/demo/UX)
- **Aanleiding:** in een installateursdemo is ‘Doorgaan als klant’ de paarse hoofdknop. Prospects moeten acteren als hun eigen klant.
- **Doel:** primaire actie is zelf de opname doen of (in productie) naar de klant sturen. Klantpad in de demo is secundair: ‘Bekijk wat de klant ziet’.
- **Scope:** rolkeuze-UI/copy in de demo (en afgestemde productstart waar dezelfde knoppen gelden); installer-first CTA; klantpad blijft bereikbaar maar niet primair.
- **Acceptatie:** installer-first CTA; customer path remains reachable but not primary; copy does not present role-play as the real start.

### BL-068 — Demo-create: geen ‘mailen’ als mail uit staat

- **Status:** ready · **Prioriteit:** high · **Datum:** 2026-08-24 · **Epic:** E5 · **Band:** A (product/demo/UX)
- **Aanleiding:** knop ‘Opslaan en link mailen’ terwijl demo geen klantmail stuurt, daarna rolkeuze.
- **Doel:** knoptekst en vervolg matchen wat er gebeurt (opslaan / naar de klant sturen alleen als mail echt gaat).
- **Scope:** create-/branch-copy in demo vs. productie; geen claim dat e-mail is verstuurd wanneer demo-mail uit staat.
- **Acceptatie:** demo-copy never claims email was sent; production copy may still say mailen when SMTP sends.

### BL-070 — Demo-tour: één progressielaag

- **Status:** ready · **Prioriteit:** medium · **Datum:** 2026-08-24 · **Epic:** E5 · **Band:** A (product/demo/UX)
- **Aanleiding:** tour 6/6 overlays + product 8/8 sticky + 4 tabbladen. Installateur weet niet waar hij is. Sample-dossier is een shortcut; lege werkplek is de echte start.
- **Doel:** max één korte welkomstlaag; daarna de echte werkplek. Voorbeelddossier blijft optioneel, niet de default happy path.
- **Scope:** democoach/overlays en sticky progressie; voorbeelddossier als duidelijk gelabelde boost, niet als verplichte start.
- **Acceptatie:** no 6-step modal stack; prospect can finish a real empty opname without loading the sample; sample remains a clearly labeled boost.

### BL-071 — Rest-UI op language.md

- **Status:** ready · **Prioriteit:** high · **Datum:** 2026-08-24 · **Epic:** E5 · **Band:** A (product/demo/UX)
- **Aanleiding:** BL-052 is done, maar live UI breekt docs/language.md nog: chrome ‘Intake Engine’, ‘Open technische opname’, badge `Airco-opname · v11`, AI ‘power · zekerheid 0.76’, klant u/je-mix, Engelse HTML5-validatie (‘Please fill out this field.’) en Engelse 404.
- **Doel:** gebruikersgerichte tekst volgt language.md. Merk in UI: Digitale Opname of gewoon opname. ‘Open technische opname’ → ‘Opname openen’. Geen templateversie in de UI. AI-onzekerheid in gewone taal (‘Meterkast: groepen niet scherp. Vraag een betere foto.’). Klanttekst consequent u. Validatie/expired in het Nederlands.
- **Scope:** installateurs-, klant- en demo-UI; chrome/merknaam; knoppen/badges; AI-onzekerheidsteksten; HTML5-/validatiemeldingen; expired/404-pagina’s. Volgens [docs/language.md](language.md); geen nieuw jargon.
- **Acceptatie:** listed strings gone from installer/customer/demo UI; language.md glossary applied; no new jargon.

### BL-072 — Production-release van Unreleased

- **Status:** ready · **Prioriteit:** medium · **Datum:** 2026-08-24 · **Epic:** E5 · **Band:** A (operationeel)
- **Aanleiding:** enige git-tag is v1.0.0 (2026-07-22). Dossier-product, nieuwe demo, taal en werkplek staan in CHANGELOG [Unreleased]. Production-smoke via tag/dispatch is todo. Live vs staging-versie is onzeker.
- **Doel:** bewuste production-release ná staging-smoke van BL-001, of documenteren welke commit nu op production draait.
- **Scope:** README/DEPLOYMENT productieversie; tag + `/health`-smoke óf expliciete notitie bij dispatch zonder tag; geen tag vóór BL-001 staging-smoke.
- **Acceptatie:** README/DEPLOYMENT state the live production version; either a new tag with /health production smoke, or an explicit note if production was dispatched without tag. Do not tag until BL-001 staging smoke passes.

### BL-043 — Publieke productfunnel en interesse-CTA

- **Status:** done · **Prioriteit:** medium · **Datum:** 2026-07-30 · **PR:** #56 · **Epic:** E5
- **Doel:** maak van de publieke homepage een conversiegerichte route voor airco-installateurs, zonder de productwerking te versimpelen tot een algemene vragenlijst of onbewezen besparingsclaims.
- **Scope:** probleem→oplossing-opbouw; werkwijze; afzonderlijke voordelen voor installateur en klant; fictieve productweergaven uit dezelfde demoset; klant-/installateur-/hybride uitleg; demo-CTA; FAQ; korte pilot-/interesse-CTA.
- **Interesseflow:** valideert naam, bedrijf, e-mail en optionele telefoon/toelichting; honeypot + IP-rate-limit zonder IP-opslag; zelfstandige `product_interests`-opslag; optionele interne queuemail buiten de `log`-mailer; dagelijkse harde purge na standaard 365 dagen.
- **Acceptatie:** demo blijft primair productbewijs; formulier blijft bruikbaar zonder SMTP; fout/succes is toegankelijk en Nederlandstalig; geen technische klantdata of intake wordt aangemaakt; desktop/mobiele staging-smoke staat als `todo` in de teststatus.
- **Resultaat:** volledige funnel, twee responsieve productweergaven met synthetisch bewijs, werkende interesseopslag/notificatie/purge, env-documentatie en featuretests zijn geleverd. Latere fix: homepage-CTAs niet meer één generieke “dashboard”-knop voor elke `@auth`-sessie (inclusief demogebruiker).

### BL-053 — Mobiele werkplek: acties eerst, info dicht

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-08-09 · **PR:** #70 · **Epic:** E5 · **Band:** O · **Afhankelijk:** BL-037
- **Aanleiding:** de installateurswerkplek is op mobiel één lange kolom; status, AI, woninggegevens en foto’s staan vóór het echte werk.
- **Doel:** zet de volgende actie en open punten bovenaan; houd het werkpad (ruimtes → plekken → opstellingen → afronden) open; klap niet-kritische info standaard dicht.
- **Scope (fase 1):** sticky “Volgende stap” + primaire CTA (telling bij open punten; ook “Plek toevoegen”); alleen `Blocked`/`Review` als open punten (`Unknown` = opbouwen); AI/woninggegevens/foto’s/uitkomst als `<details>` (AI open bij uitzonderingen); sectievolgorde werk vóór info; demobanner, anchors en `<x-demo-guide>` ongemoeid; hash-sprongen openen dichtgeklapte secties.
- **Niet in scope:** tab-IA, desktop-split layout, inhoud schrappen, demomodal-copy.
- **Acceptatie:** op ~390 px zijn sticky CTA en open punten in het eerste scherm; werksecties bereikbaar zonder info-blokken te openen; demo-coach en `#demo-*`-sprongen blijven werken; `composer check` groen.
- **Resultaat:** sticky actiebalk + gefilterde open punten + dichtgeklapte info; democoach en anchors intact; featuretests en BL-052-stringasserts bijgewerkt.

### BL-054 — Sticky CTA = echte handeling

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-08-09 · **PR:** #71 · **Epic:** E5 · **Band:** O · **Afhankelijk:** BL-053
- **Doel:** de sticky knop scrollt/opent altijd een echte handeling, nooit “bekijk open punten”.
- **Resultaat:** `WorkspacePrimaryActionResolver` kiest ruimte/plek/route/klanttaak/goedkeuren/uitkomst; unit- en featuretests bewaken dat “bekijken” verdwijnt.

### BL-055 — Open punten tikken door naar werkblok

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-08-09 · **PR:** #71 · **Epic:** E5 · **Band:** O · **Afhankelijk:** BL-054
- **Doel:** elk open punt is een link naar het juiste werkblok (`#workspace-rooms`, `#demo-placements`, `#connection-{id}`, …).
- **Resultaat:** kaarten tonen de concrete handeling + anker; verbindingen hebben `id="connection-{id}"`.

### BL-056 — Sticky/open punten inkorten + mobiele volgorde

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-08-09 · **PR:** #71 · **Epic:** E5 · **Band:** O · **Afhankelijk:** BL-054
- **Doel:** minder statusruis; klanttaak en afronden in de main-flow na opstellingen.
- **Resultaat:** compacte sticky (samenvatting + knop); max 3 open punten zichtbaar; klanttaak/afronden vóór AI; uitkomst in aside.

### BL-057 — Bewijsfoto’s bij het object

- **Status:** done · **Prioriteit:** medium · **Datum:** 2026-08-09 · **PR:** #71 · **Epic:** E5 · **Band:** O · **Afhankelijk:** BL-049/053
- **Doel:** recente foto’s staan bij ruimte/plek/verbinding, niet alleen in de globale galerij.
- **Resultaat:** `_subject-tools` toont tot 4 thumbnails per subject; globale fotolijst blijft dicht.

### BL-058 — Licht afronden na voorstelgoedkeuring

- **Status:** done · **Prioriteit:** medium · **Datum:** 2026-08-09 · **PR:** #71 · **Epic:** E5 · **Band:** O · **Afhankelijk:** BL-054
- **Doel:** goedkeuren is één duidelijke knop; na akkoord een korte bevestiging + sprong naar uitkomst.
- **Resultaat:** sectie “Voorstel afronden” met korte copy; sticky na goedkeuring wijst naar uitkomst.

### BL-059 — Ruimtes bewerken na aanmaken

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-08-10 · **PR:** #73 · **Epic:** E7 · **Band:** O · **Afhankelijk:** BL-037/054
- **Aanleiding:** installateurs kunnen ruimtematen en gebruik alleen bij aanmaken zetten; open punt “Maten invullen” landt op de ruimtesectie zonder bewerkbaar formulier.
- **Doel:** naam, gebruik en maten van een bestaande ruimte bijwerken; beslisgereedheid herberekent; deep link `#room-{id}` naar de eerste incomplete ruimte.
- **Scope:** `AircoSurveyService::updateRoom`, workspace-route/formulier, resolver-capacity-anchor. Geen verwijderen van ruimtes.
- **Acceptatie:** maten wijzigen werkt; readiness volgt; open punt capacity springt naar `#room-{id}` wanneer maten missen.
- **Resultaat:** bewerkformulier per ruimte; capacity-CTA deep-linkt naar eerste incomplete `#room-{id}`.

### BL-060 — Plaatsingen bewerken na aanmaken

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-08-10 · **PR:** #73 · **Epic:** E8 · **Band:** O · **Afhankelijk:** BL-039/054
- **Aanleiding:** plaatsingsopties zijn na create read-only; correcties vereisen een nieuwe kandidaat.
- **Doel:** label, type, ruimte-koppeling en beschrijving van een bestaande plaatsing bijwerken.
- **Scope:** `AircoSurveyService::updatePlacement`, workspace-route/formulier, `#placement-{id}`. Geen verwijderen.
- **Acceptatie:** bijwerken herberekent readiness; subjectlabel blijft synchroon.
- **Resultaat:** bewerkformulier per plek; dossier-subjectlabel/meta blijft synchroon.

### BL-061 — AI-uitzondering → 1-klik klanttaak

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-08-10 · **PR:** #73 · **Epic:** E7 · **Band:** O · **Afhankelijk:** BL-038/041
- **Aanleiding:** AI-uitzonderingen zijn alleen tekst; de installateur moet handmatig een klanttaakformulier vullen.
- **Doel:** per uitzondering één knop die direct een klanttaak aanmaakt en (waar mogelijk) mailt.
- **Scope:** snelle `customer-tasks/quick`-route op basis van exception-label + decision_area; hergebruik `CreateCustomerContributionRequest` + mailpad.
- **Acceptatie:** één tik vanaf uitzondering → open klantronde; openstaande ronde blokkeert netjes.
- **Resultaat:** knop “Vraag de klant” per AI-uitzondering.

### BL-062 — Open punt / foto → vraag klant

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-08-10 · **PR:** #73 · **Epic:** E7 · **Band:** O · **Afhankelijk:** BL-054/049
- **Aanleiding:** open punten zijn alleen deep links; bij AI-fotovoorstellen ontbreekt “vraag nieuwe foto aan de klant”.
- **Doel:** bij open punten met `RequestContribution` en bij foto-voorstellen één-klik “Vraag de klant”.
- **Scope:** zelfde quick-contribution endpoint; knoppen in open-puntenlijst en `_subject-tools`.
- **Acceptatie:** prompt komt uit blocker/voorsteltekst; type photo waar relevant.
- **Resultaat:** “Vraag de klant” bij open punten; “Vraag nieuwe foto” bij AI-fotovoorstellen.

### BL-052 — Gecontroleerd eenvoudig Nederlands in de app-UI

- **Status:** done · **Prioriteit:** medium · **Datum:** 2026-08-09 · **PR:** #69 · **Epic:** E5 · **Band:** A
- **Aanleiding:** na BL-045 was de homepage eenvoudiger, maar klantwizard, installateurswerkplek, demo-coach, mails en templatevragen bevatten nog jargon en lange zinnen.
- **Doel:** schrijf alle gebruikersgerichte app-teksten in gecontroleerd, eenvoudig Nederlands volgens ASD-STE100 en NEN-ISO 24495-1.
- **Scope:** klantwizard/follow-up, installateursschermen, demo-coach, auth, e-mails, enum-/beslislabels, flashmeldingen en airco-template **v11** (alleen labels/help/foto-instructies). Nieuwe bron: [docs/language.md](language.md). Dev-admin en productdocs buiten scope.
- **Acceptatie:** korte zinnen en gewone woorden; vaste termen uit de woordenlijst; nieuwe intakes gebruiken v10+vragen met v11-taal; bestaande gepinde templates blijven ongewijzigd; `composer check` groen.
- **Niet in scope:** homepage-copy (BL-045/046), code-identifiers, ADR’s, interne comments.
- **Resultaat:** UI/mails/enums herschreven; airco v11 gepubliceerd via seeder; schrijfregels in `docs/language.md`; featuretests bijgewerkt; `composer check` groen.

### BL-045 — Eenvoudige installateurstaal op de productfunnel

- **Status:** done · **Prioriteit:** medium · **Datum:** 2026-07-30 · **PR:** deze PR · **Epic:** E5
- **Aanleiding:** de eerste funnelversie was volledig maar te lang en te technisch voor een installateur die snel wil weten wat het product hem oplevert.
- **Doel:** maak de homepage in enkele seconden begrijpelijk en scanbaar, zonder werking te verbergen of besparingen te verzinnen.
- **Scope:** één herkenbare hoofdbelofte; het praktijkprobleem in gewone taal; drie stappen; korte voordelen voor installateur en klant; productbeelden als bewijs; vier kernvragen; één lage-drempelige pilot-CTA. De dubbele workflowsectie vervalt en de keuze klant/zelf/combineren staat voortaan bij de werkwijze.
- **Resultaat:** de funnel gebruikt korte zinnen en dagelijkse installateurstaal, met de herkadering dat een onnodig voorbezoek soms vooral een dure manier is om één ontbrekende foto te krijgen. Demo, interesseformulier, beveiliging en productgedrag blijven ongewijzigd.

### BL-046 — Brede productbelofte op de productfunnel

- **Status:** done · **Prioriteit:** medium · **Datum:** 2026-07-30 · **PR:** deze PR · **Epic:** E5
- **Aanleiding:** de vereenvoudigde funnel gebruikte de ontbrekende foto zo vaak als voorbeeld dat de Digitale Opname smaller leek dan het product is.
- **Doel:** positioneer de app als complete opname- en beslisondersteuning tussen aanvraag en offerte, zonder terug te vallen op technische producttaal.
- **Scope:** bredere hero, probleemschets, drie stappen, voordelen, productuitleg, demotekst, FAQ en metadata. Foto’s blijven zichtbaar als één invoervorm naast bestaande woninggegevens, klantaanvullingen, installateurswaarnemingen, opstellingen, routes en open punten.
- **Resultaat:** de hoofdlijn is nu aanvraag → complete opname → installateursbesluit. De pagina blijft kort en scanbaar, terwijl foto’s weer één middel zijn in plaats van de volledige productbelofte.

### BL-050 — Productfunnel in JPWebcreation-huisstijl

- **Status:** in_progress · **Prioriteit:** medium · **Epic:** E5 · **Band:** A
- **Aanleiding:** de publieke productfunnel gebruikte nog de cool-grijze Apple-achtige marketingstyling, terwijl jpwebcreation.nl de gewenste warmere huisstijl (groen/amber/paper) al heeft.
- **Doel:** laat kleuren, typografie en knop-/oppervlaktestijl van `/` aansluiten op jpwebcreation.nl, zonder de ingelogde app (BL-032 `brand.*` / tenantkleuren) te wijzigen.
- **Scope:** aparte `marketing.*`-tokens; Inter alleen op de homepage; hero-gradient, amber-CTA’s, coral eyebrows, paper/mist-vlakken en productmocks in dezelfde huisstijl. Copy, sectiestructuur, demo en interesseflow blijven functioneel gelijk.
- **Acceptatie:** desktop/~390 px leesbaar zonder overflow; bestaande demo- en interesse-featuretests groen; ingelogde schermen blijven Apple/tenant-styling gebruiken.

### BL-044 — Hervatbare MySQL-dossiermigratie

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-30 · **PR:** deze PR · **Epic:** E5
- **Aanleiding:** de dossiermigration bestond uit meerdere MySQL-DDL-stappen. MySQL legde de eerste kolommen en tabellen direct vast, maar Laravel registreerde de migration niet nadat een automatisch gegenereerde foreign-keynaam boven de limiet van 64 tekens faalde. Een herstart stopte daardoor al op de bestaande kolom `workflow_mode`.
- **Doel:** herstel staging uitsluitend via reproduceerbare code en voorkom dat dezelfde fout bij een nieuwe database of latere onderbreking terugkomt.
- **Resultaat:** alle kolom- en tabelstappen van de dossiermigration zijn hervatbaar; de bewijsbackfill is idempotent; de twee lange foreign keys hebben expliciete korte namen. Een regressietest voert dezelfde migration tweemaal uit en CI draait iedere PR ook met een verse MySQL 8.4-database, verwijdert daarna bewust alleen de migrationregistratie en bewijst dat hervatten slaagt.

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

- **Status:** done · **Prioriteit:** low · **Datum:** 2026-07-21 · **Ref:** `docs/DEPLOYMENT.md`, `.github/workflows/deploy-production.yml`
- **Parallel:** band **I** (done).
- **Doel:** `deploy-production.yml` getriggerd op tags (`v*`), `PRODUCTION_*`-secrets, eigen `apps/intake-engine-production`-boom en database. Eerste release taggen als `v0.x` en CHANGELOG `[Unreleased]` afsluiten.
- **Resultaat:** `main` blijft automatisch naar `staging.intake-engine.nl` deployen; `v*` of een bewuste handmatige dispatch gebruikt GitHub environment `production` en `PRODUCTION_*`-secrets voor `intake-engine.nl`. Beide omgevingen hebben eigen `.env`, app-key, sessiecookie, database, private storage, cronjobs en releaseboom. Deploypaden en `APP_ENV` worden vóór migraties gecontroleerd; stale runtimecaches worden vóór de eerste Artisan-boot verwijderd. De bestaande stagingdata/media zijn eenmalig naar production gekopieerd en runtimecaches, sessies en queuejobs niet.

### BL-012 — Multi-accountplatform voor installatiebedrijven

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-25 · **PR:** (deze PR) · **Ref:** ADR-0010
- **Parallel:** band **I**, kettingkop vóór BL-030 en BL-031; raakt users, intakes, policies, registratie, queries en storage.
- **Doel:** ieder installatiebedrijf wordt een tenant (`companies`). Een gebruiker hoort bij precies één bedrijf; meerdere medewerkers per bedrijf worden door het model ondersteund. Iedere intake is rechtstreeks aan een bedrijf gekoppeld en alle installateursroutes, metrics, rapporten en private bestanden zijn tenantgebonden.
- **Migratie:** bestaande gebruikers krijgen ieder een eigen bedrijf; hun bestaande intakes worden via `created_by` aan dat bedrijf gekoppeld. Nieuwe registraties maken atomair een bedrijf en eigenaaraccount aan.
- **Kaders:** route-modelbinding is nooit autorisatie; policies én tenantgescope queries blijven verplicht. Klanttokens geven uitsluitend toegang tot één intake en daarmee één bedrijfsstijl. Platformbeheer over tenants heen valt buiten deze slice.
- **Acceptatie:** tests bewijzen dat bedrijf A geen intake, upload, rapport of metrics van bedrijf B kan lezen of wijzigen; medewerkers van hetzelfde bedrijf delen de bedrijfsintakes.

### BL-031 — White-label branding uit installateurslogo

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-25 · **PR:** (deze PR) · **Ref:** BL-012, ADR-0010
- **Parallel:** band **I**, na BL-012.
- **Doel:** een installateur beheert bedrijfsnaam en logo. Na een gevalideerde JPEG/PNG/WebP-upload bepaalt de server een representatieve primaire kleur en leidt daaruit toegankelijke accent-, tekst- en oppervlaktekleuren af.
- **Kaders:** logo en kleuren staan op de tenant en worden privé opgeslagen; transparante/witte achtergrondpixels tellen niet als merkkleur. WCAG-leesbaarheid, veilige standaardkleur en handmatige kleurcorrectie zijn verplicht. Geen externe beeld- of AI-provider.
- **Acceptatie:** installateursapp, klantintake en aanvraag-/dossierweergaven tonen uitsluitend logo en kleuren van de juiste tenant; foutieve of kleurloze logo's vallen veilig terug.

### BL-032 — Modern, strak en Apple-achtig productdesign

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-25 · **PR:** (deze PR) · **Ref:** BL-031
- **Parallel:** band **I**, na BL-031.
- **Doel:** gedeelde layouts, navigatie, formulieren, dashboard en klantwizard krijgen één rustig premium designsysteem: systeemfont, sterke typografische hiërarchie, veel witruimte, neutrale oppervlakken, subtiele scheiding en duidelijke tenantkleur voor acties.
- **Kaders:** nadrukkelijk géén Liquid Glass: geen backdrop blur, translucente kaarten, glanzende gradients of decoratieve glaslagen. Mobiel eerst, 44px touch targets, zichtbare focusringen en voldoende contrast.
- **Acceptatie:** kernschermen zijn responsive en visueel consistent; dynamische tenantbranding overschrijft alleen de gecontroleerde design tokens, niet willekeurige component-CSS.

### BL-029 — Begeleide leidingroute (foto-voor-foto + routesynthese)

- **Status:** dropped · **Prioriteit:** high · **Datum:** 2026-07-30 · **Vervangen door:** ADR-0012 + BL-040
- **Reden:** de resterende UI-scope ging uit van één globale leidingroute vanaf een al gekozen binnenunitpositie. Het besloten model vereist eerst plaatsings-/installatieopties en daarna afzonderlijke koel-, condens- en stroomverbindingen. De oude UI zou de verkeerde productstructuur verharden.
- **Behouden backend-slice:** `pipe_route_sessions`/`pipe_route_segments`, float-confidence-contracten, `StartPipeRouteSession`, `AddPipeRoutePhoto`, `SynthesizePipeRoute`, `ApprovePipeRoute`, Terra/Sol-modeltiering, gating en tests blijven bruikbare bouwstenen.
- **Opvolging:** BL-040 heeft de routesessie aan één concrete verbinding binnen één installatieoptie gekoppeld en de gerichte werkplekflow gebouwd. Er komt geen generieke BL-029-klantwizard.

### BL-030 — Foto-varianten: dossier + AI-analyse (JPEG, tokens/storage)

- **Status:** done · **Prioriteit:** high · **Datum:** 2026-07-30 · **PR:** deze PR · **Epic:** E9 · **Band:** H · **Ref:** `docs/uploads.md`, `docs/ai.md`, ADR-0012
- **Doel:** per upload geen telefoon-posterresolutie op disk; wel genoeg dossierkwaliteit én een aparte, kleinere AI-kopie. Bespaart storage én vision-tokens (~86% op beeldtokens t.o.v. 4k-phone) zonder dossiermateriaal te verliezen.
- **Beslissing (vast):**

  | Variant | Lange zijde | Formaat | Kwaliteit | Gebruik |
  |---------|-------------|---------|-----------|---------|
  | **Dossier** | **2048 px** | JPEG | **82** | Preview, galerij, HTML/PDF, installateur-zoom |
  | **AI-analyse** | **1536 px** | JPEG | **80** | Alleen vision-calls (Terra e.d.) |

  Beide: auto-orient, EXIF/metadata strippen, HEIC/PNG/WebP → JPEG. Uploadlimiet blijft gelden op het binnenkomende bestand vóór conversie. WebP als opslagformaat bewust niet (JPEG = minder foutgevoelig voor Imagick/Dompdf/OpenAI). Uitgewerkt implementatieplan: [`docs/plans/bl-030-dossier-ai-image-variants.md`](plans/bl-030-dossier-ai-image-variants.md).
- **Scope AI:** alle vision-paden via gedeelde `AiImageResolver` — `AnalyzeRoutePhoto`, `SynthesizePipeRoute`-escalatie (Sol alleen relevante analysekopieën), `AssessFuseboxPhotos`, `DerivePhotoAnswers`. Lokale `AssessPhotoUsability` gebruikt de dossiervariant.
- **Datamodel:** `intake_uploads.analysis_path` (+ mime/size/checksum); `path` blijft dossier. `HardDeleteIntake` verwijdert beide bestanden.
- **Config:** `INTAKE_DOSSIER_MAX_LONG_EDGE` / `INTAKE_DOSSIER_JPEG_QUALITY` / `INTAKE_ANALYSIS_MAX_LONG_EDGE` / `INTAKE_ANALYSIS_JPEG_QUALITY` (defaults 2048/82/1536/80); vervangt oude `max_long_edge` / `heic_to_jpeg_quality`.
- **Uitvolgorde:** (1) config + migratie + normalizer twee JPEG’s · (2) Store/FollowUp + HardDelete + tests · (3) `AiImageResolver` + vision-actions · (4) docs/CHANGELOG · (5) latere slice: bij onleesbaar detail één crop/hogere-res van die foto, nooit alle originelen opnieuw.
- **Niet in scope:** client-side resize; backfill-job (lazy op eerste AI-gebruik of dossier-fallback); UI behalve dat previews op 2048 i.p.v. 4k kunnen ogen.
- **Waarom (hoofddoel):** multi-foto verbindingsanalyse (BL-040/041) en overige vision moeten betaalbaar en privacyvriendelijker (geen EXIF) blijven zonder dat de installateur detail in het dossier verliest.
- **Resultaat:** alle nieuwe klant-, vervolg- en installateursfoto's worden twee metadata-vrije JPEG's; beide opslagpaden hebben transactionele cleanup, verwijdering en hard purge. `AiImageResolver` bedient alle vision-acties; de Sol-routeherbeoordeling krijgt alleen maximaal vier relevante analysekopieën. Tests bewaken afmetingen, bytesbron en dubbele verwijdering.

### BL-028 — Dev-admin: staging-inzage in dienststatus en opname-data

- **Status:** done · **Prioriteit:** medium · **Epic:** E5 · **Band:** I
- **Waarom:** op staging moet controleerbaar zijn of de externe APIs werken en welke data er bij een opname binnenkwam, zonder de installateursflow te vervuilen. Dat spaart de producteigenaar handmatig speurwerk via logs/DB bij het verifiëren van de vele staging-todo's in `docs/functional-test-status.md`.
- **Opgeleverd:** routegroep `/dev` met vier panelen — dienststatus (passief, uit `intake_external_facts`/`ai_runs`, geen live calls), opname-inspector (ruwe feiten/AI-runs/antwoorden/uploads/events per intake), AI-runs & activiteitenlog, en een uitgebreid systeem/health-paneel. Omgevings-gated: aan op local/staging, hard 404 op production via `EnsureDevAccess` (`config('devadmin.enabled')`); navigatielink alleen zichtbaar wanneer aan. Beslissing en privacy-afweging: **ADR-0008** (in lijn met ADR-0002/0007). Verificatie: zie `docs/functional-test-status.md`.

### BL-013 — S3 als mediadisk

- **Status:** done · **Prioriteit:** low · **Datum:** 2026-08-24 · **PR:** #78 · **Ref:** `docs/uploads.md`
- **Parallel:** band **I** — parallel met A/D–H; afgestemd met afgeronde BL-008 mediapipeline.
- **Doel:** `MEDIA_DISK=s3` + AWS-vars; bestaande rijen behouden `disk`+`path`. Pas nodig bij storagegroei of vertrek van cPanel.
- **Resultaat:** `league/flysystem-aws-s3-v3` toegevoegd; `s3`-disk privé (visibility); schrijven blijft via `config('filesystems.media')` met disknaam op de rij; lezen/verwijderen/purge gebruiken de opgeslagen disk. Env-sjablonen en DEPLOYMENT/uploads documenteren de AWS-vars zonder secrets. Tests met fake disks bewijzen nieuwe uploads op `MEDIA_DISK` en onaangetaste legacy-rijen.

## Afgerond / vervallen

`done`- en `dropped`-items blijven in de overzichtstabel en detailsecties hierboven staan als geheugen (met datum + PR).

| ID | Datum | Resultaat / PR |
|----|-------|----------------|
| BL-013 | 2026-08-24 | #78 — `MEDIA_DISK=s3` + AWS-env; legacy disk+path intact |
| BL-049 | 2026-07-31 | deze PR — contextgebonden foto’s/notities en bevestigbare fotoconstateringen |
| BL-047 | 2026-07-30 | #60 — gestructureerde adresregistratie, BAG-ketentest en herstelactie |
| BL-044 | 2026-07-30 | deze PR — hervatbare dossiermigration + MySQL-migratiesmoke |
| BL-045 | 2026-07-30 | deze PR — kortere, scanbare funnelcopy voor airco-installateurs |
| BL-030/035–042 | 2026-07-30 | deze PR — dossierkern, beeldvarianten, drie workflows, airco-opstellingen/verbindingen, AI-synthese en uitkomstmetrics |
| BL-029 | 2026-07-30 | oorspronkelijke globale route-UI vervallen; backend behouden en vervolg onder BL-040 |
| BL-010 | 2026-07-21 | deze wijziging — gescheiden staging/production + productionworkflow |
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
