# Evaluatieset tekstinterpretatie (BL-148)

Meet `model_raw` vs `pipeline_final` op fixtures. Productpad: betekenis zit in het model (ADR-0016); code bewaakt schema/citaat/enums. Nieuwe formulering → prompt/schema/deze set, geen regex in productie. FakeAiClient is **geen** baseline.

## Fixtures

- `fixtures/request_texts/` — aanvraagteksten + expected feiten
- `fixtures/customer_answers/` — klantantwoorden (hoogte/nok/knieschot)
- `fixtures/photo_observations/` — observatietekst + model_fields uit traces
- `fixtures/meta.json` — enumwaarden / source_kinds
- `source_kind=reconstructed` → later vervangen via `eval:import-traces`

## Draaien (echte baseline)

```bash
AI_PROVIDER=openai \
AI_BASE_URL=https://openrouter.ai/api/v1 \
AI_API_KEY=… \
AI_MODEL=google/gemini-3.1-flash-lite \
AI_VISION_MODEL=google/gemini-3.1-flash-lite \
AI_DOSSIER_MODEL=google/gemini-3.1-flash-lite \
AI_TEXT_INFERENCE_ENABLED=true \
AI_PHOTO_INFERENCE_ENABLED=true \
AI_DOSSIER_SYNTHESIS_ENABLED=true \
AI_BUDGET_DAILY_CENTS=200 \
php artisan eval:interpretation --migrate --repeats=3
```

Zonder `AI_API_KEY`: automatisch FakeAiClient, rapport = **GEEN baseline**.

## Promptwijziging meten (stap-voor-stap)

1. Wijzig alleen `app/Domains/AI/Prompts/<naam>/prompt.md` (+ bump `meta.php` versie). Geen code-regels als vangnet.
2. Draai `php artisan eval:interpretation --migrate --repeats=3` tegen het echte model.
3. Vergelijk: `php artisan eval:interpretation --compare=<vorige-datum-of-prompt-hash>` (of `--compare=tests/Eval/results/<bestand>.json`).
4. Bekijk `tests/Eval/results/HISTORY.md` voor score per component per prompt-hash.
5. Alleen mergen als `model_raw` én `pipeline_final` verbeteren (of gelijk blijven) op de geraakte feiten.

## Output

- `storage/app/eval/<datum>-<sha>.{json,md}`
- `tests/Eval/results/<datum>-<prompt-hash>.{json,md}` + `HISTORY.md`
- `tests/Eval/baseline/<datum>-<sha>.{json,md}` (kopie voor PR)

## Import traces

```bash
php artisan eval:import-traces /pad/naar/export.jsonl
# of: php artisan eval:import-traces export.jsonl --dry-run
```

Niet tegen staging/prod draaien. Expected-feiten na import handmatig zetten.

## CI

Workflow `.github/workflows/eval-interpretation.yml` (`workflow_dispatch` + optionele schedule). Normale `ci.yml` hangt hier niet van af. Ontbreekt secret `AI_API_KEY` → job stopt met duidelijke melding.
