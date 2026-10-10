# Eval resultaatgeschiedenis (prompt-versies)

> Scores per component per prompt-hash. FakeAiClient = **GEEN baseline**; echte baseline vereist `AI_API_KEY`.

| Datum | Prompt-hash | Commit | Mode | Model | Opmerking |
|-------|-------------|--------|------|-------|-----------|
| 2026-10-08 | `952817c96df3` | `247f047` | fake | `google/gemini-3.1-flash-lite` | GEEN baseline — C1:63/131 C2:16/29 C3:48/80 C4:13/13 C5:7/9 C6:13/15 C7:25/28 C8:3/22 |
| 2026-10-10 | `40149ec64c94` | `6ee9878` | fake | `gpt-4o-mini` | **GEEN baseline, echte eval nog nodig** — request-prefill-v13 + 4 kt10-fixtures — C1:50/154 C2:10/39 C3:45/94 C4:12/13 C5:7/9 C6:14/15 C7:25/28 C8:3/22 |
| 2026-10-10 | `d51eee9d3cc8` | `d3487aa` | fake | `gpt-4o-mini` | **GEEN baseline, echte eval nog nodig** — request-prefill-v14 (gedeeltelijke verdieping + stated/high m²) — C1:50/154 C2:10/39 C3:45/94 C4:12/13 C5:7/9 C6:14/15 C7:25/28 C8:3/22 (fake-scores gelijk aan v13; geen echte modelmeting) |

### 2026-10-08 / 952817c96df3

| Component | model_raw | pipeline_final |
|-----------|-----------|----------------|
| C1 | 39/131 | 63/131 |
| C2 | 5/29 | 16/29 |
| C3 | 29/80 | 48/80 |
| C4 | 5/13 | 13/13 |
| C5 | 9/9 | 7/9 |
| C6 | 0/15 | 13/15 |
| C7 | 27/28 | 25/28 |
| C8 | 3/22 | 3/22 |

### 2026-10-10 / 40149ec64c94 (request-prefill-v13, GEEN baseline)

| Component | model_raw | pipeline_final |
|-----------|-----------|----------------|
| C1 | 50/154 | 50/154 |
| C2 | 10/39 | 10/39 |
| C3 | 45/94 | 45/94 |
| C4 | 12/13 | 12/13 |
| C5 | 9/9 | 7/9 |
| C6 | 14/15 | 14/15 |
| C7 | 27/28 | 25/28 |
| C8 | 3/22 | 3/22 |

### 2026-10-10 / d51eee9d3cc8 (request-prefill-v14, GEEN baseline)

| Component | model_raw | pipeline_final |
|-----------|-----------|----------------|
| C1 | 50/154 | 50/154 |
| C2 | 10/39 | 10/39 |
| C3 | 45/94 | 45/94 |
| C4 | 12/13 | 12/13 |
| C5 | 9/9 | 7/9 |
| C6 | 14/15 | 14/15 |
| C7 | 27/28 | 25/28 |
| C8 | 3/22 | 3/22 |
