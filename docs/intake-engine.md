# Intake-engine

Status: **klantflow Fase 3 geïmplementeerd** (stappen, autosave, conditionals, voortgang). Foto-upload UI volgt in Fase 4; afronden in Fase 5.

## Doel

Een herbruikbare intake-engine: vragen, validatie, conditionele logica, voortgang en compleetheid zijn data-gedreven. Airco is de eerste template, geen hardcoded airco-app.

## Opbouw

```
Template (key: airco)
  └── Version (v1, published)   ← intakes pinnen hierop
        └── Sections (ordered)
              └── Questions (typed)
                    ├── Options (keuzevragen)
                    └── Rules (conditioneel show/require)
```

Bron van template-inhoud in MVP:

1. **PHP/array- of YAML/JSON-config** in repo (`database/data/templates/airco/v1.php` of vergelijkbaar)
2. **Seeder** die een gepubliceerde `intake_template_version` + children schrijft
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
- Klantflow: **één sectie (of substap) per scherm** op mobiel
- `is_repeatable`: bv. “Ruimtes” herhaalt zich N keer op basis van `repeat_count_question_key` (aantal binnenunits)

`section_instance_key` op antwoorden/uploads: `null` voor normale secties, `room-1` … `room-n` voor herhalingen.

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

- Berekend over zichtbare, relevante vragen/foto-opdrachten in de gepinde versie
- `progress_percent` op `intakes` wordt bij elke save bijgewerkt (cache)
- UI toont: huidige stap, percentage, ontbrekende verplichte onderdelen

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
    {"question_key": "fusebox_photo", "section_instance_key": null, "reason": "required_photo"}
  ],
  "attention_points": [
    {"code": "no_free_group", "label": "Geen vrije groep bekend"}
  ]
}
```

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
- Zelfde klantlink hervat op `current_section_key`
- Duidelijke “opgeslagen”-feedback in UI

## Airco-template (MVP-inhoud)

Minimale secties (keys voorstel):

1. `request` — aanvraag (reden, koelen/verwarmen, units, merk, planning)
2. `building` — woning/pand
3. `rooms` — repeatable per binnenunit
4. `outdoor_unit` — buitenunit
5. `pipe_route` — leidingroute
6. `electrical` — meterkast / groep
7. `condensate` — condensafvoer
8. `closing` — opmerkingen, waarheidsverklaring, toestemming

Exacte labels/opties blijven configureerbaar na installateurs-feedback; keys stabiel houden binnen een versie.

## Nieuwe intaketemplate toevoegen

1. Configbestand onder `database/data/templates/{key}/v1.php`
2. Seeder of artisan-commando `intake:template-publish {key}`
3. Zet `intake_templates.is_active = true`
4. Tests voor visibility/completeness van die template
5. Documenteer afwijkende secties in dit bestand

Geen nieuwe controllers per intaketype.

## Uitbreidingspunten (niet MVP)

- Visuele templatebouwer
- Per-bedrijf template-overrides
- Branching naar volledig andere flows mid-intake
- AI follow-up vragen (alleen ná deterministische basis)
