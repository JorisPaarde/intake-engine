# Evaluatieset tekstinterpretatie (BL-148)

Meet `model_raw` vs `pipeline_final` op fixtures. Productpad: betekenis zit in het model (ADR-0016); code bewaakt schema/citaat/enums. Nieuwe formulering → prompt/schema/deze set, geen regex in productie. FakeAiClient is **geen** baseline. **Alleen echte modelruns** committen naar `tests/Eval/baseline/*.md` + `HISTORY.md`-regel (geen JSON).

## Fixtures

- `fixtures/request_texts/` — aanvraagteksten + expected feiten
- `fixtures/customer_answers/` — klantantwoorden (hoogte/nok/knieschot)
- `fixtures/photo_observations/` — observatietekst + model_fields uit traces
- `fixtures/meta.json` — enumwaarden / source_kinds
- `source_kind=reconstructed` → later vervangen door echte geanonimiseerde prod-tekst (aparte importstap)

## Draaien (echte baseline) — lokale throwaway sqlite

**Nooit** tegen production/staging. De command weigert **volledig** als `APP_ENV` production of staging is (geen AI-runs/traces op live DB, geen shared daily budget). `migrate:fresh` alleen met expliciet `--migrate` en alleen op `DB_CONNECTION=sqlite`. Zonder `--migrate` én zonder airco-template: exit 1 met duidelijke fout.

1. Gebruik een **lokale throwaway sqlite** (leeg bestand is genoeg).
2. Kopieer uit prod/staging **alleen** AI-settings: `AI_MODEL`, `AI_BASE_URL`, budgetcaps — **niet** `DB_*` / app-key / storage van die omgeving.
3. Zet de OpenRouter-key als `AI_API_KEY` in de shell (geen `OPENROUTER_API_KEY`-alias):

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
- zet tekst-inferentie aan voor de run (geen photo/dossier-overrides);
- zet een dagbudget van 200 cent als er geen budgetcap in config staat;
- laat bestaande prod-`AI_MODEL` / `AI_BASE_URL` / budget **ongewijzigd** als die al gezet zijn.

Zonder `AI_API_KEY`: automatisch FakeAiClient, rapport = **GEEN baseline** (niet committen).

## Meten op deze branch

`request_prefill` staat op **v13** (identiek aan #173). Geen aparte promptversie: de winst van deze branch zit in fixtures + eval-harness (migrate-guard, key-only bootstrap), niet in een promptbump. Floor-regressie uit de klanttest zit in de classifier-rewrite op main; #173 haalt die weg.

```bash
AI_API_KEY=… php artisan eval:interpretation --migrate --repeats=1
```

`--repeats=1` houdt kosten laag; voor spreiding later `--repeats=3`. Prompt-hash bij v13: typisch `40149ec64c94`.

## Promptwijziging meten (stap-voor-stap)

1. Wijzig alleen `app/Domains/AI/Prompts/<naam>/prompt.md` (+ bump `meta.php` versie). Geen code-regels als vangnet.
2. Draai tegen het echte model op lokale sqlite (`--migrate --repeats=1`).
3. Vergelijk: `--compare=tests/Eval/results/<bestand>.json` (lokaal; JSON niet committen).
4. Bekijk `tests/Eval/results/HISTORY.md` voor score per component per prompt-hash.
5. Alleen mergen als `model_raw` én `pipeline_final` verbeteren (of gelijk blijven) op de geraakte feiten.

## Output (waar scores landen)

| Bestand | Inhoud |
|---------|--------|
| `tests/Eval/baseline/<datum>-<sha>.md` | Gecommitte **echte** baseline (alleen markdown) |
| `tests/Eval/results/HISTORY.md` | Samenvatting per prompt-hash (alleen echte runs) |
| `tests/Eval/results/<datum>-<prompt-hash>.{json,md}` | Lokale volledige run (JSON **niet** committen) |
| `storage/app/eval/<datum>-<sha>.{json,md}` | Lokale kopie onder storage (niet in git) |

## CI

Workflow `.github/workflows/eval-interpretation.yml` (`workflow_dispatch`, `permissions: contents: read`). `repeats` via env, alleen gehele getallen 1–10. Normale `ci.yml` hangt hier niet van af. Ontbreekt secret `AI_API_KEY` → job stopt.
