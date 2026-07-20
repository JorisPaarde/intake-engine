# Intake-engine

> **Documentversie:** 1.7 · **Laatste update:** 2026-07-18 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Status: **geïmplementeerd t/m Fase 6** (compleetheid, rapport, beoordeling en AI-samenvatting). Airco-template **v3** gepubliceerd — v2-vragenset + prefill-vlaggen (BL-016; audit BL-017 zit in v2).

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

Nieuwe intakes gebruiken **v2**; lopende/afgeronde opnames blijven op hun gepinde versie (ADR-0001). Config: `database/data/templates/airco/v2.php`.

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

Airco: v3 vlagt `request`-vragen als `installer_prefillable` en `rooms.floor_level` als `prefill_from_previous`. Verdere afleiding (uit adres/openbare bronnen, uit foto's) staat los in BL-019/BL-020.

## Nieuwe intaketemplate toevoegen

1. Configbestand onder `database/data/templates/{key}/v1.php`
2. Seeder of artisan-commando `intake:template-publish {key}`
3. Zet `intake_templates.is_active = true`
4. Tests voor visibility/completeness van die template
5. Documenteer afwijkende secties in dit bestand

Geen nieuwe controllers per intaketype.

## Uitbreidingspunten (niet MVP)

Gepland werk staat in [docs/backlog.md](backlog.md); relevante items voor de engine:

- Afleiden uit adres/openbare bronnen: satellietbeeld, BAG-bouwjaar (BL-019)
- Foto-gedreven afleiding en adaptieve vervolgvragen, bv. meterkastfoto → vrije groep (BL-020)

Afgerond: airco-template v2-audit (BL-017); prefill van bekende gegevens (BL-016, zie [§ Prefill](#prefill-van-bekende-gegevens-bl-016)).

Verder buiten scope tot er vraag naar is:

- Visuele templatebouwer
- Per-bedrijf template-overrides
- Branching naar volledig andere flows mid-intake
