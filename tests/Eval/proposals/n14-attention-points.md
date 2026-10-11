# PROPOSED prompt diff — N14 false "Tegenstrijdigheid" (attention_points)

> **Not applied.** Live `app/Domains/AI/Prompts/attention_points/` stays unchanged. Measure with `report_claims` fixtures `rc-n14-*`.

## Component

- Prompt: `attention_points` (`attention_points-v3` → proposed `attention_points-v4` rules below; may stack with existing P1-5 proposal in `summary-attention_points-next.md`)
- Downstream UI: installateur aandachtspunten op het rapport (geen klantwizard)

## Hypotheses

1. Promptregel *"Benoem relevante tegenstrijdigheden tussen klantantwoord, registerbron en afleiding"* triggert te breed: AI-foto-labels (werkkamer) en AI-fase worden als tegenspraak van klantintentie/onwetendheid gelezen.
2. Geen schema/code mag “tegenstrijdigheid” filteren (ADR-0013/0016) — alleen prompt + eval.

## Current prompt (live, excerpt)

`app/Domains/AI/Prompts/attention_points/prompt.md` regel ~15:

```markdown
- Benoem relevante tegenstrijdigheden tussen klantantwoord, registerbron en afleiding als controlepunt.
```

## Proposed addition under Regels (`attention_points-v3` → `attention_points-v4`)

Bump `meta.php` version to `attention_points-v4` when applying.

```markdown
- Noem iets alleen een **tegenstrijdigheid** wanneer twee **expliciete beweringen** elkaar uitsluiten (bijv. klant zegt 1-fase en de meterkastfoto wijst op 3-fase). Onwetendheid (“weet ik niet”, `unknown`) is geen bewering en dus nooit een tegenstrijdigheid met een AI- of registerobservatie.
- Een AI-fotolabel over **huidige inrichting** (bureau, bed, “werkkamer”) is geen tegenstrijdigheid met de **door de klant genoemde gewenste ruimte/naam** (bijv. “zolderslaapkamer”). Formuleer dat hoogstens als observatie of controlepunt zonder conflictwoord.
- AI- of fotobevestiging van iets waar de klant “weet ik niet” zei: formuleer als **te controleren observatie** (met hedge), niet als conflict tussen klant en AI.
- Bewaar wél echte conflicten: expliciet klantantwoord vs. register/AI die het tegengestelde stelt.
```

## Per-case: current vs proposed

| Case | Huidige prompt (verwacht) | Voorstel (verwacht) |
|------|---------------------------|---------------------|
| `rc-n14-attic-vs-office-furnishing` | Label met “Tegenstrijdigheid … zolderslaapkamer … werkkamer” → `false_room_use_contradiction` **fail** | Observatie bureau/inrichting zonder conflictwoord → forbidden claim afwezig |
| `rc-n14-phase-unknown-vs-ai` | “Tegenstrijdigheid … niet te weten … bevestigt 3-fase” → **fail** | “3-fase lijkt zichtbaar; te controleren” zonder conflict met onwetendheid |
| `rc-n14-phase-stated-vs-ai` | Echte 1-fase vs 3-fase conflict → **pass** (`assert`; conflicttaal verplicht) | Blijft assert: conflicttaal behouden (controle tegen over-suppressie). Fase zelf blijft `hedged_only`. |

## Fake offline

Fixtures include `fake_attention_output` mirroring the **current** prod wording for the two false cases and a correct conflict for the control — so `--fake` scores the scorer wiring, not the live model.

## Measure (real run on the box)

```bash
AI_API_KEY=… php artisan eval:interpretation --migrate --repeats=3 --kind=report_claims
```

Filter mentally / in the JSON report on `rc-n14-*`. Accept prompt only if false-conflict claims go correct (or stay disputed with clear reason) **and** `rc-n14-phase-stated-vs-ai` still asserts a real contradiction.
