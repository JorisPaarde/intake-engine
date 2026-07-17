# AI — Digitale Opname

Status: **nog niet geïmplementeerd**. Bewust uitgesteld tot na de deterministische MVP (Fase 6). Zie ADR-0005.

## Wat AI wél mag (later)

Ondersteunende adviezen, nooit bron van waarheid:

- Samenvatting van antwoorden voor het interne rapport
- Voorstel voor aandachtspunten
- Signaleren van mogelijk ontbrekende informatie (aanvulling op, niet vervanging van, CompletenessChecker)
- Indicatie of een foto waarschijnlijk bruikbaar is (scherpte/relevantie — advisory)

## Wat AI níet mag

- Antwoorden van de klant overschrijven
- Verplichte validatie of afronding bepalen
- Definitief technisch installatieadvies of offerte
- Autonome chat die de flow overneemt zonder menselijke controle
- Persoonsgegevens naar een provider sturen zonder DPIA/akkoord en redactiestrategie

## Voorgestelde servicearchitectuur

```
App\Domains\AI\
  Contracts\AiClientInterface
  Services\AiGateway          # logging, timeout, structured output
  DTOs\...
  Prompts\PromptVersionRepository   # geen prompts in controllers/views
  Actions\SummarizeIntake
  Actions\SuggestAttentionPoints
  Actions\AssessPhotoUsability
  Models\AiRun
```

Providerkeuze via `.env`: `AI_PROVIDER`, `AI_API_KEY` (placeholders bestaan al).

Kernintake (`Intake`-domein) hangt **niet** van AI af. Actions in Reports/Intake roepen AI optioneel aan; falen van AI = soft-fail + log.

## Datastructuur `ai_runs` (Fase 6)

| Kolom | Doel |
|-------|------|
| `intake_id` | Koppeling |
| `type` | `summary` / `attention_points` / `photo_quality` |
| `provider` | bv. `openai` |
| `model` | modelidentifier |
| `prompt_version` | versiestring |
| `input_hash` | reproduceerbaarheid zonder raw PII in logs |
| `output` | json (gestructureerd, gevalideerd) |
| `status` | `pending` / `succeeded` / `failed` |
| `error_message` | nullable |
| `started_at` / `finished_at` | |

## Logging & foutafhandeling

- Elke run = `ai_runs`-rij
- Geen API-keys in logs; minimale PII in prompt-logs (liever gehashte/geselecteerde velden)
- Timeouts en providerfouten: status `failed`, intake blijft bruikbaar
- Queue: AI-jobs asynchroon (`QUEUE_CONNECTION=database`)

## Promptversionering

- Prompts in versioned files (`app/Domains/AI/Prompts/v1/summary.md` + meta)
- `prompt_version` opgeslagen per run
- Wijziging prompt = bump version, geen stille overwrite van historische betekenis

## Structured output

- Provider JSON-schema / strict mode waar beschikbaar
- Server-side validatie (Form Request-achtige DTO + rules) vóór opslaan
- Ongeldige output = `failed`, geen partial write naar attention points zonder markering “AI-voorstel”

## Menselijke controle

- AI-aandachtspunten krijgen `source = system` of aparte flag `suggested_by_ai`
- Installateur vinkt af / verwijdert
- Rapport toont AI-tekst duidelijk als “voorstel”

## Privacyrisico’s

| Risico | Mitigatie |
|--------|-----------|
| Foto’s/PII naar externe API | Opt-in, redactie, minimale velden, verwerkersovereenkomst |
| Retentie bij provider | Zero-retention mode waar mogelijk; documenteer |
| Prompt injection via vrije tekst | Structured output; geen tool-calling dat data mutates |
| Logging van antwoorden | input_hash i.p.v. full dump in standaard logs |

## Implementatiestatus

| Onderdeel | Status |
|-----------|--------|
| Env placeholders `AI_PROVIDER` / `AI_API_KEY` | Aanwezig |
| `app/Domains/AI/` map | Leeg scaffold |
| Interface / jobs / `ai_runs` | Toekomstig (Fase 6) |
| Deterministische intake | Verplicht vóór AI |

## Fase 6 — minimale eerste slice

1. `AiClientInterface` + null/fake provider voor tests
2. `SummarizeIntake` job na afronding
3. Resultaat als adviesblok in HTML-rapport
4. Tests: AI-fout blokkeert afronding/rapportbasis niet
