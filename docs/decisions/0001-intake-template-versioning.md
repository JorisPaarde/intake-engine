# ADR-0001: Intake-templateversionering

- **Status:** Accepted
- **Datum:** 2026-07-17

## Context

Templates (vragenlijsten) wijzigen na overleg met installateurs. Afgeronde en lopende opnames mogen niet stilzwijgend van inhoud veranderen.

## Beslissing

- Relationeel model: `intake_templates` → `intake_template_versions` → sections/questions/options/rules.
- Elke `intake` pin’t een `intake_template_version_id`.
- Gepubliceerde versies zijn immutabel; wijzigingen = nieuwe versie.
- Templatebron in MVP: versioned config + seeder, geen form builder.
- Antwoorden refereren `question_key` (+ optionele `section_instance_key`), niet losse question-IDs als enige waarheid.

## Alternatieven

| Alternatief | Afgewezen omdat |
|-------------|-----------------|
| Alleen JSON-blob per versie | Moeilijker te query’en/valideren; relationeel is helderder voor rules |
| In-place edit van vragen | Breekt historische opnames |
| Copy-on-write volledige snapshot JSON op elke intake bij create | Redundant zolang versie immutabel is; wel snapshot van *completeness* bij afronding |

## Gevolgen

- Migraties/seeders moeten “publish new version”-pad ondersteunen.
- Rapporten lezen altijd de gepinde versie + opgeslagen antwoorden.
- Oude README-voorstel (`IntakeFlow`/`IntakeStep`) wordt vervangen door template/version/section/question-terminologie.
