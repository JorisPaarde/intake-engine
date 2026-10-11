# PROPOSED prompt / input diff — report_claims baseline (P1-5)

> **Not applied.** Live prompts under `app/Domains/AI/Prompts/` stay unchanged until a real-model baseline is measured on the box. This file is the proposed follow-up after `eval:interpretation --kind=report_claims`.

## Hypothesis (from IE test run 11 Oct 2026)

Summary / attention prompts already ask for hedging, but the structured input often exposes aggregated `phase=three_phase` without per-photo `status` / `confidence` / `retake_requested`. The model then emits unhedged “Driefasige … aanwezig” / “uitbreiding … vereist”. `DerivedClaimConfidenceGuard` does **not** match `driefasige` (only `driefasen?`) — do **not** extend those regexes (ADR-0016); fix via prompt + richer input, measured by report_claims.

## 1) Structured input change (preferred lever)

Pass per-photo assessment rows next to the aggregated fusebox fact for both `summary` and `attention_points` payloads, e.g.:

```json
"uploads[].content_assessment": {
  "status": "needs_clearer|ok|…",
  "confidence": "low|medium|high",
  "retake_requested": true,
  "phase": "unknown|three_phase|…",
  "empty_module_space": "unknown|none_visible|visible",
  "customer_message": "…",
  "retake_instruction": "…"
}
```

and/or under `external_facts.fusebox_photo_assessment.value.photo_assessments[]` (see fixture `rc-kt11-fusebox-two-photos-conflict`).

Product code change (after baseline): extend `IntakeAttentionContextBuilder` / summary payload builder only to **surface** fields already stored on uploads — no new keyword meaning logic.

## 2) Proposed `summary` prompt diff (`summary-v2` → `summary-v3`)

Bump `app/Domains/AI/Prompts/summary/meta.php`:

```php
return [
    'version' => 'summary-v3',
    'description' => 'Samenvatting; per-foto meterkast-status/confidence verplicht meewegen (report_claims).',
];
```

Add to `prompt.md` (append under Regels):

```markdown
- Als `external_facts.fusebox_photo_assessment` of `uploads[].content_assessment` per foto `status`, `confidence`, `retake_requested` of conflicterende fase-observaties bevat: noem de onzekerheid of het conflict expliciet (“lijkt”, “mogelijk”, “te controleren”, “foto’s wijzen niet eenduidig”).
- Gebruik nooit stellige formuleringen als “Driefasige groepenkast/meterkast aanwezig” of “uitbreiding … vereist” wanneer enige bronfoto `needs_clearer`, `retake_requested`, `confidence` medium/low, of `empty_module_space=unknown` has.
- Een afgeleide claim mag nooit zekerder klinken dan de zwakste relevante fotobeoordeling.
```

## 3) Proposed `attention_points` prompt diff (`attention_points-v3` → `attention_points-v4`)

Bump `app/Domains/AI/Prompts/attention_points/meta.php`:

```php
return [
    'version' => 'attention_points-v4',
    'description' => 'Aandachtspunten; confidence ≤ bronfoto; geen stilzwijgende capaciteitsfeiten (report_claims).',
];
```

Add to `prompt.md` (append under Regels):

```markdown
- Voor meterkast/fase/vrije-groep: zet `confidence` nooit hoger dan de laagste `confidence` van de geciteerde upload/`fusebox_photo_assessment`-bronnen.
- Citeer bij conflicterende meterkastfoto’s de concrete `uploads[].reference` (of photo_ref) in `evidence`; benoem het conflict in `label`.
- Formuleer “volledig bezet” / “uitbreiding vereist” niet als feit bij `empty_module_space=unknown` of afdekplaatjes; hooguit als controlepunt met confidence ≤ medium.
```

## 4) Measure after apply

```bash
AI_API_KEY=… php artisan eval:interpretation --migrate --repeats=3 --kind=report_claims
```

Accept only if `model_raw` and `pipeline_final` on report_claims are same-or-better vs the pre-change baseline (especially `rc-kt11-fusebox-two-photos-conflict`, `rc-fusebox-blanking-plates`; controls `rc-fusebox-clear-3ph-high` / `rc-no-fusebox-photo` must not regress into over-hedging / invention).

## Out of scope (unchanged)

- Double “Is de meterkastfoto duidelijk genoeg?” proposals
- `dossier_synthesis` / vision
- Extending `DerivedClaimConfidenceGuard` regexes
