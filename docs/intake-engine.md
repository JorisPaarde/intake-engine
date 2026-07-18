# Intake-engine

> **Documentversie:** 1.4 · **Laatste update:** 2026-07-18 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Status: **geïmplementeerd t/m Fase 6** (compleetheid, rapport, beoordeling en AI-samenvatting). Airco-template **v2** gepubliceerd (BL-017).

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

## Nieuwe intaketemplate toevoegen

1. Configbestand onder `database/data/templates/{key}/v1.php`
2. Seeder of artisan-commando `intake:template-publish {key}`
3. Zet `intake_templates.is_active = true`
4. Tests voor visibility/completeness van die template
5. Documenteer afwijkende secties in dit bestand

Geen nieuwe controllers per intaketype.

## Uitbreidingspunten (niet MVP)

Gepland werk staat in [docs/backlog.md](backlog.md); relevante items voor de engine:

- Prefill van bekende/afleidbare gegevens (BL-016)
- Afleiden uit adres/openbare bronnen: satellietbeeld, BAG-bouwjaar (BL-019)
- Foto-gedreven afleiding en adaptieve vervolgvragen, bv. meterkastfoto → vrije groep (BL-020)

Afgerond: airco-template v2-audit (BL-017).

Verder buiten scope tot er vraag naar is:

- Visuele templatebouwer
- Per-bedrijf template-overrides
- Branching naar volledig andere flows mid-intake
