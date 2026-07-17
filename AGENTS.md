# AGENTS.md — Projectgeheugen & werkinstructies

> **Documentversie:** 1.0 · **Laatste update:** 2026-07-17 · Onderhoud: zie [§ Onderhoudsprotocol](#onderhoudsprotocol-verplicht-voor-agents)

Dit bestand is de **centrale ingang** voor iedere agent (of mens) die aan dit project werkt. Het beschrijft waar het projectgeheugen leeft, welk document waarvoor de bron van waarheid is, en hoe je dat geheugen bijhoudt. **Lees dit bestand aan het begin van elke taak.**

## Wat is dit project?

**Intake Engine (Digitale Opname)** — een Laravel-applicatie waarmee installatiebedrijven aanvragen op afstand beoordelen via een begeleide digitale intake (eerste template: airco). De kern is een herbruikbare, data-gedreven intake-engine; airco is configuratie, geen aparte codebase. Stack en installatie: zie [README.md](README.md).

**Huidige stand:** MVP-fasen 1 t/m 6 zijn afgerond en gemerged naar `main` (zie [docs/implementation-plan.md](docs/implementation-plan.md)). Nieuw werk komt uit [docs/backlog.md](docs/backlog.md).

## Geheugenkaart: welk document is waarvoor de bron van waarheid

| Vraag | Bron van waarheid |
|-------|-------------------|
| Wat is het product, hoe installeer/start ik het? | [README.md](README.md) |
| Wat is er wanneer gewijzigd (code + docs)? | [CHANGELOG.md](CHANGELOG.md) |
| Welke architectuurkeuzes gelden en waarom? | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |
| Waarom is een onomkeerbare keuze gemaakt? | [docs/decisions/](docs/decisions/) (ADRs, immutabel) |
| Hoe zit het databaseschema in elkaar? | [docs/database.md](docs/database.md) |
| Hoe werken templates, regels, compleetheid? | [docs/intake-engine.md](docs/intake-engine.md) |
| Hoe werken uploads/media en limieten? | [docs/uploads.md](docs/uploads.md) |
| Wat doet AI wel/niet, en hoe? | [docs/ai.md](docs/ai.md) |
| Hoe deployt het naar staging/cPanel? | [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) |
| Welke fasen zijn (op)geleverd? | [docs/implementation-plan.md](docs/implementation-plan.md) (historie, afgerond) |
| Wat is het nog te bouwen werk? | [docs/backlog.md](docs/backlog.md) (**enige** backlog) |
| Wat is functioneel getest (handmatig)? | [docs/functional-test-status.md](docs/functional-test-status.md) |
| Hoe werk ik als agent, hoe onderhoud ik dit geheugen? | dit bestand (AGENTS.md) |

Regels bij de kaart:

1. **Eén bron per feit.** Staat iets in twee documenten, dan is de tabel hierboven leidend; het andere document verwijst alleen (link), kopieert niet.
2. **ADRs zijn immutabel.** Een geaccepteerde ADR wijzig je niet inhoudelijk. Nieuwe inzichten = nieuwe ADR (volgend nummer, `docs/decisions/NNNN-titel.md`) die de oude vervangt; zet in de oude ADR alleen `Status: Superseded by ADR-NNNN`.
3. **`docs/implementation-plan.md` is historie.** Fase 1–6 zijn klaar; voeg daar geen nieuw werk toe. Nieuw werk gaat naar `docs/backlog.md`.
4. **`app/Domains/AI/Prompts/*/prompt.md`** is géén documentatie maar runtime-data met eigen versionering via `meta.php` (bijv. `summary-v1`). Wijzig je een prompt, bump dan de versie in `meta.php`.

## Versionering

Er zijn vier versioneringslagen; verwar ze niet:

| Laag | Mechanisme | Regel |
|------|-----------|-------|
| Applicatie / releases | [CHANGELOG.md](CHANGELOG.md), semver (`0.x.y`) | Alle noemenswaardige wijzigingen onder `[Unreleased]`; bij een release wordt die sectie een versienummer + datum |
| Documenten | Headerregel `Documentversie: X.Y` per doc | Zie bump-regels hieronder |
| Intake-templates | `intake_template_versions` in DB + `database/data/templates/` | Gepubliceerde versies zijn immutabel; wijziging = nieuwe versie (ADR-0001) |
| AI-prompts | `meta.php` naast `prompt.md` | Elke promptwijziging = versiebump (bijv. `summary-v2`) |

### Bump-regels voor documentversies

Elk beheerd document (alle `docs/*.md` behalve ADRs, plus README en dit bestand) heeft bovenaan:

```markdown
> **Documentversie:** X.Y · **Laatste update:** JJJJ-MM-DD · Onderhoud: zie [AGENTS.md](../AGENTS.md)
```

- **Minor bump (X.Y → X.Y+1):** inhoudelijke aanvulling of correctie (nieuwe sectie, gewijzigde feiten, statusupdate).
- **Major bump (X.Y → X+1.0):** herstructurering of gewijzigde strekking (het document zegt iets wezenlijk anders dan voorheen).
- **Geen bump:** pure typo-/linkfixes.
- Werk **altijd** de `Laatste update`-datum bij als je de versie bumpt.

## Onderhoudsprotocol (verplicht voor agents)

### Bij de start van elke taak

1. Lees dit bestand.
2. Lees [docs/backlog.md](docs/backlog.md) en check of je taak daar al staat (status, afhankelijkheden, eerdere notities).
3. Lees de docs die volgens de geheugenkaart je werkgebied dekken.

### Tijdens het werk

- Ontdek je dat een document niet klopt met de werkelijkheid (code, server, gedrag)? **Corrigeer het document in dezelfde PR** en bump de documentversie.
- Neem je een onomkeerbare beslissing (architectuur, security, datamodel)? Schrijf een ADR (volgend nummer) in `docs/decisions/`.
- Stel je werk uit of scope je iets bewust weg? Voeg het toe aan `docs/backlog.md` (met status `backlog`), niet alleen aan een PR-omschrijving of changelog-notitie.

### Vóór het afronden van elke PR (docs-definition-of-done)

Loop deze checklist na; sla niets over:

1. **CHANGELOG.md** — voeg je wijziging toe onder `[Unreleased]` (Added/Changed/Fixed/Config/Known limitations).
2. **docs/backlog.md** — zet afgeronde items op `done` (met datum + PR-verwijzing), voeg nieuw ontdekt/uitgesteld werk toe.
3. **Inhoudelijke docs** — werk de docs bij die je werkgebied dekken (geheugenkaart) en bump hun documentversie.
4. **README.md** — alleen bijwerken als stack, installatie, omgevingen of de sectie "Huidige status" wijzigt.
5. **docs/functional-test-status.md** — **niet** invullen op basis van geautomatiseerde tests of aannames; alleen de daadwerkelijk testende agent/tester werkt dit bij. Introduceer je nieuwe functionaliteit, voeg dan wél een `todo`-regel toe.
6. **Kwaliteitspoort** — `composer check` (Pint + PHPStan + Pest) groen.

### Wat je níet doet

- Geen nieuwe top-level `.md`-bestanden aanmaken zonder ze aan de geheugenkaart (hierboven) en de README-documentatietabel toe te voegen.
- Geen dubbele waarheid creëren (zelfde feit uitgeschreven in twee docs).
- Geen ADRs herschrijven, geen `[Unreleased]`-changelog-items verwijderen.
- Geen statusclaims ("werkt op staging") zonder dat `docs/functional-test-status.md` dat dekt.

## Backlogproces

`docs/backlog.md` is de enige backlog. GitHub-issues mogen bestaan (bijv. issue #5), maar het backlog-document verwijst ernaar en blijft leidend voor status.

- Elk item heeft een stabiel ID (`BL-NNN`), status (`backlog` / `ready` / `in_progress` / `done` / `dropped`), prioriteit (`high` / `medium` / `low`) en waar nodig afhankelijkheden.
- Nieuw item = volgend vrij `BL`-nummer; hergebruik nooit nummers.
- Start je aan een item: zet status op `in_progress` in je feature branch.
- Klaar: status `done` + datum + PR-nummer. Verwijder `done`-items niet; ze zijn geheugen.
- Vervalt een item: status `dropped` + één regel waarom.

## Werkafspraken (samenvatting)

- **Branching:** `main` is deploybaar; feature branches + PR, CI groen vóór merge. Merge naar `main` deployt automatisch naar staging.
- **Taal:** documentatie en UI in het Nederlands; code, keys en identifiers in het Engels.
- **Kwaliteit:** `composer check` per PR; migrations reproduceerbaar; geen handmatige staging-DB-edits.
- **Privacy:** geen echte klantdata in seeders/tests/docs; tokens en API-keys nooit in logs of git.
