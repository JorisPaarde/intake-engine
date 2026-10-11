# PROPOSED fix path — N16 drain_photo uses outdoor-unit feedback

> **Not applied.** No live prompt or assessment-flow code change in this eval PR. Fixtures live under `tests/Eval/fixtures/photo_feedback/`.

## Root cause (code, not missing context)

Photo question context **does** reach the evaluator:

| Step | Evidence |
|------|----------|
| Question key | `drain_photo` (template Q29 “Afvoer in de buurt”, optionele foto) |
| Profile | `PhotoDerivationProfile` → `drain` → prompt `drain_assessment` |
| Expected subject | `PhotoSubject::expectedForPhotoQuestion('drain_photo')` → **`OutdoorLocation`** (`PhotoSubject.php` ~85) |
| Customer message | `OutdoorLocation::mismatchMessage(...)` → *"… gevel, tuin of buitenplek voor de unit …"* (`PhotoSubject.php` ~253–274) |

Exact prod string for indoor_unit on drain (matches opname #138):

> Dit is een binnenunit; we hebben een foto van de gevel, tuin of buitenplek voor de unit (zonder bestaande airco is prima) nodig.

So this is a **code/schema mapping bug** (drain reclames outdoor-unit place copy), not a missing `question_key` on the AI call. `drain_assessment` prompt itself asks for afvoerplek; the wrong sentence is assembled in PHP after `wrong_subject`.

Secondary: `fromModelOutput` on derive-path forces `wrong_subject` when `expected === OutdoorLocation` and `subject_match=no` even if `room` is in the drain accepted set (`PhotoContentAssessment.php` ~133–142) — so a living-room photo classified as `room` still gets the outdoor-unit place message.

Optional-photo tone (“Toch doorgaan” / “niet goed genoeg”) is UI/`PhotoOverridePolicy` — out of scope for prompt; note for owner.

## Smallest code fix (owner decides; not in this branch)

1. **Preferred:** dedicated drain expected subject or drain-specific branch in `mismatchMessage` / `customerRetakePrompt` when `$questionKey === 'drain_photo'` (or `profileName === 'drain'`), e.g. needed text: *"een foto van de afvoerplek (regenpijp, putje, goot of plek waar condenswater weg kan)"*.
2. Optionally stop treating `subject_match=no` + accepted `room` as hard wrong_subject for optional drain, or soften optional wrong_subject copy.
3. Do **not** add keyword meaning logic; keep structured `question_key` routing (ADR-0013).

Existing tests that pin today’s outdoor copy for drain (`Klantwizard14ReviewRound1Test` R1-9, `Klantwizard14PhotoSubjectConsistencyTest`) must be updated in the same product PR.

## Prompt proposal (optional, secondary)

If retake_instruction from `drain_assessment` still echoes outdoor-unit language, bump `drain-assessment-v1` → `v2` with:

```markdown
- Bij verkeerd onderwerp: `retake_instruction` vraagt om een **afvoerplek** (regenpijp, putje, goot, of plek waar condenswater weg kan) — nooit om “buitenplek voor de unit” of een buitenunit.
- Optionele foto: formuleer mild (“handiger is …”) zonder te suggereren dat de foto verplicht is.
```

Primary lever remains the PHP mismatchMessage for drain.

## Eval harness: smallest `photo_feedback` kind

Fixtures are already in `tests/Eval/fixtures/photo_feedback/`. Stub scorer: `App\Domains\AI\Eval\PhotoFeedbackScorer` (deterministic message checks; no vision).

To wire later (not done here):

1. Add `'photo_feedback' => $this->photoFeedbackRunner->run(...)` in `InterpretationEvalRunner` + load dir `fixtures/photo_feedback`.
2. `PhotoFeedbackCaseRunner`: resolve message via `PhotoContentAssessment::fromModelOutput` + `customerMessage()` (or fixture `code_path.current_customer_message` for offline), score with `PhotoFeedbackScorer`.
3. CLI: `--kind=photo_feedback`.

Until wired, Pest covers schema + current-message scoring (expects **wrong** on N16 mismatch fixtures — documents the bug).

## Per-case

| Case | Huidig (code) | Na fix |
|------|---------------|--------|
| `pf-n16-drain-livingroom-as-indoor` | outdoor-unit place copy; scorer **fail** on must_contain afvoer | afvoerplek-copy; pass |
| `pf-n16-drain-room-subject-match-no` | idem | afvoerplek-copy; pass |
| `pf-n16-drain-correct-downspout` | ok, geen message; **pass** | blijft pass |

## Measure after code/prompt apply

```bash
# N14 attention (real model):
AI_API_KEY=… php artisan eval:interpretation --migrate --repeats=3 --kind=report_claims

# N16 message path (Pest, no API):
./vendor/bin/pest tests/Unit/AI/PhotoFeedbackScorerTest.php tests/Unit/AI/PhotoFeedbackFixtureTest.php
```

When `photo_feedback` is wired: `php artisan eval:interpretation --migrate --kind=photo_feedback`.
