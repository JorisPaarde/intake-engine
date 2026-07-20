# AI — Digitale Opname

> **Documentversie:** 1.2 · **Laatste update:** 2026-07-18 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Status: **Fase 6 + BL-007 geïmplementeerd** — samenvatting, aandachtspunten-voorstellen (installateur accepteert/verwijdert) en lokale fotokwaliteit-check. Externe provider-client (BL-006) aanwezig maar **standaard uit** (DPIA + key vereist). Zie ADR-0005. Multimodale foto-afleiding: BL-020 in [docs/backlog.md](backlog.md).

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
| `heuristic` | Lokale deterministische samenvatting + aandachtspunten, geen externe API |
| `openai` | Externe OpenAI-compatibele provider (BL-006). **Standaard uit**; vereist `AI_API_KEY` (+ `AI_BASE_URL`/`AI_MODEL`) én DPIA/akkoord. PII wordt vóór verzending geredigeerd (`AiInputRedactor`); bij fout/timeout → soft-fail |

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

- Input voor AI: antwoorden + attention-point codes + template-meta — geen e-mail/telefoon als apart veld in de payload
- Extra redactielaag (`AiInputRedactor`) verwijdert e-mail/telefoon uit vrije tekst vóór verzending naar een externe provider. Restrisico (willekeurige NAW in vrije tekst) wordt in de DPIA afgewogen.
- Geen API-keys in logs of git (`.env`)
- De externe `openai`-provider staat standaard uit en wordt pas geactiveerd ná DPIA/akkoord (key in `.env`). Tests draaien met gemockte HTTP.

## Aandachtspunten-voorstellen (BL-007)

- `SuggestAttentionPoints` (mirror van `SummarizeIntake`) leidt via de gekozen provider aandachtspunten af; `HeuristicAiClient` doet dit deterministisch en lokaal. Prompt: `attention_points` (versioned).
- Voorstellen landen als `intake_attention_points` met `source=ai`, `status=proposed`. De installateur **accepteert** (→ `accepted`, komt in het rapport) of **verwijdert** (→ `dismissed`) ze op de opnamepagina. Alleen `accepted` (en system/reviewer) punten staan in het rapport.
- Idempotent op `(intake, code)`: opnieuw voorstellen dupliceert niet en respecteert een eerdere accept/dismiss-beslissing. Async na afronding (`SuggestAttentionPointsJob`) + on-demand knop. Provider `null` = soft-fail (geen voorstellen).

## Fotokwaliteit (BL-007)

- `AssessPhotoUsability` beoordeelt elke geüploade foto **lokaal met GD** (`PhotoUsabilityHeuristic`): te donker of te lage resolutie → verdict op `intake_uploads.usability_verdict`. Geen externe API.
- Klantflow: niet-blokkerende hint bij de fotostap ("foto lijkt te donker — maak er eventueel nog één"); blokkeert afronden **nooit** (ADR-0004/0005). Installateur: subtiel kwaliteitslabel in de galerij. `AiRun` type `photo_quality` per beoordeling.

## Volgende uitbreidingen

- BL-020: multimodale foto-afleiding tot bevestigbare voorzet (vereist externe multimodale LLM productief + DPIA).
- Activeren van de `openai`-provider ná DPIA/akkoord (key in `.env`).
