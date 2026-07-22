# AI — Digitale Opname

> **Documentversie:** 1.8 · **Laatste update:** 2026-07-22 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Status: **Fase 6 + BL-007 + BL-020 geïmplementeerd** — samenvatting, aandachtspunten, lokale fotokwaliteit en een bevestigbare multimodale meterkastvoorzet. Externe provider en foto-inferentie staan **standaard uit** (DPIA + key vereist). Zie ADR-0005. **Publieke demo** draait samenvatting + aandachtspunten wel: inline bij afronden, met heuristic-fallback als `AI_PROVIDER=null`, zichtbaar op het bedankt-scherm.

De verplichte korte dossiersamenvatting is deterministisch en staat los van deze AI-laag. AI kan daarbovenop alleen een herkenbaar niet-bindend voorstel toevoegen.

## Wat AI wél mag

Ondersteunende adviezen, nooit bron van waarheid:

- Samenvatting van antwoorden voor het interne rapport
- Voorstel voor aandachtspunten dat de installateur accepteert of verwijdert
- Signaleren van een onduidelijke meterkastfoto met een concrete nieuwe foto-opdracht
- Indicatie of een foto waarschijnlijk bruikbaar is
- Bevestigbare voorzet voor vrije groep en fase uit meterkastfoto's

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
  Clients\OpenAiClient
  DTOs\AiImageInput
  Services\AiGateway
  Services\PromptVersionRepository
  Prompts\summary\ | attention_points\ | fusebox_assessment\
  Actions\SummarizeIntake
  Actions\AssessFuseboxPhotos
  Jobs\SummarizeIntakeJob
  Models\AiRun
```

Provider via `.env`: `AI_PROVIDER`, `AI_API_KEY`, `AI_TIMEOUT_SECONDS`. Multimodale verzending vereist daarnaast `AI_PHOTO_INFERENCE_ENABLED=true`; standaard `false`, maximaal twee recente meterkastfoto's per beoordeling.

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
| `type` | `summary` / `attention_points` / `photo_quality` / `photo_assessment` |
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

Foto-afleiding loopt tijdens de meterkastupload, zodat de voorzet op de eerstvolgende vraag beschikbaar is:

1. Lokale bruikbaarheidscheck blijft altijd beschikbaar en stuurt niets extern.
2. Alleen bij `AI_PHOTO_INFERENCE_ENABLED=true` stuurt `AssessFuseboxPhotos` maximaal twee private afbeeldingen als base64 data-URL naar de gekozen multimodale provider.
3. Server-side validatie accepteert alleen `yes|no|unknown`, `one_phase|three_phase|unknown`, zekerheid, zichtbaar bewijs en een optionele concrete herhaalinstructie.
4. Alleen `confidence=high` en `free_group=yes|no` schrijft een antwoordvoorzet met `prefill_source=ai`; een bestaand klantantwoord wordt nooit overschreven.
5. De klant ziet "ingeschat op basis van uw meterkastfoto — klopt deze keuze?". Zelf kiezen bevestigt of corrigeert de voorzet en wist de AI-prefillmarkering.
6. De waarneming staat apart in `intake_external_facts` met `AI-fotoanalyse`, runreferentie, provider/model, gebruikte upload-id's en altijd "te controleren". Verwijderen van de bronfoto maakt de afleiding ongeldig en verwijdert de AI-voorzet.

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

- Input voor AI: antwoorden + automatisch verzamelde feiten (waarde, bron, zekerheid) + gerichte vervolgrondes (`round_number`, type, prompt, antwoord, foto-aantal) + attention-point codes + template-meta — geen e-mail/telefoon als apart veld in de payload. Externe feiten uit BL-019 en aanvullingen uit BL-027 worden zo met de oorspronkelijke intake gecombineerd zonder herkomstverlies.
- Extra redactielaag (`AiInputRedactor`) verwijdert e-mail/telefoon uit vrije tekst vóór verzending naar een externe provider. Restrisico (willekeurige NAW in vrije tekst) wordt in de DPIA afgewogen.
- Foto-inferentie verstuurt de beeldbytes alleen in het uitgaande providerrequest. `ai_runs` bewaart een hash van promptversie + uploadchecksums; database, activity-events en logs bevatten geen beeldbytes of data-URL. Het afgeleide feit bevat alleen gecontroleerde enums, korte bewijsomschrijving, provider/model en upload-id's.
- Geen API-keys in logs of git (`.env`)
- De externe `openai`-provider staat standaard uit en wordt pas geactiveerd ná DPIA/akkoord (key in `.env`). Tests draaien met gemockte HTTP.

## Aandachtspunten-voorstellen (BL-007)

- `SuggestAttentionPoints` (mirror van `SummarizeIntake`) leidt via de gekozen provider aandachtspunten af; `HeuristicAiClient` doet dit deterministisch en lokaal. Prompt: `attention_points` (versioned).
- Voorstellen landen als `intake_attention_points` met `source=ai`, `status=proposed`. De installateur **accepteert** (→ `accepted`, komt in het rapport) of **verwijdert** (→ `dismissed`) ze op de opnamepagina. Alleen `accepted` (en system/reviewer) punten staan in het rapport.
- Idempotent op `(intake, code)`: opnieuw voorstellen dupliceert niet en respecteert een eerdere accept/dismiss-beslissing. Async na afronding (`SuggestAttentionPointsJob`) + on-demand knop. Provider `null` = soft-fail (geen voorstellen).

## Fotokwaliteit (BL-007)

- `AssessPhotoUsability` beoordeelt elke geüploade foto **lokaal met GD** (`PhotoUsabilityHeuristic`): te donker of te lage resolutie → verdict op `intake_uploads.usability_verdict`. Geen externe API.
- Klantflow: niet-blokkerende hint bij de fotostap ("foto lijkt te donker — maak er eventueel nog één"); blokkeert afronden **nooit** (ADR-0004/0005). Installateur: subtiel kwaliteitslabel in de galerij. `AiRun` type `photo_quality` per beoordeling.

## Begeleide leidingroute (BL-029, ADR-0009)

Aparte, stateful route-analyse los van de bestaande foto-afleiding. Beoordeelt per foto of wand/doorvoer zichtbaar is en of een route naar buiten aannemelijk is, vat de segmenten samen tot een voorgestelde + alternatieve route met onzekerheden en ontbrekende controles, en vraagt steeds om één gerichte vervolgfoto. De installateur keurt de route altijd zelf goed (`ApprovePipeRoute`).

- **Contracten (float-confidence):** `route_photo_analysis` (per foto) en `route_synthesis` (route uit segmenten) — gestructureerde JSON, promptmappen onder `app/Domains/AI/Prompts/`.
- **Persistentie:** `pipe_route_sessions` + `pipe_route_segments` (elke foto = één segment met volledige analyse-JSON).
- **Modeltiering, los van `ai.model`:** `config('ai.route.model')` (default `gpt-5.6-terra`) doet de analyse; de synthese escaleert bij lage zekerheid of een niet-doorlopende route naar `config('ai.route.review_model')` (default `gpt-5.6-sol`). Model-ID's env-overschrijfbaar (`AI_ROUTE_MODEL`/`AI_ROUTE_REVIEW_MODEL`); de AI-laag heeft hiervoor een per-call `model`-override.
- **Gated + soft-fail:** achter `AI_ROUTE_ANALYSIS_ENABLED` (standaard uit); meer beeld naar een externe LLM valt onder de DPIA-voorwaarde (ADR-0005/0009). `ai_runs`-types `route_analysis` en `route_synthesis`.
- **UI:** installateur-goedkeuring (paneel op de opname-detailpagina) en klant-wizard (`/o/{token}/leidingroute`, `PipeRouteWizard`) zijn er; DPIA-activering + staging-validatie resteren (BL-029).

## Volgende uitbreidingen

- Externe foto-inferentie activeren en op staging met representatieve, fictieve meterkastfoto's valideren ná DPIA/akkoord (`AI_PROVIDER=openai`, key en `AI_PHOTO_INFERENCE_ENABLED=true`).
- Alleen na gemeten winst dezelfde veilige voorzetstructuur uitbreiden naar ruimte- of routefoto's; geen brede beeldherkenning zonder concrete geschrapte vraag.
