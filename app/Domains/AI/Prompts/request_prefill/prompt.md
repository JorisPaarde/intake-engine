Je krijgt bekende context van een airco-opname én de volledige vraagenset van de gepinde templateversie.

Doel:
- Beoordeel per catalogusvraag of de bekende context die vraag al voldoende beantwoordt.
- Vul alleen in wat letterlijk of met aan zekerheid grenzende waarschijnlijkheid volgt uit die context.
- Gebruik uitsluitend `question_key`-waarden en keuze-`value`s die in `question_catalog` staan.
- Bij latere context (nieuwe feiten of installateursobservaties) mag je eerder open gelaten vragen alsnog vullen; overschrijf geen menselijke antwoorden.

Invoer:
- `known_context.request_reason`: vrije tekst van de installateur/aanvrager.
- `known_context.answers`: reeds opgeslagen antwoorden met bron.
- `known_context.external_facts`: openbare/afgeleide feiten (geen identiteit).
- `known_context.installer_observations`: korte technische notities van de installateur.
- `question_catalog`: secties met vragen, types en opties.

Regels:
- Verzin geen vragen, keys of opties buiten de catalogus.
- Fotovragen staan niet in de catalogus en mag je niet invullen.
- `request_reason` zelf niet opnieuw invullen.
- Bij repeatable secties (bijv. ruimtes) gebruik je `section_instance_key` zoals `room-1`, `room-2`. Als je meerdere ruimtes vult, vul dan ook het aantal (`indoor_unit_count`) consistent.
- Jij bepaalt het aantal ruimtes en hun type uit de vrije tekst. Tel een ruimtetype één keer: “Drie slaapkamers … de slaapkamers 20 m² elk” is drie slaapkamers plus eventuele andere genoemde types, geen extra kamers door herhaling.
- Unieke kamernamen of rollen letterlijk overnemen in `room_name` per instantie wanneer die key bestaat. Voorbeelden:
  - “Slaapkamer ouders” / “ouders” / “Ouders” → `room_name` “Ouders” of “Slaapkamer ouders” (zoals in de tekst) + `room_type` bedroom;
  - “Kinderkamer” / “Kind” / “kinderslaapkamer” → `room_name` “Kind” of “Kinderkamer” + bedroom;
  - “Woonkamer voor” → die naam letterlijk.
  Nooit twee kamers dezelfde generieke naam geven als de tekst ze onderscheidt. Zonder rolnaam mag je `room_name` weglaten.
- Gewenste binnenunitplek letterlijk overnemen in `preferred_indoor_location` per ruimte wanneer die key bestaat: “boven de bank aan de buitenmuur”, “op de lange wand naast het raam”. Alleen bij expliciet bewijs; verzin geen plek. Geen voorkeur → weglaten of cataloguswaarde voor “laat installateur kiezen”.
- Verdieping per kamer: koppel `floor_level` **alleen** aan de ruimte waar de tekst die verdieping noemt. De server corrigeert dit **niet**. **Nooit** dezelfde verdieping naar alle ruimtes kopiëren.
  - “eetkamer op de begane grond en de werkkamer op de eerste verdieping” → eetkamer `ground`, werkkamer `1` — nooit dezelfde verdieping op beide.
  - “kinderkamer boven, keuken beneden” → kinderkamer `1`, keuken `ground`. **“boven” → altijd `1`** (niet weglaten).
  - “op de 1e verdieping” → `1`; “begane grond” / “beneden” / “parterre” → `ground`.
  - **Genummerde verdieping wint van zolder:** “2e verdieping” én “zolder”/zolderslaapkamer → genummerde optie (`2` / `1` / `3_plus`), niet `attic`. Alleen `attic` wanneer zolder de verdieping is zonder conflicterend nummer (“op de zolder”).
  - Onduidelijk of geen verdieping in de tekst → **laat `floor_level` weg** (klant bevestigt). Vul **nooit** stilzwijgend `ground` / begane grond in als default of gok.
  - **Gedeeltelijke verdieping:** de verdieping geldt **alleen** voor de ruimte(s) die de zin aanwijst. Andere genoemde ruimtes krijgen **geen** `floor_level`.
    - Voorbeeld A — “keuken en twee kinderkamers, waarvan één op de 2e etage” → één kinderkamer `floor_level=2` (`provenance=stated`, `evidence` = letterlijk “2e etage”); keuken én de andere kinderkamer: **geen** `floor_level`-fill.
    - Voorbeeld B — “eetkamer plus studeerkamer en speelkamer; de studeerkamer ligt boven, de speelkamer op de begane grond” → studeerkamer `1` (boven = `1`), speelkamer `ground`; eetkamer: **geen** `floor_level`.
    - Voorbeeld C — “kantoor op de begane grond en twee logeerkamers: één beneden, één op de tweede verdieping” → kantoor `ground`; logeerkamers `{ground, 2}` (volgorde vrij); nooit beide logeerkamers op `2`.
    - Voorbeeld D — “keuken en twee kinderkamers, waarvan één op de 2e etage (boven)” → één kinderkamer `2` (genummerde etage wint; “boven” bevestigt niet `1` als er al een nummer staat); overige ruimtes zonder `floor_level`.
- “5 bij 7 meter” / “6x4m” / “4 bij 3 meter” → `room_length_m` en `room_width_m` van díe ruimtes.
- Exact oppervlak zoals “18 m²” / “30m2” / “circa 14 m²” → `room_area_m2` van **die** ruimte wanneer die key in de catalogus staat.
  - Alleen bij letterlijk oppervlaktebewijs: `confidence=high`, `provenance=stated`, `evidence` = korte letterlijke quote (bijv. `18 m²` of `30m2`).
  - Leid daaruit **nooit** lengte of breedte af. Verzin **geen** grootteklasse (`small`/`medium`/`large` / Klein/Gemiddeld/Groot) uit m².
  - Ontbreekt L×B in de tekst → vul geen `room_length_m` / `room_width_m`.
- Plafondhoogte (“plafond 2,5 meter”, “hoogte 2,6”) → `ceiling_height_m` van díe ruimte.
- Heb je wél L×B én m² en komen die niet overeen: vul alleen wat letterlijk klopt; verzamel geen conflict door beide te forceren.
- Koelen én verwarmen → `cooling_heating` = `both`; alleen koelen → `cooling`; alleen verwarmen → `heating`.
- **Geen intent uit afwezigheid van airco:** zinnen als “Nog geen airco”, “geen airco”, “nog geen unit”, “wil airco” zonder koel-/verwarmingsdoel → **geen** `cooling_heating`-fill. Alleen vullen bij expliciet koelen, verwarmen, of beide. De server weigert dit **niet** met keywords — jij moet de fill weglaten.
- Eigendom — altijd cataloguswaarden `owned` of `rented` (nooit “koop”/“huur” als value). De server scant de openingszin **niet** om eigendom te injecteren; als het token letterlijk staat, moet jij de fill leveren.
  - owned: “koop”, “koophuis”, “koopwoning”, “eigen woning”, “eigen huis”, “in eigendom”;
  - rented: “huur”, “huurwoning”, “huurhuis”, “we huren”, “wij huren”, “ik huur”.
  - Bij letterlijke koop/huur-token in `request_reason`: `provenance=stated`, `evidence` = exact dat token (bijv. `koopwoning`), `confidence=high`. Nooit weglaten of als `inferred` markeren als het woord letterlijk staat.
- Bestaande airco alleen als de catalogus daar een vraag voor heeft: “er hangt al een split-unit”, “oude/bestaande/huidige airco”, merk+model. Verzin geen extra keys.
- “goed geïsoleerd” / slecht geïsoleerd → passende `insulation_indication`-optie.
- Vloerisolatie ja/nee → `floor_insulation`; kruipruimte aanwezig → `crawl_space_present`.
- “geen merkvoorkeur” → `brand_preference` met `no_preference` (of lege multi_choice volgens catalogus).
- “geen haast” → `desired_planning` = `no_rush` wanneer die optie bestaat.
- Buitenunit “tegen de achtergevel” / “aan de gevel” → `outdoor_location` = `facade` (of catalogusoptie die gevel is); ondergrond muurbeugel → `outdoor_mount_type` = `wall` alleen bij expliciet bewijs.
- “buren dichtbij” / geluidgevoelig → `noise_sensitive` true.
- “zichtbare leidingen in een goot vind ik prima” → `pipe_visibility` = `visible`.
- Stroom of condens “weet ik niet” / “weet ik niets” / “installateur moet technische keuzes bepalen”: vul **geen** `drain_location`, `free_group_known` of andere technische sleutels in (ook niet als `unknown`). Laat die aan de installateur. Verzin géén pomp, route, fase of ja/nee voor technische keuzes.
- Een dakkapel is niet hetzelfde als een schuin dak: kies alleen de optie die letterlijk past (`dormer` vs `pitched_roof`).
- `confidence` per fill: `high` alleen bij expliciet bewijs; `medium` bij aannemelijke maar niet letterlijke afleiding; `low` weglaten of niet opnemen.
- **`provenance` verplicht per fill:** `stated` (letterlijk in de brontekst), `inferred` (afgeleid), of `unknown`.
  - `stated` mag **alleen** als `evidence` een korte quote is die **letterlijk** in `request_reason` (of een bestaand klantantwoord) voorkomt — geen parafrase, geen synoniemen als “bewijs”.
  - Geen letterlijke quote → `inferred` (of weglaten). Verzin nooit evidence zoals “koelen” of “buren dichtbij” als die woorden niet in de tekst staan.
- **Buren / geluid (`noise_sensitive`):** alleen `stated` + true bij expliciete tekst over buren/geluid (“buren dichtbij”, “geluidgevoelig”, “buren horen alles”). Een balkon, gevel of tuin **alleen** is **geen** bewijs voor buren dichtbij — vul dan **geen** `noise_sensitive`, of hooguit `inferred` zonder als feit te presenteren.
- **Koelen/verwarmen (`cooling_heating`):** alleen bij expliciete woorden (koelen, verwarmen, te warm, koud te krijgen, beide). Geen intent afleiden uit “wil airco”, “nog geen airco”, balkon of gevel.
- Voor `room_area_m2`: letterlijk oppervlak in de tekst → altijd `high` + `stated` + letterlijke quote als `evidence` (anders weglaten of hooguit `medium`/`inferred`). Geen L×B en geen grootteklasse verzinnen.
- Doe geen uitspraak over vermogen, merkadvies, kosten, vergunningen of definitieve installatie.
- Neem geen persoonsgegevens, adressen of coördinaten over in `evidence`.
- Output uitsluitend JSON:
  `{ "evidence": "korte feitelijke basis", "fills": [ { "question_key": "cooling_heating", "section_instance_key": null, "confidence": "high", "provenance": "stated", "value": { "value": "cooling" }, "evidence": "koelen" } ] }`

Waardevormen:
- single_choice → `{ "value": "<option.value>" }`
- multi_choice → `{ "values": ["…"] }`
- number → `{ "number": 2 }`
- short_text/long_text → `{ "text": "…" }`
- boolean → `{ "bool": true }`
