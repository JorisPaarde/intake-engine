# Vragen- en takenengine

> **Documentversie:** 2.14 · **Laatste update:** 2026-09-04 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Status: de templatewizard is **geïmplementeerd t/m airco v14** en werkt als bijdrage-/takenengine binnen één centrale opname. Productmodel en rollen: [product-model.md](product-model.md). UI-taal: [language.md](language.md).

## Doel

Een herbruikbare invoerengine: vragen en concrete tekst-, foto-, document- en controleopdrachten zijn data-gedreven, met validatie, conditionele logica, autosave, hervatten en taakcompleetheid.

De engine is **niet** de technische bron van waarheid. Zij verzamelt bewijs en waarnemingen voor de centrale opname. Airco-objecten zoals ruimtes, plaatsingsopties, installatieopties en drie technische verbindingen horen in het aircodomein, niet als kunstmatige vraag-/antwoordstructuur in templates.

## Huidige opbouw

```
Template (key: airco)
  └── Version (v1, v2, … published)   ← nieuwe intakes pinnen op latest published
        └── Sections (ordered)
              └── Questions (typed)
                    ├── Options (keuzevragen)
                    └── Rules (conditioneel show/require)
```

Bron van template-inhoud in MVP:

1. **PHP/array-config** in repo (`database/data/templates/airco/v1.php`, `v2.php`, …)
2. **Seeder** die gepubliceerde `intake_template_version`s + children schrijft (idempotent per versienummer)
3. Geen visuele formulierbouwer

Runtime leest altijd uit de database (de gepinde versie), nooit rechtstreeks uit views/controllers.

## Rol binnen de centrale opname

| Oude aanname | Geïmplementeerd gedrag |
|--------------|------------------------|
| Iedere opname begint met een klantlink | Installateur kiest klant of zelf uitvoeren; hybride ontstaat wanneer beide bijdragen. Toegang staat alleen aan bij klantwerk. |
| Templatevragen bepalen de dossierstructuur | Dossierobjecten bepalen wat technisch nodig is; taken verzamelen het ontbrekende bewijs. |
| `rooms` herhaalt per vooraf gekozen binnenunit | Airco v10 gebruikt de compatibiliteitskey als aantal **gewenste ruimtes**; de technische configuratie volgt uit kandidaatopstellingen. |
| Eén lineaire klantwizard | Klant krijgt een lineaire starttaakset of uitsluitend gerichte taken; installateur werkt vrij en niet-lineair. |
| `CompletenessChecker` bepaalt “opname klaar” | Checker bepaalt alleen of een taakset klaar is; `DecisionReadinessService` bewaakt acht technische gebieden. |
| Prefill is altijd apart te bevestigen | Eenduidige bronnen en sterke afleidingen mogen volgens serverregels direct worden gebruikt; alleen beslissende uitzonderingen worden voorgelegd. |
| Gerichte vervolgitems bestaan pas na review | `contribution_tasks` gebruikt dezelfde beveiligde vervolgitems ook na een installer-only-start of tijdens hybride werk. |

### Workflowvereisten

- **Klant:** één veilige, concrete opdracht per scherm; geen definitieve unit-, configuratie- of routekeuze.
- **Installateur:** dezelfde opname volledig kunnen vullen zonder actieve klanttoegang; camera-first; vrije volgorde; foto of technische notitie rechtstreeks bij de betreffende ruimte, positie of verbinding. Onderwerp, sleutel, methode en herkomst zijn geen invulvelden.
- **Hybride:** iedere open taak heeft een bedoelde bijdrager; installateur kan een klanttaak overnemen of later één specifieke taak sturen.
- **Brondata:** BAG, PDOK-luchtfoto, EP-Online en 3DBAG worden automatisch vóór klanttaken gebruikt om redundante vragen/opdrachten te voorkomen.
- **Taakselectie:** een nieuwe vraag of foto-opdracht is alleen gerechtvaardigd wanneer het antwoord een plaatsing, verbinding, kostenrisico, veiligheid of offertebesluit kan veranderen.

## Nieuwe opname: postcode-eerst adresaanvulling (BL-033)

- De installateur vult eerst postcode en huisnummer in (toevoeging mag in het huisnummer, bijv. `12A`). Zodra postcode en huisnummer geldig en compleet zijn, start de lookup automatisch na een korte debounce; er is geen zoekknop.
- Het authenticated adresendpoint valideert en normaliseert de invoer en gebruikt PDOK Locatieserver. Alleen resultaten met exact dezelfde postcode en hetzelfde huisnummer worden teruggegeven; een opgegeven toevoeging moet eveneens exact overeenkomen.
- Eén resultaat vult *Straat en huisnummer* (volledige regel inclusief nummer/toevoeging, bijv. `Bernadottelaan 12A`), plaats en BAG-adresreferentie direct aan. Bij meerdere toevoegingen kiest de installateur het juiste resultaat. Er is geen apart zichtbaar Toevoeging-veld en geen “handmatig invoeren”-wrapper (BL-080).
- Postcode, huisnummer en de gekozen toevoeging worden afzonderlijk in `intakes` bewaard. De zichtbare adresregel is presentatie inclusief huisnummer; BAG-matching leest het huisnummer nooit meer terug uit vrije tekst.
- Wijzigen van postcode of huisnummer wist een eerdere selectie. Straat en plaats blijven altijd bewerkbaar bij geen resultaat, een PDOK-storing of bewust corrigeren.
- De externe call vindt uitsluitend na complete geldige invoer plaats, nooit tijdens het renderen. Nieuwe invoer annuleert een geplande of lopende call; verouderde responses kunnen zichtbare of handmatige invoer niet overschrijven. De bestaande fail-soft BAG/open-dataverrijking na opslaan blijft ongewijzigd.
- Historische adresregels met het bekende patroon `Straat, 273, 273` worden bij migratie alleen bij een exacte dubbele eindwaarde genormaliseerd. Een mislukte of tijdelijk onbeschikbare adrescontrole kan de installateur vanuit hetzelfde dossier opnieuw uitvoeren.
- **Geen multi-veld installateur-prefill op create (BL-093):** alleen optioneel tekstveld **Wat vroeg de klant?** (`prefill[request_reason]`) — invullen door de installateur bij het aanmaken; daarna klantlink of zelf verder. De app (`DeriveIntentFromRequest`) haalt daaruit wat zij kan invullen; overige vragen volgen in wizard/werkplek. Backend kan nog andere `prefill`-keys in de create-POST accepteren (tests); die staan niet in de UI.

Het interne dossier en HTML/PDF-rapport bevatten altijd een korte deterministische samenvatting van bekende kernantwoorden. Deze gebruikt labels uit de gepinde templateversie en heeft geen AI-provider nodig; een eventuele AI-samenvatting blijft een apart, niet-bindend voorstel.

De rapportpreview toont daarnaast alle werkelijk aangeleverde intake- en vervolgfoto's en gerichte PDF-documenten met vraaglabel, oorspronkelijke bestandsnaam, bron en eventuele aanvullingsronde. De opgeslagen HTML verwijst naar geautoriseerde private-media-routes; de PDF-generator embedt alleen beelden tijdens rendering en vermeldt documenten als beveiligde dossierlink, zodat bestandsbytes niet dubbel in de database belanden.

## Vraagtypen

| Type | UI | Waarde-opslag (JSON) |
|------|----|----------------------|
| `short_text` | input | `{"text":"..."}` |
| `long_text` | textarea | `{"text":"..."}` |
| `number` | number input | `{"number":12.5}` |
| `single_choice` | radio/select | `{"value":"cool"}` |
| `multi_choice` | checkboxes | `{"values":["a","b"]}` |
| `boolean` | ja/nee | `{"bool":true}` |
| `photo` | camera + file picker | via `intake_uploads` (antwoord kan `{"upload_ids":[…]}` cachen) |

## Secties

- Geordend (`sort_order`)
- Huidige klantflow: **één zichtbare vraag per scherm** (BL-018); sectietitel blijft als hoofdstukmarkering zichtbaar
- `is_repeatable`: bv. “Ruimtes” herhaalt zich N keer op basis van `repeat_count_question_key`
- Airco v10: **Ruimtes** herhaalt per gewenste ruimte. De legacy-key `indoor_unit_count` blijft alleen als compatibiliteitsanker; klantlabel en betekenis zijn “aantal gewenste ruimtes”. **Buitenunit** en **Leidingroute** blijven één begeleide brontaakset; de installateurswerkplek modelleert daarna nul of meer echte plaatsingen/routes.
- Bij meer dan één gewenste ruimte voegt de compleetheidscontrole het deterministische installateursaandachtspunt `review_split_configuration` toe: vergelijk één multi-split met meerdere single-splits. Dit is bewust geen klantvraag en geen automatische technische keuze.

`section_instance_key` op antwoorden/uploads: `null` voor normale secties, `room-1` … `room-n` voor herhalingen.

## Navigatie in de klantwizard (BL-018 / BL-023)

- **Autosave** per antwoord; hervatten via cursor (`current_question_key` / `current_section_instance_key`).
- **Auto-doorgaan (BL-023):** na een keuze op `single_choice` of `boolean` gaat de wizard automatisch door naar de volgende zichtbare vraag (korte bevestiging “Opgeslagen”). Niet op de laatste stap (daar blijft **Afronden** handmatig). **Vorige** blijft altijd beschikbaar.
- **Enter = Volgende** op `short_text` en `number` (niet op `long_text` — daar is Enter een nieuwe regel).
- **Geen** auto-doorgaan bij `multi_choice`, foto’s of `long_text`.
- Conditionele vragen: eerst `realignToActiveStep()` (live visibility), daarna pas eventueel auto-doorgaan — een nét verschenen vervolgvraag wordt niet overgeslagen. `next()` blijft de poort voor verplichte-veldcontrole.

## Regels (conditioneel)

Evaluatie is **deterministisch** in een service (`EvaluateQuestionRules` / `VisibilityResolver`):

- Input: huidige antwoorden + rule-set van de versie
- Output per vraag: `visible`, `required` (effectieve verplichting)

Voorbeeld: foto condensafvoer alleen tonen/verplichten als afvoerlocatie ≠ “onbekend”.

Geen LLM in deze keten.

## Validatie

1. **Client:** UX-hints (type, required) — nooit enige bron
2. **Server Form Request / Action:** type, required (effectief), min/max, option membership, upload MIME/size/count
3. `validation_rules` + `meta` op de vraag sturen de servervalidatie

## Voortgang

- `ProgressCalculator` (BL-022): percentage over **verplichte** zichtbare vragen/foto’s in de gepinde versie — optionele onbeantwoorde vragen tellen niet mee, zodat 100% ≈ klaar om af te ronden
- `progress_percent` op `intakes` wordt bij elke save bijgewerkt (cache)
- UI toont: huidige stap, percentage; bij geblokkeerd afronden een klikbare “Nog niet alles is ingevuld”-lijst

## Compleetheidsberekening

Service: `CompletenessChecker`

Deze service bewaakt of alle verplichte zichtbare vragen/foto's van een template of vervolgronde zijn uitgevoerd. Zij berekent uitsluitend **taakcompleetheid**. Koelleiding, condens, stroom en offertebasis worden apart door `DecisionReadinessService` beoordeeld.

Controleert:

- verplichte zichtbare vragen zonder geldig antwoord
- verplichte foto-opdrachten zonder voldoende uploads
- niet-afgeronde repeatable-instanties
- conditioneel verplichte velden

Resultaat:

```json
{
  "is_complete": false,
  "missing": [
    {
      "question_key": "room_photos",
      "section_instance_key": "room-2",
      "reason": "required_photo",
      "label": "Foto's van de ruimte",
      "instance_label": "Ruimtes 2"
    }
  ],
  "attention_points": [
    {"code": "no_free_group", "label": "Geen vrije groep bekend"}
  ]
}
```

In de klantwizard (BL-022) zijn ontbrekende items klikbaar (`goToMissing` → `goToStep`); `instance_label` gebruikt hetzelfde leesbare patroon als de wizard-sectietitel (“Ruimtes 2”), niet de rauwe key.

De hoofdwizard (`CompleteIntake`) weigert als `is_complete === false`. Afronding sluit alleen de toegewezen taakset; open technische beslisgebieden blijven toegestaan en zichtbaar in de installateurswerkplek.

Bij afronding: `completeness_snapshot` + `generated_reports` momentopname.

## Versionering

Zie ADR-0001 en `docs/database.md`.

- Nieuwe opname → laatste `published` versie van gekozen template
- Templatewijziging → nieuwe versie publiceren; lopende/afgeronde intakes blijven op oude versie
- Draft-versies zijn alleen intern/seed-tijd bruikbaar

## Autosave & hervatten

- Elke stap/antwoord-save is idempotent upsert op `intake_answers`
- Upload en antwoord zijn aparte requests; mislukte upload mag eerdere antwoorden niet wissen
- Zelfde klantlink hervat op `current_section_key` + `current_question_key` (+ `current_section_instance_key` bij repeatables)
- Duidelijke “opgeslagen”-feedback in UI

## Huidige airco-template

Secties (stabiele keys over versies):

1. `request` — aanvraag (reden, koelen/verwarmen, gewenste ruimtes, merk, planning)
2. `building` — woning/pand
3. `rooms` — repeatable per gewenste ruimte
4. `outdoor_unit` — beelden van relevante buitenruimte
5. `pipe_route` — eerste routebeelden
6. `electrical` — meterkast / groep
7. `condensate` — condensafvoer
8. `closing` — opmerkingen, waarheidsverklaring, toestemming

### v1 → v2 (BL-017, toenmalige vragenreductie)

V2 introduceerde onderstaande vraagreductie. Nieuwe intakes gebruiken inmiddels de laatste gepubliceerde **v14**; lopende/afgeronde opnames blijven op hun gepinde versie (ADR-0001). V10 verandert klanttaal en repeatable-semantiek naar gewenste ruimtes en voorkomt dat de klant een binnenunitpositie kiest; de technische single-/multi-splitkeuze staat in airco-objecten. V11 houdt die structuur en vernieuwt alleen de klantteksten naar gecontroleerd eenvoudig Nederlands. V12 herstelt offerte-kritische bewijsfoto’s (meterkast, rondom het huis) en foto-afgeleide fase/stopcontacten zonder een stapel ja/nee-vragen (BL-074). V13 zet de meterkastfoto strikt vóór `free_group_known`: geen losse vrije-groepvraag zonder foto, en geen ja/nee wanneer AI `free_group` al uit de foto haalde (BL-077). V14 kort alleen kruipruimte- en L×B×H-labels/help in (BL-082).

| Wijziging | Was (v1) | Wordt (v2) |
|-----------|----------|------------|
| Kamermaten | 3 verplichte getallen (`room_length_m`, `room_width_m`, `ceiling_height_m`) | 1 keuze `room_size_indication` (klein/gemiddeld/groot); exacte maten later uit foto’s (BL-020) |
| Verdieping | vrije tekst `floor_level` | keuzelijst |
| Buitenlocatie / bereikbaarheid / route / condens | vrije tekst | keuzelijsten |
| Afstanden | 3 losse vragen (`distance_to_indoor`, `pipe_distance_indication`, `fusebox_distance`) | 1 optionele bandkeuze `pipe_distance_indication` |
| Geveloverzicht | verplichte `facade_overview_photo` | optioneel (satellietbeeld: BL-019) |
| Vrije groep | verplichte `free_group_known` | optioneel t/m v12; **v13:** alleen ná gevulde `fusebox_photo`, verplicht als zichtbare fallback; vervalt bij AI-prefill |

Keys van geschrapte v1-vragen bestaan niet in v2; hergebruikte keys behouden hun betekenis binnen de versie.

## Prefill van bekende gegevens (BL-016)

Bekende aanvraag- en brongegevens en sterke afleidingen worden zonder apart overzicht van bevestigingsvelden in het dossier gebruikt. Alleen een relevant conflict of beslissende onzekerheid wordt voorgelegd. `prefill_source` blijft nodig voor herkomst en voor gepinde historische templates.

Drie bronnen, gestuurd door vraag-`meta`:

| `meta`-vlag | Bron | Gedrag |
|-------------|------|--------|
| `installer_prefillable: true` | Create-UI toont alleen `request_reason` als **Wat vroeg de klant?** (BL-093; installateur vult in vóór klantlink). Overige keys blijven server-side mogelijk via POST `prefill[...]` (tests/demo); geen multi-veld create-formulier meer. | Opgeslagen met `prefill_source=installer`. Airco v10 voegt `installer` aan `skip_when_prefilled_by` toe; oudere gepinde versies tonen de waarde nog bewerkbaar. Prefill zet de opname niet op `in_progress`. |
| `prefill_from_previous: true` | Binnen een repeatable sectie: het antwoord van de dichtstbijzijnde vorige instantie. | `IntakePrefillResolver` levert een voorzet voor de actieve stap zolang die instantie nog leeg is. Pas bij "Volgende" wordt het als eigen antwoord opgeslagen (`prefill_source` blijft `null`). |
| `text_analysis: request_intent` | Een begrensde lokale parser leest evidente feiten uit de openingszin; optionele externe analyse is alleen fallback. | Hoge zekerheid wordt opgeslagen met `prefill_source=request_text`. De stepbuilder behandelt die bron als sterke tekstafleiding en laat de al beantwoorde vraag ook in bestaande gepinde v9/v10-opnames vervallen. |

Zodra de aanvrager een zichtbaar voorzetveld zelf wijzigt of eroverheen navigeert, vervalt `prefill_source`. De deterministische `show`/`require`-regels blijven de enige poort voor verplichte velden.

Airco: v3 vlagt `request`-vragen als `installer_prefillable` en `rooms.floor_level` als `prefill_from_previous`; v10 slaat eenduidige installateursprefill over.

## Externe feiten en vraagreductie (BL-019)

Deze bronketen is **al geïmplementeerd** en blijft de automatische basis van iedere opname; zij is geen toekomstige productwens.

PDOK Locatieserver vult bij het aanmaken straat, postcode en plaats vanuit één adresselectie. Daarna haalt `EnrichIntakeAddress` het BAG-verblijfsobject en het gekoppelde pand op. De actie is fail-soft: time-out, geen exacte match of een gemanipuleerde lookup-id blokkeert de intake nooit.

Automatische waarden worden in `intake_external_facts` opgeslagen met bron, referentie/URL, zekerheid en ophaaltijdstip (ADR-0007). De eerste set bevat adrescontrole, coördinaten/gemeente/provincie, gebruiksoppervlakte, gebruiksdoel, perceelreferentie en — bij exact één gekoppeld pand — bouwjaar. De volledige set blijft beschikbaar voor audit en dev-admin, maar de gewone installateursweergave en het rapport tonen alleen gegevens die een installatiebesluit ondersteunen: energielabel/isolatie, bouwjaar, relevante 3D-context en meterkastbeoordeling. Coördinaten, perceelreferentie, gebruiksdoel en volledige BAG-gebruiksoppervlakte worden niet als hoofdinhoud getoond.

Als BAG coördinaten levert, vraagt `PdokAerialImageService` server-side een actuele `Actueel_orthoHR` JPEG op via PDOK Luchtfoto RGB WMS (`EPSG:3857`, standaard circa 180 × 120 meter). Het bestand wordt gevalideerd, op de private `MEDIA_DISK` bewaard en als gemarkeerd bovenaanzicht in installateursdetail, HTML en PDF opgenomen. In het installateursdetail staat het beeld ingeklapt, zodat woningfeiten eerst scanbaar blijven. De browser maakt geen directe WMS-call. Alleen een echte WMS-fout schrijft een onzekerheid; de algemene beperking van een bovenaanzicht wordt niet bij ieder geslaagd dossier als actiepunt herhaald. Hard purge verwijdert het bestand.

**Vraagbesluit:** de luchtfoto vervangt geen klantfoto. Zij geeft dak, perceel en omgeving als snelle installateurscontext, maar geen betrouwbare actuele gevel, leidingroute, obstakels of montagehoogte. Google Street View is géén vervanging (voorgevel-only, ToS, achtergevel/tuin meestal ontbreekt). Vanaf airco **v12** is `around_house_photos` daarom weer **verplicht** in het standaardpad; de concrete buitenunit-/routefoto’s blijven aanvullend.

Vraagreductie blijft template-gestuurd:

| `meta`-vlag | Gedrag |
|-------------|--------|
| `skip_when_prefilled_by: pdok` | De wizard laat de vraag weg als voor dezelfde vraag een antwoord met `prefill_source=pdok` bestaat. Zonder eenduidig bronresultaat blijft de normale vraag zichtbaar. |

Airco v4 gebruikte dit alleen voor `build_year`: BAG registreert dit direct op het eenduidig gekoppelde pand. v6 breidt het uit naar `building_type`, maar alleen voor het eenduidige geval — bevat het gebruiksdoel geen enkele `woonfunctie`, dan is `commercial` een feit. BAG onderscheidt appartement, tussenwoning, hoekwoning en vrijstaand níét, dus bij elke woonfunctie blijft de vraag gewoon staan: een fout voorzet kost de installateur meer dan één extra vraag.

In normale opnames wordt `EnrichIntakeAddress` direct na `IntakeController::store` aangeroepen; automatisch opgehaalde feiten gaan met bron en zekerheid het dossier in. De publieke demo volgt hetzelfde create-pad: na postcode/huisnummer draait live BAG/PDOK/luchtfoto-verrijking. Het optionele voorbeelddossier injecteert ruimtes, foto’s en AI-voorbeeldcontent; een synthetische luchtfoto wordt alleen toegevoegd wanneer er nog geen live `PDOK Luchtfoto RGB`-capture is, zodat het getypeerde adres zichtbaar blijft (BL-075).

## Foto-afleiding en uitzonderingen (BL-020/041)

Onderstaande zekerheidsladder beschrijft de wizardintegratie. `medium` blijft een uitzondering/controlepunt; `high` wordt rechtstreeks als herleidbare dossierconclusie gebruikt zonder een los bevestigingsscherm.

Airco v5 markeert `fusebox_photo` met `meta.photo_analysis=fusebox` en maakt de foto-opdracht concreet: groepen, hoofdschakelaar en vrije posities recht van voren en leesbaar. De normale, optionele `free_group_known`-vraag blijft de fallback.

**v6 maakt dit generiek.** `meta.photo_analysis` verwijst naar een profiel uit `PhotoDerivationProfile::all()`; `DerivePhotoAnswers` draait dat profiel op de foto's van één vraag (en één sectie-instantie, dus per ruimte apart). Een profiel benoemt welke vragen het mag beantwoorden en met welke optiewaarden — een waarde buiten de template wordt afgekeurd, niet opgeslagen. Publiceren met een onbekende profielnaam faalt meteen, zodat een typefout niet stilletjes niets aflevert.

Zekerheid bepaalt hoeveel werk de aanvrager overhoudt:

| `confidence` | `prefill_source` | Gevolg in de wizard |
|---|---|---|
| `high` | `ai` | De vraag vervalt (`skip_when_prefilled_by: ai`); het bewijs blijft zichtbaar in het dossier |
| `medium` | `ai_suggestion` | De vraag blijft staan, ingevuld als bevestigbare voorzet |
| `low` | — | Niets opgeslagen; de vraag wordt normaal gesteld |

Bestaande antwoorden van aanvrager of installateur worden nooit overschreven, en het weghalen van een foto wist elke conclusie die eruit volgde.

Profielen in v7:

| Profiel | Fotovraag | Leidt af |
|---|---|---|
| `room` | `room_photos` | ruimtetype, grootte, zonbelasting, glasoppervlak, stopcontactzichtbaarheid (`room_outlet_status` → eventueel extra `wall_outlet_photo`) |
| `outdoor` | `outdoor_location_photos` | plek, ondergrond, bereikbaarheid |
| `pipe_route` | `pipe_route_photos` | route, afstandsindicatie, boringen nodig |
| `fusebox` | `fusebox_photo` (+ optioneel `fusebox_photo_extra`) | vrije groep, fase (eigen actie; geen losse 1-/3-fasevraag) |

Elke sectie opent nu met zijn foto. In v6 stonden `room_type` en `outdoor_location` nog vóór hun foto en konden daardoor per definitie niet vervallen.

Booleanvragen worden afgeleid via `yes`/`no` op de wire en pas bij opslag omgezet naar een echte boolean, zodat het model nooit over JSON-types hoeft te redeneren.

### Wat bewust blijft staan

Niet alles hoort weg te vallen, ook niet als het "korter" kan:

- `ownership`, `pipe_visibility`, `noise_sensitive` — juridische status en voorkeuren staan niet op een foto.
- `floor_level` — een binnenfoto laat de verdieping niet betrouwbaar zien.
- `truth_confirmation` en `privacy_consent` blijven twee losse vragen. Toestemming moet specifiek en ongebundeld zijn; samenvoegen met een juistheidsverklaring maakt haar niet-vrij. Eén stap winst weegt daar niet tegenop.
- `insulation_indication` / `floor_insulation` blijven vragen zolang EP-Online geen label levert; met label vervallen ze (`skip_when_prefilled_by: epo`, fail-soft zonder key).
- `crawl_space_present` (v12) — veilige ja/nee/weet-niet-waarneming; geen kruipinstructies.

## Airco v12 — offerte-kritisch bewijs (BL-074)

Na de eerste echte installateurstrial (Jamie Elderenbos, 28 aug 2026) herstelt v12 bewijs dat de offerte raakt zonder twintig extra ja/nee-klantvragen:

| Onderwerp | Aanpak |
|-----------|--------|
| Meterkast | Verplichte `fusebox_photo` eerst; bij lage zekerheid of onbekende fase volgt `fusebox_photo_extra`; `free_group_known` alleen als AI free_group niet kon afleiden (nooit vóór de foto) |
| 1-/3-fase | Alleen uit `AssessFuseboxPhotos` (`one_phase`/`three_phase`); geen losse fasevraag |
| Stopcontacten | Uit ruimtefoto (`room_outlet_status`); anders verplichte `wall_outlet_photo` |
| Rondom het huis | Verplichte `around_house_photos` (gevel/tuin/montageplek); geen Street View/luchtfoto-vervanging |
| Kruipruimte | Optionele `crawl_space_present` (klant of installateur) |
| Vloerisolatie | `floor_insulation`; overgeslagen bij EP-Online-prefill |
| Kamer L×B×H | Optionele meters + zichtbaar op de werkplek; geen verplichte meetspam |

## Twee BAG-routes: Kadaster met PDOK als vangnet

De adres-autocomplete in het installateursformulier blijft altijd de open PDOK Locatieserver — Individuele Bevragingen is geen geocoder. Voor de *kenmerken* van het gekozen adres probeert `PdokAddressService` eerst de [BAG API Individuele Bevragingen](https://www.kadaster.nl/zakelijk/producten/adressen-en-gebouwen/bag-api-individuele-bevragingen) van Kadaster:

| | Kadaster Individuele Bevragingen | PDOK BAG OGC (vangnet) |
|---|---|---|
| Bevraging | exact op postcode + huisnummer | gestructureerde postcode + huisnummer + toevoeging; Locatieserver levert het BAG-object |
| Actualiteit | near-realtime uit de LVBAG | periodiek ververst extract |
| Auth | `X-Api-Key` | geen |
| Limieten | gebruikslimieten, niet voor bulk | geen |

Zonder key, bij een storing of bij een niet-eenduidig antwoord valt de verrijking stil terug op de PDOK-route — dezelfde `AddressEnrichment`, dus de rest van de keten merkt er niets van. `BAG_API_ENABLED=false` is de standaard.

Twee dingen komen ook op het Kadaster-pad van PDOK: **coördinaten** (Kadaster levert geometrie in RD/EPSG:28992, het dossier en de luchtfoto rekenen op WGS84) en **gemeente/provincie**.

`oorspronkelijkBouwjaar` is bij Kadaster een array — één jaar per pand waar het verblijfsobject deel van uitmaakt. Alleen een eenduidig jaar wordt als voorzet overgenomen; bij panden met verschillende bouwjaren blijft de bouwjaarvraag gewoon staan.

## De openingsvraag telt mee (tekst-afleiding)

"Ik wil twee airco’s om m’n slaapkamers op zolder te koelen" beantwoordt meerdere vragen die de wizard daarna nog stelde: de functie (koelen), het aantal gewenste ruimtes (twee), het type van elke ruimte (tweemaal slaapkamer) en de verdieping (voor beide zolder). “Op zolder” is daarbij de ligging van die slaapkamers, niet een derde ruimte.

`request_reason` heeft daarom `meta.text_analysis = 'request_intent'`. `DeriveIntentFromRequest` draait direct nadat de installateur de opname aanmaakt én wanneer de klant de openingsvraag zelf opslaat. Bij het openen van een oudere actieve klantlink draait één lokale herstelpass voordat de stappen worden gebouwd. De lokale parser herkent alleen een kleine set evidente Nederlandse doelen, aantallen en ruimtetypen; tegenstrijdige aantallen of een onduidelijke functie leveren niets op.

Een evidente lokale conclusie gebruikt `prefill_source=request_text`, laat de redundante vragen vervallen en vereist geen AI-provider. De genoemde ruimtes worden op volgorde aan `room-1`, `room-2`, … gekoppeld; een expliciete zolderligging vult `floor_level=attic` bij iedere afgeleide slaapkamer in. Een zin met “twee airco’s” en een niet-geteld meervoud “slaapkamers” mag twee slaapkamers opleveren; bij een conflict, bijvoorbeeld twee airco’s voor drie genoemde kamers, blijft de normale vraag staan.

Alleen wanneer de lokale parser geen zekere conclusie kan trekken, mag de bestaande versioned prompt (`request-intent-v3`) als fallback draaien. Daarvoor blijft `AI_TEXT_INFERENCE_ENABLED` vereist. Tekst naar een externe provider sturen is een andere afweging dan foto's, dus dat staat los van `AI_PHOTO_INFERENCE_ENABLED`; de herstelpass bij het openen van een klantlink doet nooit stil een externe call. Een toelichting korter dan tien tekens gaat helemaal niet naar een provider.

## Cascades: wat logisch volgt, wordt niet gevraagd

De regelmotor kon dit vanaf het begin; de template gebruikte hem alleen niet. v9 zet er `show`-regels op waar het antwoord al vastligt:

| Vraag | Verschijnt niet wanneer | Waarom |
|---|---|---|
| `outdoor_accessibility` | `outdoor_mount_type` = `ground` | staat de unit op de grond, dan is ladder of steiger niet aan de orde |
| `pipe_distance_indication` | `pipe_route_description` = `short_direct` | een korte directe doorboring ís de korte afstandsklasse |

Let bij het toevoegen van regels op de operator: `readRuleComparable()` leest voor een `single_choice`-bron de sleutel `value`, niet `values`. Een `in`/`not_in` met een lijst blijft daar leeg — gebruik `equals`/`not_equals`.

## Keuzelijsten in plaats van vrije tekst

`brand_preference` is een `multi_choice` met merken en `desired_planning` een `single_choice` met termijnen. Vrije tekst leverde voor beide onbruikbare data op voor een offerte; een keuzelijst geeft de installateur iets om op te filteren.

## Energielabel uit EP-Online

[EP-Online](https://www.rvo.nl/onderwerpen/wetten-en-regels-gebouwen/ep-online) van RVO is het landelijke register van geregistreerde energielabels. Bevraagd op het BAG-verblijfsobject-id dat de adresverrijking toch al oplevert (`/api/v5/PandEnergielabel/AdresseerbaarObject/{id}`), dus zonder opnieuw op adres te matchen. Key via `epbdwebservices.rvo.nl`, meegestuurd als `Authorization`-header.

Het neemt twee vragen over:

| Vraag | Uit | Waarom dit mag |
|---|---|---|
| `insulation_indication` | `Energiebehoefte` | geregistreerd ná eventuele renovaties |
| `building_type` | `Gebouwtype` | het woningtype dat de BAG níét kent |

**Isolatie volgt de energiebehoefte, niet de labelletter.** Die letter verrekent ook installaties, dus een matig geïsoleerd huis met zonnepanelen scoort een A terwijl de warmtevraag hoog blijft. `Energiebehoefte` (NTA 8800) is juist de vraag vóór installaties en dus de maat voor wat een airco moet leveren. Grenzen: ≤50 `good`, ≤100 `average`, daarboven `poor`. Oudere en vereenvoudigde labels hebben dat getal niet; die vallen terug op de letter.

**Bouwtype alleen bij herkenning.** EP-Online legt de waarden van `Gebouwtype` niet vast in de OpenAPI-spec, dus de omschrijving wordt op herkenbare woorden gematcht ("vrijstaand", "hoek", "tussen", "galerij"). Herkennen we hem niet, dan blijft de vraag staan in plaats van dat we gokken. `Gebouwklasse` "U" gaat rechtstreeks naar `commercial`.

Beide onderbouwingen — labelletter én kWh/m²·jr — komen als feit in het dossier met bron en registratiedatum, zodat een afgeleid antwoord navolgbaar blijft. Heeft een adres geen label, dan blijven beide vragen gewoon staan; registratie is verplicht bij verkoop, verhuur en oplevering, dus de dekking is hoog maar niet volledig.

De gewone dossierweergave vertaalt het afgeleide antwoord naar **Isolatie-indicatie: Goed, Gemiddeld of Matig** en toont de energiebehoefte ernaast wanneer die beschikbaar is. De integratie is fail-soft en draait alleen met `EP_ONLINE_ENABLED=true` én een geldige key in de omgeving; zonder configuratie of zonder label blijft `insulation_indication` een normale klantvraag. Na het activeren kan **Adres opnieuw controleren** een bestaand dossier opnieuw via BAG en EP-Online verrijken.

Omdat `building_type` nu uit twee registers kan komen, accepteert `meta.skip_when_prefilled_by` sinds v8 ook een lijst bronnen.

## Woningtype uit BAG-pandgeometrie

Ontbreekt een herkenbaar EP-Online-woningtype, dan gebruikt `PdokBuildingContextService` het exacte BAG-pand en een kleine ruimtelijke buurtquery in RD (EPSG:28992). De engine telt verblijfsobjecten die via `pand.href` aan hetzelfde pand zijn gekoppeld en vergelijkt gedeelde pandgrenzen:

| BAG-context | Afgeleid templateantwoord |
|---|---|
| meerdere verblijfsobjecten in één pand | `apartment` |
| één verblijfsobject, geen aansluiting | `detached` |
| geïsoleerd paar aansluitende panden | `semi_detached` |
| uiteinde van een langere pandketen | `corner` |
| aansluitingen aan tegenoverliggende zijden | `terraced` |

De classificatie is bewust fail-closed. Een ongeldige contour, nul verblijfsobjecten, een afgekorte buurtquery of een niet-eenduidige aansluitingsvorm levert geen antwoord op. EP-Online en ieder bestaand klant-/installateurantwoord worden nooit overschreven. Bij hoge zekerheid bewaart `building_type_inference` de conclusie, reden, BAG-pandreferentie en het aantal verblijfsobjecten; het antwoord gebruikt `prefill_source=pdok`, waardoor gepinde v8–v10-templates de redundante vraag kunnen overslaan.

De luchtfoto is hierbij alleen visuele dossiercontext. Pixels worden niet als bron van waarheid gebruikt; exacte BAG-contouren zijn controleerbaar en betrouwbaarder.

## Pandgeometrie uit de 3DBAG

Naast PDOK/BAG haalt `EnrichIntakeAddress` dakvorm en gevelhoogte op bij de [3DBAG](https://3dbag.nl) van TU Delft, op basis van het pand-id dat de BAG-verrijking al heeft opgeleverd. De data staat onder **CC BY 4.0**: opslaan en tonen in het dossier mag, mits de bron vermeld blijft — anders dan bij Google Street View, waar het vooraf ophalen, opslaan of cachen van beeld verboden is en embedden in een gegenereerde PDF dus niet kan.

| Fact | Bron-attribuut | Nut |
|---|---|---|
| `building_height_m` | `b3_h_dak_max` − `b3_h_maaiveld` | ladder of steiger bij de buitenunit |
| `roof_type` | `b3_dak_type` | plat of schuin dak |
| `floor_count` | `b3_bouwlagen` | context bij de verdiepingsvraag |

Bewust géén vraagreductie. De hoogte van een pand zegt niet waar de buitenunit komt te hangen, dus hier vervalt geen enkele vraag — dit is context voor de installateur en extra grond voor de AI-aandachtspunten.

`b3_kwaliteitsindicator = false` betekent dat 3DBAG de 3D-reconstructie zelf als mogelijk onjuist markeert. De feiten worden dan nog steeds getoond, maar met lage zekerheid én een expliciete onzekerheid in het dossier — hoogte stuurt de keuze tussen ladder en steiger, dus dat mag de installateur niet ontgaan. Daktypen die geen betekenis hebben (`no points`, `no planes`, `unknown`) worden helemaal weggelaten in plaats van als "onbekend" getoond. Een storing bij 3DBAG blokkeert niets: de BAG-verrijking en de opname lopen gewoon door.

### Effect op het aantal stappen

Gemeten op een opname met één gewenste ruimte, met werkende BAG, energielabel, foto- en tekst-inferentie:

| Versie | Stappen |
|---|---|
| v5 (platte lijst) | 38 |
| v6 (adaptief) | 29 |
| v7 (maximaal afgeleid) | ~20 |
| v8 (met energielabel) | ~19 |
| v9 (openingsvraag + cascades) | 18 |
| v10 (gewenste-ruimtetaal) | 18 |
| v11 (eenvoudige klanttaal) | 18 |

Bij twee gewenste ruimtes komen daar 3 stappen voor de extra ruimte bij (foto's + verdieping), dus 21.

V11 wijzigt geen vragenstructuur of regels; alleen labels, helpteksten en foto-instructies naar gecontroleerd eenvoudig Nederlands (BL-052).

Wat overblijft is de openingsvraag zelf, niet-zichtbare feiten (eigendom, verdieping), voorkeuren, de foto's en de twee afsluitende verklaringen.

De cascades leveren in deze meting niets extra's op: de foto-afleiding had `outdoor_accessibility` en `pipe_distance_indication` al beantwoord. Ze zijn het vangnet voor de situatie waarin AI uit staat of te weinig zekerheid heeft — dan snoeien ze alsnog deterministisch.

Bij expliciet ingeschakelde foto-inferentie beoordeelt `AssessFuseboxPhotos` maximaal twee recente meterkastfoto's. Alleen `free_group=yes|no` met `confidence=high` wordt als `prefill_source=ai` vastgelegd; de redundante vraag vervalt en bron/zekerheid blijven zichtbaar in het dossier. Bestaande klant- of installateurantwoorden worden nooit overschreven. Bij onvoldoende beeld blijft de normale vraag staan en verschijnt de concrete `retake_instruction` bij de foto.

De volledige beperkte uitkomst (vrije groep, 1-/3-fase/unknown, zekerheid, zichtbaar bewijs, provider/model en gebruikte upload-id's) staat als `fusebox_photo_assessment` in de automatisch verzamelde informatie. Dossier, HTML en PDF noemen `AI-fotoanalyse` als bron en zetten de waarneming altijd bij te controleren onzekerheden. Beeldbytes bestaan alleen tijdens het providerrequest; verwijderen van de bronfoto verwijdert de AI-voorzet en het afgeleide feit. Zonder provider/flag werkt de intake ongewijzigd verder.

## Gerichte klantbijdragen (BL-027/038)

1. De installateur kan na een eerste klantflow via `need_more_info` óf rechtstreeks vanuit de technische werkplek 1–5 concrete items toevoegen: `text`, `photo` of `document` (PDF).
2. Vanaf de werkplek kan één concrete taak ook in één tik worden gestuurd (`intakes.workspace.tasks.quick`, BL-061/062): vanuit een AI-uitzondering, een open punt met `RequestContribution`, of “Vraag nieuwe foto” bij een AI-fotovoorstel. Die route hergebruikt `CreateCustomerContributionRequest` + mailpad; een openstaande klantronde blokkeert een tweede snelle taak.
3. `CreateCustomerContributionRequest` maakt naast de genummerde ronde een `contribution_task`, zet de workflow op `hybrid`, activeert klanttoegang en bewaart de status waarnaar de opname terugkeert. `SubmitIntakeReview` gebruikt dezelfde vervolgstructuur voor historische reviewrondes.
4. `IntakeWizard` toont bij `awaiting_customer` uitsluitend de gevraagde items, één per scherm. Bestaande templatevragen worden niet opnieuw getoond.
5. Tekst wordt tussentijds opgeslagen. Foto-items gebruiken dezelfde MIME-controle, HEIC-normalisatie, private disk en uploadlimiet als de gewone wizard. Documentitems accepteren alleen PDF na server-side MIME- en bestandssignatuurcontrole en gebruiken dezelfde private disk.
6. `CompleteFollowUpRound` vereist elk antwoord/minimaal één gevraagd bestand, markeert ronde en taak compleet, zet de opname terug naar de bewaarde status, schakelt klanttoegang uit, synchroniseert het bewijs naar het dossier, herberekent beslisgereedheid, bouwt HTML/PDF opnieuw op en stuurt de bestaande installateursnotificatie.

Rapport en installateurdetail behouden eerdere antwoorden en tonen per aanvulling ronde, vraag, klantantwoord/foto's/documenten en bron. Activity events bevatten alleen ronde, item-id, type en aantallen; nooit vrije tekst of token. Standaardlimieten: 3 rondes, 5 items per ronde, 5 foto's per foto-item en 3 PDF's per documentitem (`INTAKE_FOLLOW_UP_*`).

## Nieuwe intaketemplate toevoegen

1. Configbestand onder `database/data/templates/{key}/v1.php`
2. Seeder of artisan-commando `intake:template-publish {key}`
3. Zet `intake_templates.is_active = true`
4. Tests voor visibility/completeness van die template
5. Documenteer afwijkende secties in dit bestand

Maak geen type-specifieke controller wanneer een templatevraag/-regel volstaat. Heeft een intaketype eigen technische objecten en beslislogica nodig, voeg die binnen een expliciet domein toe volgens `docs/ARCHITECTURE.md`; forceer ze niet in vraag-JSON.

## Uitbreidingspunten (niet MVP)

Gepland werk staat in [docs/backlog.md](backlog.md).

Afgerond: airco-template v2-audit (BL-017); prefill van bekende gegevens (BL-016, zie [§ Prefill](#prefill-van-bekende-gegevens-bl-016)); openbare adres-/gebouw-/luchtfotodata (BL-019); bevestigbare meterkastfoto-afleiding (BL-020); gerichte aanvullende informatierondes (BL-027).

Verder buiten scope tot er vraag naar is:

- Visuele templatebouwer
- Per-bedrijf template-overrides
- Een lead-/kansscan vóór de bestaande aanvraag
- Vrije autonome AI-chat die buiten bijdrageopdrachten om het dossier muteert
