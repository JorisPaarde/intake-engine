# Evaluatieset tekstinterpretatie (BL-148)

Meet `model_raw` vs `pipeline_final` op fixtures. Productpad: betekenis zit in het model (ADR-0016); code bewaakt schema/citaat/enums. Nieuwe formulering → prompt/schema/deze set, geen regex in productie. FakeAiClient is **geen** baseline. **Alleen echte modelruns** committen naar `tests/Eval/results/` / `baseline/` / `HISTORY.md`.

## Fixtures

- `fixtures/request_texts/` — aanvraagteksten + expected feiten
- `fixtures/customer_answers/` — klantantwoorden (hoogte/nok/knieschot)
- `fixtures/photo_observations/` — observatietekst + model_fields uit traces
- `fixtures/meta.json` — enumwaarden / source_kinds
- `source_kind=reconstructed` → later vervangen via `eval:import-traces`

## Draaien (echte baseline) — lokale throwaway sqlite

**Nooit** `--migrate` tegen een prod/staging-database. `migrate:fresh` wist alles. De command weigert fresh-migrate als `APP_ENV` production/staging is of `DB_CONNECTION` niet `sqlite` is.

1. Gebruik een **lokale throwaway sqlite** (leeg bestand is genoeg).
2. Kopieer uit prod/staging **alleen** AI-settings: `AI_MODEL`, `AI_BASE_URL`, budgetcaps — **niet** `DB_*` / app-key / storage van die omgeving.
3. Zet de OpenRouter-key in de shell:

```bash
# Voorbeeld lokale .env (fragment):
# APP_ENV=local
# DB_CONNECTION=sqlite
# DB_DATABASE=/pad/naar/database/eval.sqlite
# AI_BASE_URL=https://openrouter.ai/api/v1   # gekopieerd uit prod
# AI_MODEL=google/gemini-3.1-flash-lite      # gekopieerd uit prod
# AI_BUDGET_DAILY_CENTS=200                  # gekopieerd of lokaal

AI_API_KEY=… php artisan eval:interpretation --migrate --repeats=1
```

Dat is genoeg. De command:

- zet `AI_PROVIDER=openai` als die nog `null`/`fake` is;
- vult OpenRouter + `google/gemini-3.1-flash-lite` alleen in bij inerte defaults (`api.openai.com` / `gpt-4o-mini`) en **waarschuwt** dan duidelijk;
- zet tekst-/foto-/dossier-inferentie aan voor de run;
- zet een dagbudget van 200 cent als er geen budgetcap in config staat;
- laat bestaande prod-`AI_MODEL` / `AI_BASE_URL` / budget **ongewijzigd** als die al gezet zijn.

Zonder `AI_API_KEY`: automatisch FakeAiClient, rapport = **GEEN baseline** (niet committen).

## Twee promptversies meten (v13 baseline vs huidige prompt)

Op deze branch. Scores landen per **prompt-hash** (overschrijven elkaar niet). Alleen op **lokale sqlite**.

### 1) Baseline = request-prefill-v13 (#173)

```bash
git checkout origin/cursor/eval-interpretation-p1-eb52 -- app/Domains/AI/Prompts/request_prefill/
AI_API_KEY=… php artisan eval:interpretation --migrate --repeats=1
```

### 2) Huidige prompt (request-prefill-v15) terugzetten + opnieuw

```bash
git checkout HEAD -- app/Domains/AI/Prompts/request_prefill/
AI_API_KEY=… php artisan eval:interpretation --migrate --repeats=1
```

`--repeats=1` houdt kosten laag; voor spreiding later `--repeats=3`.

## Promptwijziging meten (stap-voor-stap)

1. Wijzig alleen `app/Domains/AI/Prompts/<naam>/prompt.md` (+ bump `meta.php` versie). Geen code-regels als vangnet.
2. Draai tegen het echte model op lokale sqlite (`--migrate --repeats=1`).
3. Vergelijk: `--compare=tests/Eval/results/<bestand>.json`.
4. Bekijk `tests/Eval/results/HISTORY.md` voor score per component per prompt-hash.
5. Alleen mergen als `model_raw` én `pipeline_final` verbeteren (of gelijk blijven) op de geraakte feiten.

## Output (waar scores landen)

| Bestand | Inhoud |
|---------|--------|
| `tests/Eval/results/<datum>-<prompt-hash>.{json,md}` | Volledige **echte** run per prompt-vingerafdruk |
| `tests/Eval/results/HISTORY.md` | Samenvatting per prompt-hash (alleen echte runs) |
| `storage/app/eval/<datum>-<sha>.{json,md}` | Lokale kopie onder storage (niet verplicht in git) |
| `tests/Eval/baseline/<datum>-<sha>.{json,md}` | Kopie voor PR (alleen echte baseline) |

## Import traces

```bash
php artisan eval:import-traces /pad/naar/export.jsonl
```

Niet tegen staging/prod draaien. Expected-feiten na import handmatig zetten.

## CI

Workflow `.github/workflows/eval-interpretation.yml` (`workflow_dispatch`). Normale `ci.yml` hangt hier niet van af. Ontbreekt secret `AI_API_KEY` → job stopt. **Let op:** workflow staat nog niet op `main`.
