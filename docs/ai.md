# AI — Digitale Opname

> **Documentversie:** 1.1 · **Laatste update:** 2026-07-17 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Status: **minimale Fase 6-slice geïmplementeerd** (samenvatting na afronding). Zie ADR-0005. Vervolgstappen: BL-006/BL-007 in [docs/backlog.md](backlog.md).

## Wat AI wél mag

Ondersteunende adviezen, nooit bron van waarheid:

- Samenvatting van antwoorden voor het interne rapport ✅
- Voorstel voor aandachtspunten (later)
- Signaleren van mogelijk ontbrekende informatie (aanvulling op CompletenessChecker; later)
- Indicatie of een foto waarschijnlijk bruikbaar is (later)

## Wat AI níet mag

- Antwoorden van de klant overschrijven
- Verplichte validatie of afronding bepalen
- Definitief technisch installatieadvies of offerte
- Autonome chat die de flow overneemt zonder menselijke controle
- Persoonsgegevens naar een provider sturen zonder DPIA/akkoord en redactiestrategie

## Architectuur (geïmplementeerd)

```
App\Domains\AI\
  Contracts\AiClientInterface
  Clients\NullAiClient | FakeAiClient | HeuristicAiClient
  Services\AiGateway
  Services\PromptVersionRepository
  Prompts\summary\          # versioned prompt + meta
  Actions\SummarizeIntake
  Jobs\SummarizeIntakeJob
  Models\AiRun
```

Provider via `.env`: `AI_PROVIDER`, `AI_API_KEY`, `AI_TIMEOUT_SECONDS`.

| Provider | Gedrag |
|----------|--------|
| `null` (default) | Soft-fail; afronding/rapport blijven intact |
| `fake` | Vaste testdata (Pest) |
| `heuristic` | Lokale deterministische samenvatting, geen externe API |

Kernintake hangt **niet** van AI af. `CompleteIntake` dispatcht `SummarizeIntakeJob` ná commit; falen = `ai_runs.status=failed` + log.

## Datastructuur `ai_runs`

| Kolom | Doel |
|-------|------|
| `intake_id` | Koppeling |
| `type` | `summary` / `attention_points` / `photo_quality` |
| `provider` | bv. `heuristic` / `fake` / `null` |
| `model` | modelidentifier |
| `prompt_version` | versiestring (`summary-v1`) |
| `input_hash` | sha256 van gereduceerde input (geen raw PII in logs) |
| `output` | json (gestructureerd, gevalideerd) |
| `status` | `pending` / `succeeded` / `failed` |
| `error_message` | nullable |
| `started_at` / `finished_at` | |

## Flow

1. Klant rondt af → `CompleteIntake` schrijft snapshot + HTML-rapport.
2. `SummarizeIntakeJob` (queue) → `SummarizeIntake`.
3. Bij succes: `generated_reports.meta.ai_summary` + HTML-blok **“AI-voorstel (niet bindend)”**.
4. Bij falen: rapport ongewijzigd; intake blijft `completed`.

## Promptversionering

- Prompts in `app/Domains/AI/Prompts/{name}/prompt.md` + `meta.php`
- `prompt_version` opgeslagen per run
- Wijziging = bump version in meta

## Structured output

Samenvatting vereist:

```json
{ "summary": "…", "highlights": ["…"] }
```

Server-side validatie vóór opslaan. Ongeldige output = `failed`.

## Privacy

- Input voor AI: antwoorden + attention-point codes + template-meta — geen e-mail/telefoon in de payload
- Geen API-keys in logs
- Externe LLM-providers bewust nog niet aangesloten (eerst DPIA)

## Volgende uitbreidingen

- `SuggestAttentionPoints` / `AssessPhotoUsability`
- Optionele OpenAI (of andere) client achter `AiClientInterface`
- Installateur: AI-voorstellen accepteren/verwijderen
