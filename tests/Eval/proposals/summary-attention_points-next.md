# PROPOSED prompt / input diff — report_claims baseline (P1-5)

> **Not applied.** Live prompts under `app/Domains/AI/Prompts/` stay unchanged until a real-model baseline is measured on the box. This file is the proposed follow-up after `eval:interpretation --kind=report_claims`.

## Hypothesis (from IE test run 11 Oct 2026 + real eval on 7065be0)

Summary / attention prompts already ask for hedging, but the structured input often exposes aggregated `phase=three_phase` without per-photo `status` / `confidence` / `retake_requested`. The model then hardens uncertain or conflicting meterkast evidence into capacity/phase facts. `DerivedClaimConfidenceGuard` is not the fix path (ADR-0016) — improve prompt + richer input, measured by report_claims claim-level scoring.

## 1) Structured input change (preferred lever)

Pass per-photo assessment rows next to the aggregated fusebox fact for both `summary` and `attention_points` payloads (status, confidence, retake_requested, phase, empty_module_space, customer_message / retake_instruction). Today `AssessFuseboxPhotos::storeObservation` does **not** write `photo_assessments[]` into the external fact — fixtures that omit it match prod; adding that field (or richer `uploads[].content_assessment`) is the product change under test.

Product code change (after baseline): extend `IntakeAttentionContextBuilder` / summary payload builder only to **surface** fields already stored on uploads — no new keyword meaning logic.

## 2) Proposed `summary` prompt diff (`summary-v2` → `summary-v3`)

Bump `app/Domains/AI/Prompts/summary/meta.php` to `summary-v3`.

Add under Regels (principle-level, no scorer phrase lists):

```markdown
- Als per-foto meterkastbeoordelingen conflicteren of een foto `needs_clearer` / retake / lage–middel confidence heeft: noem de onzekerheid of het conflict expliciet en wijs de onduidelijke foto aan.
- Een afgeleide claim over fase of vrije capaciteit mag nooit zekerder klinken dan de zwakste relevante fotobeoordeling.
- Bij een duidelijke, hoge-zekerheid meterkastfoto: formuleer de waarneming gewoon; hedge niet onnodig.
```

## 3) Proposed `attention_points` prompt diff (`attention_points-v3` → `attention_points-v4`)

Bump `app/Domains/AI/Prompts/attention_points/meta.php` to `attention_points-v4`.

Add under Regels:

```markdown
- Voor meterkast/fase/vrije-groep: zet `confidence` nooit hoger dan de laagste confidence van de geciteerde upload- of fusebox-bronnen.
- Bij conflicterende meterkastfoto’s: citeer de concrete upload-`reference` in `evidence` en benoem het conflict in `label`.
- Capacitetsuitspraken alleen als controlepunt wanneer `empty_module_space` onbekend is of foto’s afdekplaatjes/onzekerheid tonen — nooit als stilzwijgend feit.
```

## 4) Measure after apply

```bash
AI_API_KEY=… php artisan eval:interpretation --migrate --repeats=3 --kind=report_claims
```

Accept only if claim-level `model_raw` / `pipeline_final` scores are same-or-better vs the pre-change baseline (especially conflict + blanking; controls must not invent phase or over-hedge a clear photo).

## Out of scope (unchanged)

- Double “Is de meterkastfoto duidelijk genoeg?” proposals
- `dossier_synthesis` / vision
- Extending `DerivedClaimConfidenceGuard` regexes
