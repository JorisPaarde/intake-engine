# Intake-engine

> **Documentversie:** 1.19 · **Laatste update:** 2026-07-22 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Status: **geïmplementeerd t/m Fase 6 + BL-019 openbare data + BL-020 foto-afleiding + BL-027 gerichte vervolgrondes**. Airco-template **v8** gepubliceerd — v7 + het geregistreerde energielabel neemt isolatie en bouwtype over.

## Doel

Een herbruikbare intake-engine: vragen, validatie, conditionele logica, voortgang en compleetheid zijn data-gedreven. Airco is de eerste template, geen hardcoded airco-app.

## Opbouw

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
- Klantflow: **één zichtbare vraag per scherm** (BL-018); sectietitel blijft als hoofdstukmarkering zichtbaar
- `is_repeatable`: bv. “Ruimtes” herhaalt zich N keer op basis van `repeat_count_question_key` (aantal binnenunits)

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

Afronden (`CompleteIntake`) weigert als `is_complete === false`, tenzij een expliciete template-flag later “afronden met open punten” toestaat (standaard: **niet**).

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

## Airco-template

Secties (stabiele keys over versies):

1. `request` — aanvraag (reden, koelen/verwarmen, units, merk, planning)
2. `building` — woning/pand
3. `rooms` — repeatable per binnenunit
4. `outdoor_unit` — buitenunit
5. `pipe_route` — leidingroute
6. `electrical` — meterkast / groep
7. `condensate` — condensafvoer
8. `closing` — opmerkingen, waarheidsverklaring, toestemming

### v1 → v2 (BL-017, ontwerpprincipe)

V2 introduceerde onderstaande vraagreductie. Nieuwe intakes gebruiken inmiddels de laatste gepubliceerde **v8**; lopende/afgeronde opnames blijven op hun gepinde versie (ADR-0001).

| Wijziging | Was (v1) | Wordt (v2) |
|-----------|----------|------------|
| Kamermaten | 3 verplichte getallen (`room_length_m`, `room_width_m`, `ceiling_height_m`) | 1 keuze `room_size_indication` (klein/gemiddeld/groot); exacte maten later uit foto’s (BL-020) |
| Verdieping | vrije tekst `floor_level` | keuzelijst |
| Buitenlocatie / bereikbaarheid / route / condens | vrije tekst | keuzelijsten |
| Afstanden | 3 losse vragen (`distance_to_indoor`, `pipe_distance_indication`, `fusebox_distance`) | 1 optionele bandkeuze `pipe_distance_indication` |
| Geveloverzicht | verplichte `facade_overview_photo` | optioneel (satellietbeeld: BL-019) |
| Vrije groep | verplichte `free_group_known` | optioneel; meterkastfoto is leidend (afleiding: BL-020) |

Keys van geschrapte v1-vragen bestaan niet in v2; hergebruikte keys behouden hun betekenis binnen de versie.

## Prefill van bekende gegevens (BL-016)

Toepassing van het ontwerpprincipe *"vraag niets wat al bekend is"*: de engine biedt een antwoord dat al bekend is aan als **voorzet**. Een prefill is altijd zichtbaar en bewerkbaar — nooit een verborgen aanname. De aanvrager bevestigt het door het te laten staan en verder te gaan. Deterministisch, **geen LLM** in deze keten.

Twee bronnen, gestuurd door vraag-`meta` (dus template-data, geen code):

| `meta`-vlag | Bron | Gedrag |
|-------------|------|--------|
| `installer_prefillable: true` | De installateur vult de vraag bij het aanmaken alvast in (`CreateIntake`, formulier `installer/intakes/create`). | Opgeslagen als antwoord met `intake_answers.prefill_source = 'installer'`. De klantwizard toont het gemarkeerd als "alvast ingevuld — controleer". Prefill bij het aanmaken zet de intake **niet** op `in_progress`. |
| `prefill_from_previous: true` | Binnen een repeatable sectie: het antwoord van de dichtstbijzijnde vorige instantie. | `IntakePrefillResolver` levert een voorzet voor de actieve stap zolang die instantie nog leeg is. Pas bij "Volgende" wordt het als eigen antwoord opgeslagen (`prefill_source` blijft `null`). |

Zodra de aanvrager het veld zelf wijzigt of eroverheen navigeert, vervalt `prefill_source` (bevestigd). De deterministische `show`/`require`-regels blijven de enige poort voor verplichte velden — een voorzet vult alleen een waarde in, het ontgrendelt niets.

Airco: v3 vlagt `request`-vragen als `installer_prefillable` en `rooms.floor_level` als `prefill_from_previous`.

## Externe feiten en vraagreductie (BL-019)

PDOK Locatieserver vult bij het aanmaken straat, postcode en plaats vanuit één adresselectie. Daarna haalt `EnrichIntakeAddress` het BAG-verblijfsobject en het gekoppelde pand op. De actie is fail-soft: time-out, geen exacte match of een gemanipuleerde lookup-id blokkeert de intake nooit.

Automatische waarden worden in `intake_external_facts` opgeslagen met bron, referentie/URL, zekerheid en ophaaltijdstip (ADR-0007). De eerste set bevat adrescontrole, coördinaten/gemeente/provincie, gebruiksoppervlakte, gebruiksdoel, perceelreferentie en — bij exact één gekoppeld pand — bouwjaar. Rapport/PDF en installateursdetail tonen deze feiten plus expliciete onzekerheden.

Als BAG coördinaten levert, vraagt `PdokAerialImageService` server-side een actuele `Actueel_orthoHR` JPEG op via PDOK Luchtfoto RGB WMS (`EPSG:3857`, standaard circa 180 × 120 meter). Het bestand wordt gevalideerd, op de private `MEDIA_DISK` bewaard en als gemarkeerd bovenaanzicht in installateursdetail, HTML en PDF opgenomen. De browser maakt geen directe WMS-call. WMS-falen schrijft alleen een luchtfoto-onzekerheid en laat de al geslaagde BAG-verrijking intact; hard purge verwijdert het bestand.

**Vraagbesluit:** de luchtfoto vervangt geen klantfoto. Zij geeft dak, perceel en omgeving als snelle installateurscontext, maar geen betrouwbare actuele gevel, leidingroute, obstakels of montagehoogte. `facade_overview_photo` blijft daarom optioneel; de bestaande concrete buitenunit-/routefoto’s blijven de eenvoudigste controlebron wanneer ze nodig zijn.

Vraagreductie blijft template-gestuurd:

| `meta`-vlag | Gedrag |
|-------------|--------|
| `skip_when_prefilled_by: pdok` | De wizard laat de vraag weg als voor dezelfde vraag een antwoord met `prefill_source=pdok` bestaat. Zonder eenduidig bronresultaat blijft de normale vraag zichtbaar. |

Airco v4 gebruikte dit alleen voor `build_year`: BAG registreert dit direct op het eenduidig gekoppelde pand. v6 breidt het uit naar `building_type`, maar alleen voor het eenduidige geval — bevat het gebruiksdoel geen enkele `woonfunctie`, dan is `commercial` een feit. BAG onderscheidt appartement, tussenwoning, hoekwoning en vrijstaand níét, dus bij elke woonfunctie blijft de vraag gewoon staan: een fout voorzet kost de installateur meer dan één extra vraag.

Belangrijk: de aanroep moet ook echt gebeuren. Tot v6 draaide `EnrichIntakeAddress` alleen bij `IntakeController::store`, waardoor de publieke demo nooit werd verrijkt en `skip_when_prefilled_by` daar dood bleef. `StartDemoIntake` roept de verrijking nu zelf aan, op een bestaand BAG-adres uit `intake.demo.address`. Automatisch opgehaalde feiten gaan naast antwoorden mee in de context voor AI-samenvatting en aandachtspunten; bron en zekerheid blijven behouden.

## Foto-afleiding als bevestigbare voorzet (BL-020)

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
| `room` | `room_photos` | ruimtetype, grootte, zonbelasting, glasoppervlak |
| `outdoor` | `outdoor_location_photos` | plek, ondergrond, bereikbaarheid |
| `pipe_route` | `pipe_route_photos` | route, afstandsindicatie, boringen nodig |
| `fusebox` | `fusebox_photo` | vrije groep, fase (eigen actie, zie hieronder) |

Elke sectie opent nu met zijn foto. In v6 stonden `room_type` en `outdoor_location` nog vóór hun foto en konden daardoor per definitie niet vervallen.

Booleanvragen worden afgeleid via `yes`/`no` op de wire en pas bij opslag omgezet naar een echte boolean, zodat het model nooit over JSON-types hoeft te redeneren.

### Wat bewust blijft staan

Niet alles hoort weg te vallen, ook niet als het "korter" kan:

- `ownership`, `pipe_visibility`, `noise_sensitive` — juridische status en voorkeuren staan niet op een foto.
- `floor_level` — een binnenfoto laat de verdieping niet betrouwbaar zien.
- `truth_confirmation` en `privacy_consent` blijven twee losse vragen. Toestemming moet specifiek en ongebundeld zijn; samenvoegen met een juistheidsverklaring maakt haar niet-vrij. Eén stap winst weegt daar niet tegenop.
- `insulation_indication` blijft een vraag: bouwjaar uit de BAG zegt weinig over uitgevoerde renovaties.

## Twee BAG-routes: Kadaster met PDOK als vangnet

De adres-autocomplete in het installateursformulier blijft altijd de open PDOK Locatieserver — Individuele Bevragingen is geen geocoder. Voor de *kenmerken* van het gekozen adres probeert `PdokAddressService` eerst de [BAG API Individuele Bevragingen](https://www.kadaster.nl/zakelijk/producten/adressen-en-gebouwen/bag-api-individuele-bevragingen) van Kadaster:

| | Kadaster Individuele Bevragingen | PDOK BAG OGC (vangnet) |
|---|---|---|
| Bevraging | exact op postcode + huisnummer | vrije tekst + `matchesIntake()`-filter |
| Actualiteit | near-realtime uit de LVBAG | periodiek ververst extract |
| Auth | `X-Api-Key` | geen |
| Limieten | gebruikslimieten, niet voor bulk | geen |

Zonder key, bij een storing of bij een niet-eenduidig antwoord valt de verrijking stil terug op de PDOK-route — dezelfde `AddressEnrichment`, dus de rest van de keten merkt er niets van. `BAG_API_ENABLED=false` is de standaard.

Twee dingen komen ook op het Kadaster-pad van PDOK: **coördinaten** (Kadaster levert geometrie in RD/EPSG:28992, het dossier en de luchtfoto rekenen op WGS84) en **gemeente/provincie**.

`oorspronkelijkBouwjaar` is bij Kadaster een array — één jaar per pand waar het verblijfsobject deel van uitmaakt. Alleen een eenduidig jaar wordt als voorzet overgenomen; bij panden met verschillende bouwjaren blijft de bouwjaarvraag gewoon staan.

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

Omdat `building_type` nu uit twee registers kan komen, accepteert `meta.skip_when_prefilled_by` sinds v8 ook een lijst bronnen.

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

Gemeten op een opname met één binnenunit, met werkende BAG en foto-inferentie:

| Versie | Stappen |
|---|---|
| v5 (platte lijst) | 38 |
| v6 (adaptief) | 29 |
| v7 (maximaal afgeleid) | ~20 |
| v8 (met energielabel) | ~19 |

Wat overblijft is intentie (reden, koelen/verwarmen, aantal units), niet-zichtbare feiten (eigendom, verdieping), voorkeuren en de twee afsluitende verklaringen.

Bij expliciet ingeschakelde foto-inferentie beoordeelt `AssessFuseboxPhotos` maximaal twee recente meterkastfoto's. Alleen `free_group=yes|no` met `confidence=high` wordt als `prefill_source=ai` klaargezet. De wizard toont deze keuze gemarkeerd als foto-inschatting; een klantkeuze bevestigt/corrigeert en verwijdert de prefillbron. Bestaande klant- of installateurantwoorden worden nooit overschreven. Bij onvoldoende beeld blijft de normale vraag staan en verschijnt de concrete `retake_instruction` bij de foto.

De volledige beperkte uitkomst (vrije groep, 1-/3-fase/unknown, zekerheid, zichtbaar bewijs, provider/model en gebruikte upload-id's) staat als `fusebox_photo_assessment` in de automatisch verzamelde informatie. Dossier, HTML en PDF noemen `AI-fotoanalyse` als bron en zetten de waarneming altijd bij te controleren onzekerheden. Beeldbytes bestaan alleen tijdens het providerrequest; verwijderen van de bronfoto verwijdert de AI-voorzet en het afgeleide feit. Zonder provider/flag werkt de intake ongewijzigd verder.

## Gerichte aanvullende informatieronde (BL-027)

1. De installateur kiest na afronding `need_more_info` en voegt 1–5 concrete items toe: `text`, `photo` of `document` (PDF).
2. `SubmitIntakeReview` schrijft review + genummerde ronde atomair en zet de intake op `awaiting_customer`. De bestaande geldige token blijft de enige klanttoegang.
3. `IntakeWizard` schakelt voor die status naar een aparte vervolgmodus: uitsluitend de gevraagde items, één per scherm. Bestaande templatevragen worden niet opnieuw getoond.
4. Tekst wordt tussentijds opgeslagen. Foto-items gebruiken dezelfde MIME-controle, HEIC-normalisatie, private disk en uploadlimiet als de gewone wizard. Documentitems accepteren alleen PDF na server-side MIME- en bestandssignatuurcontrole en gebruiken dezelfde private disk.
5. `CompleteFollowUpRound` vereist elk antwoord/minimaal één gevraagd bestand, markeert de ronde compleet, zet de intake opnieuw op `completed`, bouwt HTML/PDF opnieuw op en stuurt de bestaande installateursnotificatie.

Rapport en installateurdetail behouden eerdere antwoorden en tonen per aanvulling ronde, vraag, klantantwoord/foto's/documenten en bron. Activity events bevatten alleen ronde, item-id, type en aantallen; nooit vrije tekst of token. Standaardlimieten: 3 rondes, 5 items per ronde, 5 foto's per foto-item en 3 PDF's per documentitem (`INTAKE_FOLLOW_UP_*`).

## Nieuwe intaketemplate toevoegen

1. Configbestand onder `database/data/templates/{key}/v1.php`
2. Seeder of artisan-commando `intake:template-publish {key}`
3. Zet `intake_templates.is_active = true`
4. Tests voor visibility/completeness van die template
5. Documenteer afwijkende secties in dit bestand

Geen nieuwe controllers per intaketype.

## Uitbreidingspunten (niet MVP)

Gepland werk staat in [docs/backlog.md](backlog.md).

Afgerond: airco-template v2-audit (BL-017); prefill van bekende gegevens (BL-016, zie [§ Prefill](#prefill-van-bekende-gegevens-bl-016)); openbare adres-/gebouw-/luchtfotodata (BL-019); bevestigbare meterkastfoto-afleiding (BL-020); gerichte aanvullende informatierondes (BL-027).

Verder buiten scope tot er vraag naar is:

- Visuele templatebouwer
- Per-bedrijf template-overrides
- Branching naar volledig andere flows mid-intake
