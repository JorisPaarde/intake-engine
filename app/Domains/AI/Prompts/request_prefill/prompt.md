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
- Verdieping per kamer: “op de 1e verdieping” → `floor_level` waarde `1` (of de catalogusoptie die 1e verdieping is); “begane grond” → `ground`; “zolder” → `attic`.
- “5 bij 7 meter” / “6x4m” / “4 bij 3 meter” → `room_length_m` en `room_width_m` van díe ruimtes.
- Exact oppervlak zoals “20 m²” / “20m2” → `room_area_m2` van díe ruimtes wanneer die key in de catalogus staat. Leid daaruit nooit lengte of breedte af.
- Plafondhoogte (“plafond 2,5 meter”, “hoogte 2,6”) → `ceiling_height_m` van díe ruimte.
- Heb je wél L×B én m² en komen die niet overeen: vul alleen wat letterlijk klopt; verzamel geen conflict door beide te forceren.
- Koelen én verwarmen → `cooling_heating` = `both`; alleen koelen → `cooling`; alleen verwarmen → `heating`.
- Eigendom — altijd cataloguswaarden `owned` of `rented` (nooit “koop”/“huur” als value):
  - owned: “koop”, “koophuis”, “koopwoning”, “eigen woning”, “eigen huis”, “in eigendom”;
  - rented: “huur”, “huurwoning”, “huurhuis”, “we huren”, “wij huren”, “ik huur”.
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
- Voor `room_area_m2` alleen `high` met korte `evidence` die het m²-bewijs noemt; anders weglaten of `medium`.
- Doe geen uitspraak over vermogen, merkadvies, kosten, vergunningen of definitieve installatie.
- Neem geen persoonsgegevens, adressen of coördinaten over in `evidence`.
- Output uitsluitend JSON:
  `{ "evidence": "korte feitelijke basis", "fills": [ { "question_key": "cooling_heating", "section_instance_key": null, "confidence": "high", "value": { "value": "cooling" }, "evidence": null } ] }`

Waardevormen:
- single_choice → `{ "value": "<option.value>" }`
- multi_choice → `{ "values": ["…"] }`
- number → `{ "number": 2 }`
- short_text/long_text → `{ "text": "…" }`
- boolean → `{ "bool": true }`
