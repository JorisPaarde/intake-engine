# APPLIED prompt / input diff — report_claims (P1-5)

> **Applied** on the branch that ships `summary-v3`, `attention_points-v4`, and summary-payload `uploads[]` from stored `content_assessment`. Kept here as the measurement brief for re-running `eval:interpretation --kind=report_claims`.

## Hypothesis (from IE test run 11 Oct 2026 + real eval on 7065be0 / ba015b6)

Summary / attention prompts already asked for hedging, but the structured input often exposed aggregated `phase=three_phase` without per-photo status. The model then hardened uncertain or conflicting meterkast evidence. Fix via prompt + richer input (not `DerivedClaimConfidenceGuard` regexes — ADR-0016).

## 1) Structured input change (applied)

`SummarizeIntake` now includes `uploads` rows from `IntakeAttentionContextBuilder::uploadAssessmentRows()` — the same stored `content_assessment` already used for attention points (status, subjects, customer_message; no new fields/regex).

## 2) `summary` → `summary-v3` (applied)

Per-foto conflict / `needs_clearer` hedging; no unnecessary hedge on a clear high-certainty photo.

## 3) `attention_points` → `attention_points-v4` (applied)

- Cap `confidence` using cited `uploads[].content_assessment` (e.g. `needs_clearer` → at most medium), **not** the fusebox **fact** confidence (prod `AssessFuseboxPhotos::storeObservation` always writes fact confidence `medium` unless wrong subject).
- Cite conflicting upload references; capaciteitsuitspraken only as check points when capacity is unknown/uncertain.

## 4) Measure after apply

```bash
AI_API_KEY=… php artisan eval:interpretation --migrate --repeats=3 --kind=report_claims
```

## Out of scope

- Double “Is de meterkastfoto duidelijk genoeg?” proposals
- `dossier_synthesis` / vision
- Extending `DerivedClaimConfidenceGuard` regexes
