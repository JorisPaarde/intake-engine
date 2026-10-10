# Evaluatieset tekstinterpretatie (BL-148)

Meet `model_raw` vs `pipeline_final` op fixtures. Productpad: betekenis zit in het model (ADR-0016); code bewaakt schema/citaat/enums. Nieuwe formulering → prompt/schema/deze set, geen regex in productie. FakeAiClient is **geen** baseline.

## Fixtures

- `fixtures/request_texts/` — aanvraagteksten + expected feiten
- `fixtures/customer_answers/` — klantantwoorden (hoogte/nok/knieschot)
- `fixtures/photo_observations/` — observatietekst + model_fields uit traces
- `fixtures/meta.json` — enumwaarden / source_kinds
- `source_kind=reconstructed` → later vervangen via `eval:import-traces`

## Draaien (echte baseline) — alleen `AI_API_KEY`

Op een machine met werkende Laravel-DB (of `--migrate` + sqlite) en je prod/staging-`.env` (OpenRouter-model + budget):

```bash
AI_API_KEY=… php artisan eval:interpretation --migrate --repeats=1
```

Dat is genoeg. De command:

- zet `AI_PROVIDER=openai` als die nog `null`/`fake` is;
- vult OpenRouter + `google/gemini-3.1-flash-lite` alleen in bij inerte defaults (`api.openai.com` / `gpt-4o-mini`);
- zet tekst-/foto-/dossier-inferentie aan voor de run;
- zet een dagbudget van 200 cent als er geen budgetcap in config staat;
- laat bestaande prod-`AI_MODEL` / `AI_BASE_URL` / budget **ongewijzigd**.

Zonder `AI_API_KEY`: automatisch FakeAiClient, rapport = **GEEN baseline**.

Optioneel (expliciet, zoals CI):

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
php artisan eval:interpretation --migrate --repeats=1
```

## Twee promptversies meten (v13 baseline vs v14)

Op branch met de nieuwe fixtures + v14-prompt. Scores landen per **prompt-hash** (overschrijven elkaar niet).

### 1) Baseline = request-prefill-v13 (#173, vóór promptwijziging)

```bash
git checkout origin/cursor/eval-interpretation-p1-eb52 -- app/Domains/AI/Prompts/request_prefill/
AI_API_KEY=… php artisan eval:interpretation --migrate --repeats=1
```

Verwacht o.a. `request-prefill-v13` en hash in de buurt van `40149ec64c94` (wijzigt als andere prompts meeveranderen).

### 2) Nieuwe versie = request-prefill-v14 (terugzetten + opnieuw)

```bash
git checkout HEAD -- app/Domains/AI/Prompts/request_prefill/
AI_API_KEY=… php artisan eval:interpretation --migrate --repeats=1
```

Of, als working tree schoon moet blijven na meting 1: `git restore app/Domains/AI/Prompts/request_prefill/`.

### 3) Vergelijken (optioneel)

```bash
php artisan eval:interpretation --fake --compare=tests/Eval/results/<datum>-<v13-hash>.json
```

(Gebruik liever de echte v14-run met `--compare=` naar het v13-resultaatbestand.)

`--repeats=1` houdt kosten laag; voor spreiding later `--repeats=3`.

## Promptwijziging meten (stap-voor-stap)

1. Wijzig alleen `app/Domains/AI/Prompts/<naam>/prompt.md` (+ bump `meta.php` versie). Geen code-regels als vangnet.
2. Draai `AI_API_KEY=… php artisan eval:interpretation --migrate --repeats=1` tegen het echte model.
3. Vergelijk: `php artisan eval:interpretation --compare=tests/Eval/results/<bestand>.json`.
4. Bekijk `tests/Eval/results/HISTORY.md` voor score per component per prompt-hash.
5. Alleen mergen als `model_raw` én `pipeline_final` verbeteren (of gelijk blijven) op de geraakte feiten.

## Output (waar scores landen)

| Bestand | Inhoud |
|---------|--------|
| `tests/Eval/results/<datum>-<prompt-hash>.{json,md}` | Volledige run per prompt-vingerafdruk |
| `tests/Eval/results/HISTORY.md` | Samenvatting per prompt-hash (component scores) |
| `storage/app/eval/<datum>-<sha>.{json,md}` | Kopie onder storage |
| `tests/Eval/baseline/<datum>-<sha>.{json,md}` | Kopie voor PR |

De artisan-output print `Prompt: request-prefill-vN — hash … → tests/Eval/results/…`.

## Import traces

```bash
php artisan eval:import-traces /pad/naar/export.jsonl
# of: php artisan eval:import-traces export.jsonl --dry-run
```

Niet tegen staging/prod draaien. Expected-feiten na import handmatig zetten.

## CI

Workflow `.github/workflows/eval-interpretation.yml` (`workflow_dispatch` + optionele schedule). Normale `ci.yml` hangt hier niet van af. Ontbreekt secret `AI_API_KEY` → job stopt met duidelijke melding. **Let op:** deze workflow staat nog niet op `main`; tot die merge werkt `workflow_dispatch` daar niet — lokaal meten met `AI_API_KEY` zoals hierboven.
