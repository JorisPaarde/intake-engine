# Functionele teststatus

> **Documentversie:** 1.53 · **Laatste update:** 2026-08-11 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Handmatig bijgehouden overzicht van wat functioneel is getest (en wat nog niet).

Bijwerken door wie de test daadwerkelijk heeft uitgevoerd: een menselijke tester **of** een testende agent (bijv. een agent die de app via een browser bedient). Niet invullen op basis van alleen implementatie — er moet echt functioneel getest zijn. Implementerende agents voegen alleen nieuwe `todo`-regels toe voor functionaliteit die zij introduceren.

Laatste testsessie: 2026-08-09 (staging; mobiele werkplek BL-053/054–058 op 390 px via publieke demo + voorbeelddossier)

| Onderdeel | Status | Getest op | Notities |
|-----------|--------|-----------|----------|
| Deploy-pipeline (push -> Actions -> rsync -> activate -> live) | pass | 2026-07-18 | Atomische symlink-swap werkt; PR #14 deploy success |
| Omgevingsscheiding staging/production (BL-010/011) | pass | 2026-07-21 | Publieke DNS, geldig TLS en HTTP→HTTPS 301 gecontroleerd. `intake-engine.nl/health` meldt `environment=production`; `staging.intake-engine.nl/health` meldt `environment=staging`. Beide: eigen app-key, sessiecookie, database, storage, releaseboom en twee cronjobs. Productionkopie behield 16 users/20 intakes; production runtime startte met 0 sessies/0 jobs. Environmentguard blokkeerde een bewust verkeerde target vóór activatie. GitHub productionworkflow zelf nog na merge via tag/dispatch smoke-testen. |
| Productionworkflow via `v*`/handmatige dispatch (BL-010) | todo | - | Na merge: production environment/secrets controleren, handmatige dispatch of release-tag uitvoeren, Actions groen en `/health` opnieuw `production`; staging mag niet wijzigen. |
| /health (app boot + DB-verbinding) | pass | 2026-07-18 | JSON ok; `php_upload` 512M/512M (BL-003) |
| /login rendert | pass | 2026-07-18 | Toont loginformulier |
| Auth-beveiliging dashboard/intakes | pass | 2026-07-18 | Uitgelogd → redirect `/login` (na dismiss 428-interstitial) |
| Dashboard weergave | pass | 2026-07-18 | Bereikbaar na registratie |
| Verbindingsgebonden route + modelescalatie (BL-029/030/040) | todo | - | Met `AI_ROUTE_ANALYSIS_ENABLED=true` + externe testprovider: foto op één concrete verbinding levert `route_analysis`; synthese schrijft terug naar die verbinding; bij lage zekerheid volgt `AI_ROUTE_REVIEW_MODEL` met alleen relevante 1536px-analysekopieën. Nieuw bewijs heropent een goedgekeurde sessie zonder duplicaat. Alleen na DPIA/akkoord met fictieve foto's. |
| Begeleide leidingroute — oude globale UI (BL-029) | n/a | 2026-07-30 scopebesluit | Wordt niet gebouwd: ADR-0009/BL-029 vervangen door ADR-0012/BL-040. Backend blijft; nieuwe UI koppelt routes aan één concrete verbinding binnen een installatieoptie. |
| Gecontroleerd eenvoudig Nederlands in app-UI (BL-052) | todo | - | Na deploy: klantwizard-afrondtekst, create-keuze (klant/zelf), werkplekkoppen (**Open punten**, **Mogelijke plekken**), demo-coach en auth-login in gewone korte zinnen; nieuwe opname gebruikt airco v11-labels (o.a. zon/vrije groep). |
| Mobiele werkplek acties eerst (BL-053) | todo | - | Op ~390 px: sticky **Volgende stap** + CTA in eerste viewport; open punten vóór ruimtes; AI/woning/foto’s/uitkomst dicht tot tikken; democoach `installer_start`/`sample_loaded` en sprongen `#demo-context`/`#demo-proposal` openen secties; desktop blijft bruikbaar. |
| Werkplek echte actie + deep links (BL-054–058) | todo | - | Sticky CTA doet echte handeling (geen “bekijken”); open punt tikt naar `#workspace-rooms`/`#connection-*`; max 3 open punten; klanttaak/afronden na opstellingen; thumbs bij object; na goedkeuren korte bevestiging + uitkomst. Democoach blijft. |
| Ruimtes/plaatsingen bewerken + 1-klik klanttaak (BL-059–062) | todo | - | Op werkplek: bestaande ruimte maten/naam bijwerken; plek label/type bijwerken; capacity-open-punt naar `#room-{id}`; AI-uitzondering en open punt “Vraag de klant”; bij fotovoorstel “Vraag nieuwe foto”; openstaande ronde blokkeert tweede snelle taak. |
| Startkeuze klant / zelf uitvoeren (BL-037) | todo | - | Na deploy: **Zelf uitvoeren** houdt klanttoegang uit en verstuurt geen mail; **Klant laten opnemen** activeert en mailt de begeleide klantlink. |
| Volledig installateur-uitgevoerde opname (BL-037) | todo | - | Twee slaapkamers mobiel en in vrije volgorde vastleggen: ruimtes, contextgebonden foto's/notities en technische conclusies; afronden/offertebasis zonder klantactie. |
| Contextgebonden foto’s en notities (BL-049) | todo | - | Na deploy op desktop en 390 px bij een ruimte, positie en verbinding **Foto maken** en **Technische notitie** doorlopen. Geen losse **Camera en bewijs**/**Vakwaarneming**, onderwerp-, sleutel-, methode- of telefoonkeuze. Met fictieve beelden en toegestane externe AI: voorstel blijft onbevestigd, **Klopt** en **Aanpassen** maken het definitief; lage zekerheid en providerfalen voegen geen dossiernoise toe. Controleer bronlabels, toetsenbord, camera én galerij en dat een routefoto één routesegment blijft. |
| Volledig klant-uitgevoerde opname (BL-038) | todo | - | Klantboodschap “Met uw hulp kunnen we uw airco sneller plaatsen”; twee slaapkamers apart; veilige kamer-, buiten- en meterkasttaken; klant maakt geen configuratie-/routekeuze. |
| Hybride opname + één latere klanttaak (BL-038) | todo | - | Installateur begint zelf zonder link, vraagt later alleen een meterkastfoto; link toont uitsluitend die taak; bijdrage komt in hetzelfde dossier terug. Test ook klantstart → installateur vult zelf aan. |
| Sterke bron-/AI-afleiding zonder losse bevestiging (BL-035/041) | todo | - | BAG, luchtfoto, EP-Online/3DBAG en hoge-confidence conclusie worden met provenance gebruikt zonder bevestigingslijst; conflict of beslissende onzekerheid verschijnt wel als uitzondering en blijft corrigeerbaar. |
| Beslisgereed dossier (BL-036) | todo | - | Taakset kan compleet zijn terwijl stroom nog blokkeert; dossier toont status per gebied en acties Offerte, Prijsindicatie, Aanvulling, Locatiebezoek, Afwijzen; installateur keurt voorstel als geheel. |
| Twee slaapkamers: plaatsingen + installatieopties (BL-039) | todo | - | Gewenste ruimtes zijn niet vooraf units; dossier kan één multi-split en twee single-splits voorstellen met aparte binnen-/buitenposities; klant kiest niet, installateur selecteert/corrigeert. |
| Drie verbindingen per airco-optie (BL-040) | todo | - | Per relevante opstelling: koelleiding, condensroute per binnenunit en stroomroute incl. groep/capaciteit, kabelroute en systeemafhankelijk aansluitpunt; ieder met bewijs/open punten/kostenimpact. |
| Onderbouwd locatiebezoek (BL-036/040) | todo | - | Een onveilig of op afstand onzichtbaar beslissend segment eindigt na gerichte taak terecht in **Locatiebezoek nodig**, zonder eindeloze fotolus of onveilige klantinstructie. |
| Dossiersynthese + uitzonderingsreview (BL-041) | todo | - | Met `AI_DOSSIER_SYNTHESIS_ENABLED=true` en fictief bewijs: beeldgebonden binnen-/buiten-/voedings-/afvoerposities, geldige optie met drie verbindingstypen, uitzonderingen en maximaal drie gerichte taken. Ongeldige referentie/cardinaliteit faalt soft; AI activeert geen klantlink en keurt niets goed. |
| Uitkomstregistratie + nieuwe metrics (BL-042) | todo | - | Leg offerte op afstand, prijsindicatie, bezoek met gecontroleerde redenen, vergeleken voorstel/deltacodes en plaatsing met montageverrassing vast. `/metrics` toont juiste noemers, medianen en reden-/afwijkingsverdeling; geen klantinhoud in pagina/events. |
| Dev-admin `/dev` toegang (BL-028) | todo | - | Op staging: ingelogd → `/dev` bereikbaar, nav-link "Dev" zichtbaar. In productie (of `DEV_ADMIN_ENABLED=false`): `/dev` geeft 404 en nav-link ontbreekt. Uitgelogd → redirect `/login`. |
| Dev-admin dienststatus (BL-028) | todo | - | `/dev` toont per externe dienst enabled/key/base-URL/timeout en laatst-gelukt tijd; geo-diensten met opgeslagen feiten worden groen, ongebruikte grijs, ontbrekende key amber. Geen live calls. |
| Dev-admin opname-inspector (BL-028) | todo | - | `/dev/intakes` zoekt op adres/uuid; detail toont externe feiten (PDOK/BAG), AI-runs, antwoorden, uploads en de activiteiten-tijdlijn van één opname. |
| Dev-admin AI-runs/activiteit/health (BL-028) | todo | - | `/dev/ai-runs` en `/dev/activity` filteren en tonen de zojuist gegenereerde runs/events; `/dev/health` toont DB/queue-diepte/cache/storage/uploads/HEIC/versies. |
| Productmetrics `/metrics` (BL-026) | pass | lokaal 2026-07-20; staging nog todo | Authenticated weergave met periodefilter, zes kerncijfers, uitvalpunten en per-opname-links gecontroleerd; eerste beoordeling met `need_more_info` telt als 0,0% direct genoeg. Desktop en 390 px zonder pagina-overflow; tabel scrolt intern; geen nieuwe browserwarnings/-errors. Na deploy dezelfde smoke volgens `docs/metrics.md`. |
| Opname aanmaken (Airco) | pass | 2026-07-18 | Opgeslagen, detail + klantlink |
| Postcode-eerst adresaanvulling + BAG-verrijking (BL-019/033/047) | todo | knopvariant lokaal pass 2026-07-26; automatische flow nog testen | Op staging exact `2037 GR` + `273` invoeren: formulier vult `Bernadottelaan 273, Haarlem`, dossier bewaart huisnummer 273 afzonderlijk en toont een gematchte BAG-controle met gebouwgegevens. Test daarnaast automatische debounce/annulering, toevoegingskeuze en focus. Simuleer bij één dossier `not_found` en controleer **Adres opnieuw controleren**. |
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
| Airco-template v10 gewenste ruimtes (BL-039) | todo | - | Nieuwe opname pin’t v10; openingsvraag en repeatable-sectie spreken over gewenste ruimtes, niet vooraf gekozen units; extra kamerfoto vraagt om wanden/doorgangen en niet om een door de klant gekozen binnenunitpositie. Historische opnames blijven op hun gepinde versie. |
| Homepage / (producthomepage Fase 3) | pass | 2026-07-18 | “Digitale Opname” producthomepage (geen Laravel-welcome) |
| Publieke productfunnel + interesse-CTA (BL-043) | todo | - | Na deploy desktop en 390 px: probleem → werkwijze → voordelen installateur/klant → productweergaven → FAQ → CTA logisch en zonder overflow doorlopen; toetsenbord/focus en details controleren. Geldig formulier geeft bevestiging en rij zonder IP; ongeldige invoer toont Nederlandse veldfouten; `PRODUCT_INTEREST_MAIL_TO` + SMTP zet één interne mail in de queue; `log`/leeg adres bewaart wel zonder mail. Verlopen testrij wordt door `product-interests:purge` verwijderd. |
| Productfunnel JPWebcreation-huisstijl (BL-050) | todo | - | Na deploy desktop en 390 px: homepage toont warm paper/mist, groene/amber hero, amber primaire CTA’s en coral eyebrows; knoppen en formulier blijven leesbaar; geen overflow; demo-start en interesseformulier werken nog. Ingelogde app mag niet op deze marketingkleuren zijn overgegaan. |
| Registratie /register | pass | 2026-07-18 | Formulier werkt; landt op `/dashboard` |
| E-mailverificatie flow | pass | 2026-07-18 | Geen `/verify-email`-blokkade op staging na register (of niet afgedwongen) |
| Klant-intakepagina /o/{token} (Fase 3) | pass | 2026-07-18 | Wizard end-to-end (8 stappen, 1 binnenunit) — *retest was vóór deploy BL-018; hertest nodig* |
| Vraag-voor-vraag klantflow (BL-018) | todo | - | Na deploy: één vraag per scherm, sectietitel als markering, Volgende/Vorige, conditionele vraag verschijnt pas na relevant antwoord, hervatten op juiste vraag |
| Auto-doorgaan na keuze + Enter (BL-023) | todo | - | Na deploy: single_choice/boolean gaat automatisch door na keuze (Opgeslagen-bevestiging); Enter op tekst/nummer = Volgende; multi_choice/foto/long_text niet; Vorige blijft werken; laatste stap geen auto-afronden |
| Voortgang + ontbreekt-lijst (BL-022) | todo | - | Na deploy: % bereikt 100 bij alleen verplichte vragen klaar (optioneel leeg mag); bij geblokkeerd afronden zijn ontbrekende items klikbaar en tonen “Ruimtes 2” i.p.v. `room-2` |
| Installateur-prefill bij aanmaken (BL-016/v10) | todo | - | Na deploy: bekende aanvraagwaarden staan met bron in het dossier; airco v10 slaat eenduidige installateursprefill over zonder losse klantbevestiging. Een historische gepinde v3-flow blijft de bewerkbare voorzet tonen; prefill alleen start de opname niet. |
| Openingszin voorkomt herhaalvragen (BL-048) | todo | - | Na deploy als installateur een klantopname aanmaken met exact `Ik wil twee airco’s om m’n slaapkamers op zolder te koelen.` en externe tekst-AI uit. De klantlink vraagt niet opnieuw naar koelen, aantal ruimtes, ruimtetype of verdieping en bouwt twee afzonderlijke slaapkamertaken op zolder; zolder wordt geen derde ruimte. Controleer hetzelfde met een vóór de deploy aangemaakte nog open link. |
| Openingszin buitenunitplek (BL-063) | todo | - | Reden `Twee airco’s op slaapkamers om ze koud te krijgen buitenunit kan op dak dakkapel`: geen herhaalvraag naar koelen/aantal/ruimtetype of “waar kan de buitenunit”; wel buitenunitfoto’s. Alleen “op het dak” zonder plat/schuin mag `outdoor_location` nog vragen. |
| Repeatable-prefill ruimtes (BL-016) | todo | - | Na deploy: bij ≥2 binnenunits neemt ruimte 2 `floor_level` over van ruimte 1 als bewerkbare voorzet ("Overgenomen van Ruimtes 1"); pas bij Volgende opgeslagen; ruimte 1 nooit voorgevuld |
| Foto-uploads (Fase 4) | pass | 2026-07-18 | JPEG-upload + preview + “Foto opgeslagen” op ruimtestap |
| Dossier- en AI-beeldvarianten (BL-030) | todo | - | Na deploy: JPEG/PNG/WebP/HEIC levert metadata-vrije JPEG-dossierkopie ≤2048px en analysekopie ≤1536px; preview gebruikt dossier, vision gebruikt analyse; verwijderen/purge wist beide. Controleer ook één historische upload zonder `analysis_path` op veilige fallback. |
| Foto multiselect + galerij (BL-021) | todo | - | Na deploy: meerdere foto's in één keer kiezen; op mobiel camera én galerij (geen geforceerde camera); één mislukte foto blokkeert de rest niet; max_files wordt gehandhaafd |
| HEIC/HEIF foto-upload (BL-008) | todo | - | Na deploy op staging met echte iPhone-foto: HEIC kiezen/maken, upload slaat op als JPEG, preview werkt, geen handmatige conversie nodig |
| Leesbare foto-galerij installateur (BL-024) | todo | - | Na deploy: opname-detail toont vraaglabels + groepen (bv. “Ruimtes 2” / “Foto’s van de ruimte”), geen rauwe `question_key`/`room-2` |
| Afronden + bedankt-scherm (Fase 5) | pass | 2026-07-18 | Na boolean-fix #14: volledige flow (incl. Ja/Nee) → **Bedankt** |
| HTML-rapport + installateur-review (Fase 5) | pass | 2026-07-18 | Rapport-iframe + review `prepare_quote` opgeslagen |
| Relevante dossierbronnen + EP-isolatie (BL-019/048) | todo | - | Na EP-configuratie een geregistreerd adres opnieuw controleren. Installateursdetail toont energielabel, leesbare isolatie-indicatie/energiebehoefte, bouwjaar en echte onzekerheden; de isolatievraag vervalt. Coördinaten, perceelreferentie, gebruiksdoel en volledige BAG-oppervlakte staan niet prominent en de luchtfoto is standaard ingeklapt. Controleer dezelfde selectie in HTML/PDF; zonder label blijft de isolatievraag staan. |
| Gerichte aanvullende informatieronde (BL-027) | todo | - | Review `need_more_info` met tekst + foto-opdracht → klantmail/dezelfde link → klant ziet alleen vervolgitems, rondt af → dossier toont ronde/bron/antwoord/foto en dashboard markeert opnieuw als te beoordelen; test ook handmatige linkfallback bij mailconfig |
| Gericht PDF-document opvragen (BL-027) | pass | lokaal 2026-07-20; staging nog todo | Featuretest: documentopdracht, herstel na ongeldige PDF, upload, afronden, auth-link, forced download en HTML/PDF-dossier groen. Gegenereerde 5-pagina-PDF visueel gecontroleerd: documentkaart met prompt, bestandsnaam, bron en ronde zonder clipping. Live documentstap desktop + 390 px zonder overflow of browserwarnings/-errors; route geeft `application/pdf`, attachment en `nosniff`. Na deploy dezelfde smoke met een echte PDF. |
| AI-samenvatting in rapport (Fase 6) | blocked | 2026-07-18 | Geen “AI-voorstel” — staging `AI_PROVIDER=null` (soft-fail by design) |
| AI-aandachtspunten automatisch + accept/verwijder (BL-007) | todo | lokaal geautomatiseerd pass 2026-07-25; staging nog todo | Geen genereerknop/-endpoint. Eerste afronding en afgeronde aanvullende ronde plannen automatisch analyse; context omvat alle technische dossierbronnen met provenance/confidence en sluit identiteit, adresvelden, opslagpaden en bytes uit. Op staging met `AI_PROVIDER=heuristic`: voorstellen verschijnen zonder klik; accepteren komt in rapport, verwijderen blijft weg; `null` blijft soft-fail. |
| Fotokwaliteit-hint klant + label installateur (BL-007) | todo | - | Donkere/kleine foto in klantflow → niet-blokkerende hint, afronden blijft mogelijk; installateursgalerij toont kwaliteitslabel |
| Externe LLM-provider (BL-006) | todo | - | Alleen ná DPIA + `AI_API_KEY`: `AI_PROVIDER=openai` levert samenvatting/aandachtspunten; controleer dat geen e-mail/telefoon in de payload staat |
| AI-budgetcap voor externe provider | todo | - | Codegereed: `AI_PROVIDER=openai` faalt vóór provider-call als dag/maandcap ontbreekt of bereikt is; `ai_runs` bewaart tokens/beelden/geschatte centen. Na deploy: zet lage stagingcap, bewijs budget-limited soft-fail en `/dev` runtimeflags zonder key. |
| Airco v5/v10 meterkastfoto-afleiding (BL-020) | todo | - | Eerst lokaal met `AI_PROVIDER=fake` + flag: hoge zekerheid legt `free_group_known` met bron vast en slaat de redundante bevestigingsvraag over; dossier blijft corrigeerbaar en foto verwijderen wist afleiding/fact. Onduidelijk beeld geeft één concrete herhaalinstructie. Daarna alleen ná DPIA met fictieve stagingbeelden: providerfout soft-fail en geen beeldbytes/data-URL in logs/DB. |
| Queue-worker (cron) | todo | - | Niet end-to-end bevestigd (geen zichtbaar AI-resultaat) |
| Oude publieke klantwizarddemo (BL-001 vóór herontwerp) | n/a | staging 2026-07-24 | Historisch functioneel bewezen, maar vervangen door de interactieve installateursdemo; zie de sessienotitie hieronder. |
| Interactieve installateursdemo (BL-001) | todo | - | Na deploy als gast: homepage → **Probeer de demo** → welkomstpopup → *Nieuwe opname* met lege postcode/huisnummer (zelf invullen; tipadres zichtbaar; lookup vult straat/plaats) → na opslaan woninggegevens/luchtfoto → rolkeuze i.p.v. mail. Test beide paden (verkorte klantwizard met foto-upload/AI én zelf/werkplek + *Toon voorbeelddossier* + AI-voorstel vernieuwen + klanttaak activeren). Desktop én 390 px, toetsenbord, geen consolefouten, geen klantmail. |
| Homepage-CTAs gast / demosessie / account (BL-001/043) | todo | - | Gast: **Probeer de demo**, **Inloggen**, **Ik wil een pilot**. Tijdens demosessie: overal **Demo beëindigen** (homepage + app-nav, ook na browser-back); op `/` ook **Verder in demo**, geen **Mijn opnames**/Inloggen. Na **Demo beëindigen** weer gast-CTAs. Echt account: **Mijn opnames** naar `/dashboard`, geen demostart. |
| Demo-PDF-aanvraag als lead (BL-051) | todo | - | Op werkplek/dossier: e-mail invullen → demorapport-PDF ontvangen/downloaden; lead in `product_interests` + mail naar `PRODUCT_INTEREST_MAIL_TO`. Bij `MAIL_MAILER=log`: lead + download, geen queue. |
| Demo: gerichte klantweergave zonder mail/PDF | todo | - | Voorgestelde meterkasttaak activeren → knop **Klantweergave** → alleen die taak zichtbaar; geen mail, PDF of notificatie. Foto-AI mag meedraaien. Afronden sluit toegang en toont de aanvulling in hetzelfde dossier. |
| Demo-isolatie en purge (`intakes:purge-demos`) | todo | - | Start twee aparte browsersessies: verschillende tenant/user/intake en onderlinge weigering. Laat één sessie verlopen; hourly purge verwijdert intake, luchtfoto, beide beeldvarianten, tijdelijke user en company terwijl actieve demo blijft bestaan. |

## Legenda

| Status | Betekenis |
|--------|-----------|
| `todo` | Nog niet getest |
| `pass` | Functioneel OK |
| `fail` | Fout gevonden |
| `blocked` | Kan niet getest worden (afhankelijkheid/omgeving) |
| `n/a` | Niet van toepassing voor deze omgeving |

## Ruimte voor details

### Sessie 2026-07-26 (lokaal) — BL-033 oorspronkelijke knopvariant

Geïsoleerde lokale SQLite-omgeving met tijdelijk testaccount. Op de nieuwe-opnamepagina stonden postcode, huisnummer en optionele toevoeging vóór straat/plaats; op desktop in één rij en op smallere schermen via de responsive grid in één kolom. De toenmalige expliciete zoekactie deed geen call tijdens renderen. Een echte PDOK Locatieserver-call met `1012 JS + 1` vulde `Dam 1`, `Amsterdam` en de BAG-adresreferentie en toonde “Adres gevonden en aangevuld.” De gevonden-adressectie bleef bewerkbaar; handmatige invoer bleef bereikbaar. Geen browserconsolefouten. Deze sessie bewijst de latere automatische lookup zonder knop niet; die staat hierboven terecht als `todo`.

### Sessie 2026-07-24 (staging) — publieke demo BL-001

Historische test van de inmiddels vervangen klantwizarddemo; deze pass geldt niet voor de nieuwe interactieve installateursdemo.

Scope: publieke **Start demo** vanaf `https://staging.intake-engine.nl/` tot en met demo-afronding. Uitgevoerd door testende agent via HTTP/Livewire-driver omdat de sessie geen browser-automation surface beschikbaar had (`agent.browsers.list()` leeg). Dit is dus geen mobiele visuele/browser-QA.

**Pass:** `/health` gaf `environment=staging`, DB ok, queue `database`, uploadlimieten 512M/512M en Imagick/HEIC-read true. Guest homepage toonde **Start demo** en de demo-scopecopy. De demo-start POST redirectte naar een gegenereerde `/o/{64}` klantlink. De klantwizard renderde de demo-banner en is functioneel doorlopen tot **Bedankt**: verplichte tekst-/keuze-/booleanvragen zijn via Livewire beantwoord, meerdere verplichte fotostappen kregen een synthetische JPEG via de normale Livewire temporary-upload flow, `complete` eindigde met `completed=true`, voortgang 100%, AI-voorstel, voorgestelde aandachtspunten, volledige-app beperkingen en registratielink.

**Niet gedekt:** echte mobiele browserinteractie, camerakeuze/galerijkeuze, visuele layout/overlap, ingelogde gebruiker ziet geen demoknop, demo-purge, installateursdashboard/dossier/review voor deze demo-opname.

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
