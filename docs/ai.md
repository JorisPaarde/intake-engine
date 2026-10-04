# AI — Digitale Opname

> **Documentversie:** 3.39 · **Laatste update:** 2026-10-04 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Status: **samenvatting, aandachtspunten, lokale fotokwaliteit, tekst-/foto-afleiding, verbindingsgebonden routeanalyse en bewijsgerichte dossiersynthese zijn geïmplementeerd**. Externe provider en tekst-/foto-/route-/dossierinferentie staan standaard uit (provider + key + featurevlaggen + budgetcaps; soft-fail zonder die config). OpenAI-compatibele gateways (o.a. OpenRouter) via `AI_BASE_URL`.

De verplichte korte dossiersamenvatting is deterministisch en staat los van deze AI-laag. AI kan daarbovenop alleen een herkenbaar niet-bindend voorstel toevoegen.

## Wat AI wél mag

AI levert een herleidbare technische voorzet en mag werk actief overnemen:

- Samenvatting van antwoorden voor het interne rapport
- Voorstel voor aandachtspunten dat de installateur accepteert of verwijdert
- Signaleren van een onduidelijke meterkastfoto met een concrete nieuwe foto-opdracht
- Indicatie of een foto waarschijnlijk bruikbaar is
- Bevestigbare voorzet voor vrije groep en fase uit meterkastfoto's
- Uit één contextgebonden installateursfoto maximaal drie beslisrelevante technische constateringen voorstellen
- Bewijs uit aanvraag, BAG/PDOK, luchtfoto, EP-Online, 3DBAG, klant en installateur gezamenlijk analyseren
- Kandidaatposities en installatieopties voor airco voorstellen en rangschikken
- Koel-, condens- en stroomverbindingen met bewijs, onzekerheid en kostenimpact voorstellen
- Een hoge-confidence conclusie automatisch in het dossier toepassen zonder apart bevestigingsscherm
- De kleinste veilige vervolgopdracht kiezen die een blokkerende onzekerheid kan oplossen
- Bepalen welke uitzonderingen de installateur vóór offerte of plaatsing moet zien

## Wat AI níet mag

- Antwoorden van de klant overschrijven
- Taakvalidatie omzeilen of bewijs/conclusies zonder herkomst opslaan
- Zelfstandig elektrische veiligheid, definitieve uitvoerbaarheid, offerte of plaatsing goedkeuren
- Autonome chat die de flow overneemt zonder menselijke controle
- Persoonsgegevens (e-mail, telefoon, adres) of beeldbytes in AI-payloads/logs/DB zonder redactie
- De klant technische ontwerpkeuzes laten bevestigen die bij de installateur horen
- Lage zekerheid stil als feit toepassen of eindeloos om extra foto's blijven vragen

## Werking: bewijs → voorstel → uitzondering → beslissing

1. De opname start met bestaande aanvraaggegevens en de al gebouwde bronverrijking.
2. Klant- en/of installateursbijdragen leveren gericht bewijs bij ruimtes, plaatsingen en verbindingen.
3. AI analyseert bewijs per object en bewaart gevalideerde conclusies met model, prompt, evidence-referenties en zekerheid.
4. AI vormt maximaal drie installatieopties met afzonderlijke koel-, condens- en stroomroutes.
5. De beslisservice bepaalt welke onzekerheden oplossing, prijs, veiligheid of uitvoerbaarheid nog kunnen veranderen.
6. Alleen voor zo'n onzekerheid mag AI één concrete, veilige klant- of installateurstaak voorstellen.
7. De installateur beoordeelt het complete voorstel en de gemarkeerde uitzonderingen; zijn correctie/keuze is de gezaghebbende conclusie.

| Zekerheid en impact | Gedrag |
|---------------------|--------|
| Voldoet aan de objectspecifieke hoge-zekerheidsregel, geen relevant conflict | Automatisch toepassen; bron/evidence zichtbaar en eenvoudig corrigeerbaar; geen losse bevestiging. |
| Middel of conflict, kan besluit wijzigen | Als voorstel/uitzondering tonen of één gerichte taak maken. |
| Laag, besluit wordt niet geraakt | Niet toepassen en niet onnodig aan de gebruiker vragen. |
| Laag, besluit wordt wel geblokkeerd | Gerichte veilige taak; lukt die niet, onderbouwd locatiebezoek. |

De installateur hoeft dus niet alle AI-afleidingen veld voor veld te accepteren of verwijderen. De technische werkplek toont kandidaatopstellingen, beslisgebieden, uitzonderingen en klanttaakvoorstellen; alleen het integrale voorstel wordt gekozen of goedgekeurd. De historische aandachtspunten-UI blijft bestaan voor compatibiliteit.

Een model dat zelf `confidence=high` teruggeeft voldoet niet automatisch aan de hoge-zekerheidsregel. De server valideert per conclusie minimaal toegestane bronnen/evidence, volledigheid, tegenstrijdigheden en veiligheidsimpact.

## Architectuur (geïmplementeerd)

```
App\Domains\AI\
  Contracts\AiClientInterface
  Clients\NullAiClient | FakeAiClient | HeuristicAiClient
  Clients\OpenAiClient
  DTOs\AiImageInput | RequestIntentEvaluation | RequestPrefillCandidate
  Services\AiGateway
  Services\AiImageResolver
  Services\AiBudgetGuard
  Services\PromptVersionRepository
  Services\SurveySynthesisContextBuilder
  Services\AiEnumNormalizer | DossierSynthesisOutputNormalizer | DossierSynthesisPartialAcceptor | DossierSynthesisJsonSchema | AiValidationFailureFormatter
  Services\LocalRequestIntentParser | TemplateQuestionCatalogBuilder | RequestPrefillContextBuilder
  Services\RequestPrefillOutcomeClassifier | EvaluateRequestIntent
  Prompts\summary\ | attention_points\ | fusebox_assessment\
  Prompts\request_prefill\ | room_assessment\ | outdoor_assessment\
  Prompts\pipe_route_assessment\ | installer_photo_observation\
  Prompts\route_photo_analysis\ | route_synthesis\
  Prompts\dossier_synthesis\
  Actions\SummarizeIntake
  Actions\SuggestAttentionPoints | AssessFuseboxPhotos | DerivePhotoAnswers
  Actions\AssessFollowUpPhotoSubject | AssessPhotoUsability
  Actions\SuggestInstallerPhotoObservations
  Actions\DeriveIntentFromRequest | PrefillAnswersFromKnownContext
  Actions\AnalyzeRoutePhoto | SynthesizePipeRoute
  Actions\SynthesizeSurveyDossier
  Jobs\SummarizeIntakeJob | SynthesizeSurveyDossierJob | AssessUploadedPhotoJob
  Models\AiRun | AiTrace | AiTraceStep
  Services\AiTraceRecorder | AiTraceHandle | AiTraceRedactor | AiTraceSnapshotService
```

Provider via `.env`: `AI_PROVIDER`, `AI_API_KEY`, `AI_BASE_URL`, `AI_MODEL`, optioneel `AI_VISION_MODEL`, `AI_TIMEOUT_SECONDS`, `AI_DOSSIER_TIMEOUT_SECONDS` (default 45, queue-job). Multimodale wizardafleiding vereist `AI_PHOTO_INFERENCE_ENABLED=true`; routeanalyse `AI_ROUTE_ANALYSIS_ENABLED=true`; integrale dossiersynthese `AI_DOSSIER_SYNTHESIS_ENABLED=true`. Alle staan standaard uit. Dossiersynthese gebruikt maximaal `AI_DOSSIER_MAX_IMAGES` (default 12) relevante analysekopieën. `AI_PROVIDER=openai` valt door de budgetguard fail-closed als er geen dag- of maandcap is gezet.

| Provider | Gedrag |
|----------|--------|
| `null` (default) | Soft-fail; afronding/rapport blijven intact |
| `fake` | Vaste testdata (Pest) |
| `heuristic` | Lokale deterministische samenvatting + aandachtspunten, geen externe API |
| `openai` | Externe OpenAI-compatibele provider (BL-006), inclusief OpenRouter. **Standaard uit**; vereist `AI_API_KEY` (+ `AI_BASE_URL`/`AI_MODEL`) en budgetcaps. Optioneel `AI_VISION_MODEL` voor calls met beelden; optioneel `AI_HTTP_REFERER`/`AI_APP_TITLE` (OpenRouter-attributie). PII wordt vóór verzending geredigeerd (`AiInputRedactor`); bij fout/timeout → soft-fail; key nooit in exceptiontekst |

### OpenRouter

Zet `AI_PROVIDER=openai`, `AI_BASE_URL=https://openrouter.ai/api/v1`, `AI_API_KEY` op de OpenRouter-key, en `AI_MODEL` op een OpenRouter-model-id (bijv. `google/gemini-2.5-flash-lite` — multimodal, structured JSON, goedkoop). Voor foto's: zelfde id of apart `AI_VISION_MODEL`. Route-/dossiermodellen (`AI_ROUTE_MODEL`, `AI_ROUTE_REVIEW_MODEL`, `AI_DOSSIER_MODEL`) moeten ook bestaande OpenRouter-ids zijn; de app-defaults (`gpt-5.6-terra` e.d.) werken daar niet. Optioneel: `AI_HTTP_REFERER` + `AI_APP_TITLE` voor leaderboard-attributie.

Kernintake hangt **niet** van AI af. Klant-, installateur- en gerichte bijdrageafronding dispatchen de passende jobs ná commit; falen = `ai_runs.status=failed` + privacyveilige log en blokkeert het dossier niet.

## Budgetguard

Alle betaalde externe calls lopen door `OpenAiClient`, dus één guard dekt samenvatting, aandachtspunten, tekstafleiding, foto-afleiding, routeanalyse/-synthese en dossiersynthese. De guard doet vóór de HTTP-call een budgetcheck (spent + reserve) en gooit een normale `AiClientException` wanneer de cap ontbreekt of bereikt is. Na de call wordt de **werkelijke** geschatte kost geboekt — de reserve is geen bodem meer. Callers behandelen budgetfouten als soft-fail: intake, upload, dossier en review blijven bruikbaar.

Env-vars:

```env
AI_BUDGET_DAILY_CENTS=500
AI_BUDGET_MONTHLY_CENTS=5000
AI_BUDGET_RESERVE_CENTS_PER_CALL=1
AI_BUDGET_INPUT_CENTS_PER_1K_TOKENS=...
AI_BUDGET_OUTPUT_CENTS_PER_1K_TOKENS=...
AI_BUDGET_IMAGE_CENTS_PER_IMAGE=...
```

- `AI_BUDGET_ENFORCED=true` is de default. Zet dit alleen bewust uit voor lokale experimenten zonder echte providerkosten.
- Minstens één van `AI_BUDGET_DAILY_CENTS` of `AI_BUDGET_MONTHLY_CENTS` moet staan voordat `AI_PROVIDER=openai` calls doet.
- De pre-call check telt geslaagde/partial OpenAI-runs sinds dag-/maandstart plus `AI_BUDGET_RESERVE_CENTS_PER_CALL`.
- Met geconfigureerde token-/beeldtarieven boekt de guard de **fractionele** kost (bijv. 0,3 cent). Opslag: `estimated_cost_microcents` (1 cent = 10_000) plus `estimated_cost_cents` als ceiling voor weergave.
- Zonder tarieven (lege env): legacygedrag — elke call boekt de reserve; éénmalige log-warning.
- Na (gedeeltelijk) succes bewaart `ai_runs` provider-usage (`input_tokens`, `output_tokens`, `total_tokens`), `image_count` en de kostvelden. Dossiersynthese schrijft usage ook bij validatiefouten/partials.
- `/dev` toont provider/model/tekst-/foto-/routeflags en budgetcaps zonder API-key; `/dev/ai-runs` toont token- en kostengebruik per run; `/dev/ai-traces` toont de volledige request→response→parse→dossier-keten (BL-116).

## AI-traces (BL-116 + BL-125 + BL-132 + BL-144)

Doel: per mislukte/onjuiste/overgeslagen uitkomst aantonen of de fout in model, prompt, parser, opslag of klantflow zit. Elke AI-call schrijft bij `succeed()`/`fail()`/`skip()` één `ai_traces`-rij (+ bulk `ai_trace_steps`) — mid-flight alleen in-memory. Correlatie via `correlation_id` / optioneel `parent_trace_id` over upload → provider → dossierupdate → restvragen; `request_id` is eerst HTTP/Livewire/`job:{id}` en wordt na een providerresponse overschreven met de completion-`id` (OpenRouter/OpenAI); `provider_response_id` bewaart die id expliciet. Zelfde completion-`id` landt op `ai_runs.provider_request_id`.

| Veldgroep | Inhoud |
|-----------|--------|
| Identiteit | `trace_id` (UUID), `correlation_id` (per request/upload-keten), `request_id`, `provider_response_id`, optioneel `parent_trace_id`, nullable `intake_id` (`nullOnDelete`), denormalised `intake_ref_id` + `is_demo`, optioneel `upload_id` / `ai_run_id` / subject |
| Call | `call_type` (`text_extraction`, `request_intent`, `photo_derive`, `photo_assess`, `follow_up_photo_subject`, `summary`, `attention_points`, `dossier_synthesis`, `route`, `route_review`; legacy `photo_analysis`/`synthesis` blijven leesbaar), tijdstippen, status (`pending`/`succeeded`/`failed`/`skipped`) |
| Provider | provider, werkelijk model-ID, `model_parameters` (model, temperature, max_tokens, seed, response_format/schema=promptversie), promptversie, fallback/`retry_count`/`attempt`, `finish_reason`, tokens, `estimated_cost_cents` + fijnmazige `estimated_cost` (provider `usage.cost`) |
| Request | `request_snapshot` (system/user/context, geredigeerd); `photo_refs` (upload id, filename, question key, dims — nooit base64) |
| Response | `raw_response`, `parsed_response`, `validation_errors`, `normalizations` (lijst `{field, from, to, rule}` via `normalizeWithDiff`), `field_outcomes` (overgenomen/afgewezen + reden/confidence/bron) |
| Effect | `dossier_before`/`dossier_after` (+ `changed_fields`), `remaining_questions_before`/`after` via `IntakeStepBuilder::buildCatalog` (reasons + next unanswered visible) |
| P2-timings | `persist_ms` / `network_upload_ms` / `queue_wait_ms` (dispatch→job-start) / `queued_at`, `preprocess_ms`, `provider_ms`, `process_ms` (`stopProcessTimer` vóór after-snapshots) |

**Retentie vs demo-purge:** `ai_traces.intake_id` is nullable met `nullOnDelete`. Bij hard-delete van een demo-intake blijven traces staan met `intake_ref_id` + `is_demo`. Bewaartermijn alleen via daily `ai:purge-traces` (`AI_TRACE_RETENTION_DAYS`, default 30). **`ai_runs` blijven cascadeOnDelete** — dat zijn operationele/idempotente apply-records die zonder intake geen betekenis hebben; duurzame diagnostiek zit in `ai_traces`.

**Geïnstrumenteerde acties:** `PrefillAnswersFromKnownContext` (`text_extraction`), `DeriveIntentFromRequest` (`request_intent`), `DerivePhotoAnswers` (`photo_derive`), `AssessFuseboxPhotos` / `AssessPhotoUsability` / `SuggestInstallerPhotoObservations` (`photo_assess`), `AssessFollowUpPhotoSubject` (`follow_up_photo_subject`), `SummarizeIntake` (`summary`), `SuggestAttentionPoints` (`attention_points`), `SynthesizeSurveyDossier` (`dossier_synthesis`), `AnalyzeRoutePhoto` + primaire `SynthesizePipeRoute` (`route`), route-escalatie (`route_review`). Foto-refs via `AiTracePhotoRefBuilder`. Transactiestappen via `beginBuffer()`/`flushBuffer()`/`discardBuffer()`. `succeed()`/`fail()`/`skip()` vullen verplichte velden (tokens/kosten/provider_ms e.d.) met veilige defaults wanneer een lokale/heuristic/skip-call geen providerresultaat heeft. Queue-jobs (`AssessUploadedPhotoJob`) zetten `queue_wait_ms` + `queued_at` + `attempt` in Context vóór trace-start. Uploads zonder `photo_analysis`-profiel of follow-up zonder subject-check schrijven via `AiSkipRecorder` een `ai_runs.status=skipped` + trace (`error_message` = reden, bv. `geen beoordelingsprofiel`).

**Export:** `php artisan ai:traces:export {--intake=*} {--since=} {--until=} {--demo-only} {--format=jsonl\|md} {--output=}`. `--intake` accepteert komma’s en herhaalde flags; één run bundelt meerdere intakes. Zonder `--format` schrijft beide. Output default `storage/app/exports/`. Relatief `--output` is t.o.v. die map; een voorvoegsel `exports/` of `storage/app/exports/` wordt weggestript (geen verdubbeling). Console toont aan het eind de absolute paden van alle geschreven bestanden. Per call: call type, promptversie, model/`model_parameters` (incl. seed), gemaskeerde request, photo refs, raw/parsed, validation/normalizations, tokens, durations (`queue_wait_ms`/`queued_at`/`provider_ms`/…), `finish_reason`, `estimated_cost`+cents, status/error, `request_id`/`provider_response_id`/`correlation_id`, attempt/retry. Markdown: index (intakes, call count, total cost) + heading per intake + JSON-fenced subsections. JSONL: één regel per call met intake id. Masking opnieuw via `AiTraceRedactor` als safety net (incl. GPS/EXIF-locatie). Auto-split ≈1 MB of ≈200k tokens (4 chars/token) op intakegrenzen (anders callgrenzen) als `-partK-of-N` + `manifest.json`. Werkt ook voor gepurgede demo-intakes (`intake_ref_id`).

**Helper voor parallelle stromen:** `AiTraceRecorder::start($intake, AiTraceCallType::…)` bouwt een **unsaved** `AiTrace` met `intake_ref_id`/`is_demo`/`request_id`/`correlation_id`; `$trace->step(…)`, `recordFieldOutcomes`, `recordDossierSnapshots($before, $after, $changedFields)`, `succeed()` / `fail($msg, $exception)` / `skip($reason)` schrijven pas.

Beveiliging: `AiTraceRedactor` verwijdert API-keys, Bearer-headers, klantlinktokens (`/o/…`), e-mail, strikt NL-telefoon (+31/06/vast), bekende namen/adressen (intake-context) plus NL straat+huisnummer-patronen, GPS/EXIF-locatievelden en coördinaatparen, en base64-beelden. **Geen** false positives op losse huisnummers, m²-waarden of technische IDs (`intake_id`, `upload_id`, `*_m2`, …). Kill switch: `AI_TRACING_ENABLED=false` → no-op handle, geen writes. Trace-fouten worden gerapporteerd en genegeerd (nooit business-flow). Inzage via `/dev/ai-traces` alleen met `DEV_ADMIN_ENABLED` **én** e-mail op `DEV_ADMIN_EMAILS` (anders 403), of CLI `ai:traces` / `ai:traces:export`. Een mislukte foto-/meterkast-call **invalideert geen** bestaande AI-antwoorden meer vóór een geslaagde providerresponse.

## Datastructuur `ai_runs`

| Kolom | Doel |
|-------|------|
| `intake_id` | Koppeling |
| `type` | o.a. `summary`, `attention_points`, `photo_quality`, `photo_assessment`, `route_analysis`, `route_synthesis`, `dossier_synthesis` |
| `provider` | bv. `heuristic` / `fake` / `null` |
| `model` | modelidentifier |
| `prompt_version` | versiestring (`summary-v1`) |
| `provider_request_id` | nullable; provider-completion-`id` (BL-125/BL-144; via `completionResultAttributes`) |
| `input_hash` | sha256 van gereduceerde input (geen raw PII in logs) |
| `output` | json (gestructureerd, gevalideerd) |
| `status` | `pending` / `succeeded` / `partial` / `failed` / `skipped` |
| `error_message` | nullable; bij `partial` samenvatting van afgewezen items; bij `skipped` de skip-reden |
| `input_tokens` / `output_tokens` / `total_tokens` | providerusage, nullable |
| `image_count` | aantal meegestuurde beelden |
| `estimated_cost_cents` | ceiling in hele centen (weergave) |
| `estimated_cost_microcents` | fractionele budgettelling (1 cent = 10_000) |
| `started_at` / `finished_at` | |

## Geïmplementeerde flows

1. Klant rondt af → `CompleteIntake` schrijft snapshot + HTML-rapport.
2. `SummarizeIntakeJob` (queue) → `SummarizeIntake`.
3. Bij succes: `generated_reports.meta.ai_summary` + HTML-blok **“AI-voorstel (niet bindend)”**.
4. Bij falen: rapport ongewijzigd; intake blijft `completed`.

Foto-afleiding loopt tijdens de meterkastupload, zodat de voorzet op de eerstvolgende vraag beschikbaar is:

1. Lokale bruikbaarheidscheck blijft altijd beschikbaar en stuurt niets extern.
2. Alleen bij `AI_PHOTO_INFERENCE_ENABLED=true` stuurt `AssessFuseboxPhotos` maximaal twee private **analysevarianten** als base64 data-URL naar de gekozen multimodale provider.
3. Server-side validatie accepteert alleen `yes|no|unknown`, `one_phase|three_phase|unknown`, zekerheid, zichtbaar bewijs en een optionele concrete herhaalinstructie.
4. Alleen `confidence=high` en `free_group=yes|no` schrijft een antwoord met `prefill_source=ai`; een bestaand klant-/installateurantwoord wordt nooit overschreven.
5. Bij hoge zekerheid vervalt de redundante vraag; bron, bewijs en zekerheid blijven in het dossier. Middelmatige foto-afleidingen blijven als zichtbare voorzet controleerbaar.
6. De waarneming staat apart in `intake_external_facts` met `AI-fotoanalyse`, runreferentie, provider/model, gebruikte upload-id's en altijd "te controleren". Verwijderen van de bronfoto maakt de afleiding ongeldig en verwijdert de AI-voorzet.

Een installateursfoto bij een ruimte of positie gebruikt daarnaast een apart, kleiner contract:

1. `SuggestInstallerPhotoObservations` ontvangt alleen het onderwerpstype, allowlisted typecontext en precies één metadata-vrije analysekopie; vrije ruimte- of positienamen gaan niet mee.
2. Prompt `installer-photo-observation-v1` mag maximaal drie korte Nederlandse constateringen teruggeven die zichtbaar invloed kunnen hebben op haalbaarheid, materiaal, prijs of montage. Decoratieve details en definitieve elektrische veiligheidsuitspraken zijn verboden; een lege lijst is geldig.
3. Servervalidatie accepteert alleen de vier impactcategorieën en bewaart uitsluitend voorstellen op of boven `AI_PHOTO_OBSERVATION_MIN_CONFIDENCE` (default `0.65`).
4. Ieder voorstel landt als `proposed` dossierrecord met bewijslinks naar foto en AI-run. **Klopt** of **Aanpassen** maakt een nieuw gezaghebbend installateursrecord en supersedeert het voorstel; AI maakt nooit zelf een definitieve installateursconstatering.
5. Demo-opnames slaan deze call over. Bij uitgeschakelde AI, providerfout of ongeldige uitvoer blijft de foto gewoon bruikbaar en verschijnt geen voorstel.
6. Foto’s bij een verbinding blijven in de afzonderlijke routeanalyse; zo krijgt hetzelfde routesegment niet twee concurrerende AI-flows.

Dossiersynthese loopt na iedere afgeronde klant-, installateur- of gerichte bijdrage en kan ook bewust vanuit de technische werkplek worden gestart:

1. `DossierManager` synchroniseert antwoorden, bronnen, uploads en klantbijdragen; `SurveySynthesisContextBuilder` voegt gewenste ruimtes, dossierrecords, bestaande posities, opties en verbindingen toe.
2. Identiteit, adres, coördinaten, geometrie, opslagpaden en ongecontroleerde identifiers worden verwijderd. Maximaal twaalf relevante dossierfoto's gaan als analysevariant mee, evenwichtig over dossieronderwerpen.
3. Prompt `dossier-synthesis-v7` mag alleen beeldgebonden kandidaatposities voorstellen met geldige onderwerp-/ruimte- en `dossier_image:*`-referenties; enumvelden (o.a. `length_class`, `cost_impact`, `status`) moeten exacte tokens zijn zonder synoniemen of haakjes. Connections gebruiken `placement:ID`/`proposal:sleutel` (nooit `room:ID`); `evidence_references` min. 1; opties streven naar koel+condens+stroom (server dropte incomplete opties partial — geen harde min:3 die de hele synthese faalt). Klanttaken mogen geen technische beslissingen vragen (pomp, afschot, doorboring, route, elektrische geschiktheid) — alleen foto’s of feitelijke waarnemingen.
4. De provider-call gebruikt strikte structured output (`response_format.json_schema`, `strict: true`) via `DossierSynthesisJsonSchema` voor niet-Google-modellen. **Google/Gemini via OpenRouter** krijgt `json_object` (schema-keywords als `pattern`/`minItems`/`maxLength` gaven HTTP 400; prod intake 95/run 357). Het wire-schema bevat geen die unsupported keywords; cardinaliteit/patterns blijven server-side. Timeout: `AI_DOSSIER_TIMEOUT_SECONDS` (default 45), los van de web-`AI_TIMEOUT_SECONDS`.
5. Vóór acceptatie normaliseert `DossierSynthesisOutputNormalizer` afwijkende enumstrings. `DossierSynthesisPartialAcceptor` valideert daarna **per** placement/option/connection/exception/task: geldige items blijven, ongeldige worden gedropt met reden in `ai_traces.validation_errors`/`field_outcomes` en een samenvatting in `ai_runs.error_message`. Eenduidige `subject:N`-refs in option placement/connection-velden worden omgezet naar de bijbehorende placement (prod run-243); incomplete connection-sets (o.a. run-336 met 2 connections) droppen alleen die optie. Een optie met ongeldige connections valt weg als optie; geldige placements blijven. Status `succeeded` (alles ok) of `partial` (minstens één voorstel behouden); alleen bij nul geldige voorstellen `failed`. AI maakt nooit een definitieve installateursbevinding.
6. Een geldige/partial run vervangt alleen eerdere nog-kandidaat AI-posities/-opties en nog-voorgestelde AI-taken. Geselecteerde of menselijke objecten blijven staan.
7. AI-klanttaken blijven `proposed`; pas na installateurscontrole maakt de app de beperkte klanttaak en activeert zij toegang. Geen AI-actie keurt verbindingen of offertebesluiten goed.
8. Vlak vóór opslag wordt dezelfde geschoonde context inclusief beeldmanifest onder de intake-lock opnieuw gehasht. Een stale resultaat wordt niet toegepast.

## Openingszin: lokaal én catalogus-AI (ADR-0013/0014)

`DeriveIntentFromRequest` volgt een hybrid pad. Eerst past de bevroren `LocalRequestIntentParser` (`request-intent-local-v4`) alleen foutloze evidente feiten toe: koel-/verwarmdoelen (inclusief `koud te krijgen`), éénduidige aantallen/ruimtetypen en “op zolder”. Zelfde kamertype twee keer noemen (vaak maten naderhand) is geen lokale high-confidence — dat bepaalt catalogus-AI. Geen lokale maat-, buitenunit- of andere keuzeheuristiek.

Daarna, met `AI_TEXT_INFERENCE_ENABLED` aan en externe calls toegestaan, beoordeelt `PrefillAnswersFromKnownContext` de volledige fillable vraagenset via `request-prefill-v7`: openingszin, antwoorden, externe feiten en installateursobservaties. Per vraag alleen cataloguskeys/opties; `high` → `prefill_source=ai_text`, `medium` → `ai_text_suggestion`, `low` → niets. Fotovragen worden niet ingevuld. De prompt telt herhaalde kamernamen niet dubbel; neemt letterlijke L×B over of vult `room_area_m2` bij exact m² (geen m²→L×B). Exact AI-m² telt alleen bij hoge zekerheid + evidence (`RoomAreaAcceptance`). Ownership-synoniemen worden server-side genormaliseerd (`OwnershipNormalizer`).
Daarna, met `AI_TEXT_INFERENCE_ENABLED` aan en externe calls toegestaan, beoordeelt `PrefillAnswersFromKnownContext` de volledige fillable vraagenset via `request-prefill-v10`: openingszin, antwoorden, externe feiten en installateursobservaties. Per fill: `provenance` (`stated`/`inferred`/`unknown`) + confidence (high/medium/low → 0–100 via `FactAcceptance`) + `fact_source` (`klantantwoord`/`foto`/`afgeleid`) + evidence. Server-side: `stated`-evidence moet als genormaliseerde substring in `request_reason` staan; anders → `inferred` en confidence onder `config('intake.fact_confidence_threshold')` (default 80, env `INTAKE_FACT_CONFIDENCE_THRESHOLD`, optioneel per veld). Alleen stated + klantantwoord/foto + ≥ drempel → `prefill_source=ai_text` (known-summary/skip); anders `ai_text_suggestion` + bevestiging (“Klopt dit?”). Dossier toont percentage + bron (“afgeleid, niet bevestigd”) + status “nog te bevestigen”. Ontbrekende provenance op risicokeys (`ownership`, `noise_sensitive`, technische keuzes) default naar inferred (`RiskRelevantPrefillKeys`). Fotovragen worden niet ingevuld.

`RequestPrefillOutcomeClassifier` verwerkt catalogusoutput **soft**: te lange top-level `evidence` (>500) of fill-evidence (>300) wordt ingekort met normalisatie + `validation_errors` in de AI-trace; één kapotte fill (ongeldige confidence, scalar `value`, onbekende key) wordt rejected met reden terwijl andere fills doorgaan. Alleen een ontbrekende `fills`-array blijft een harde `ValidationException`. Prefill-apply vangt per-veld writefouten af zodat één mislukte opslag de rest niet terugdraait; harde fouten gebruiken `AiValidationFailureFormatter` in `ai_runs.error_message`.

Herbeoordeling (ADR-0014) gebeurt opnieuw wanneer de context groeit: na adresverrijking (aanmaak én retry), bij opslaan van de openingszin, en na een installateursnotitie of aangepaste constatering. Ongewijzigde context herhaalt geen provider-call (inputhash).

De lokale run bewaart alleen parserversie, inputhash, gecontroleerde output en toegepaste vraagsleutels; de vrije openingszin komt niet in activity-properties. Afgeleide antwoorden krijgen `prefill_source=request_text`. De klantlink-herstelpass zet externe calls expliciet uit (`allowExternal: false`) en draait alleen de lokale heuristiek.

Normalisatie en classificatie (fill / voorzet / afgewezen + reden) zitten in `RequestPrefillOutcomeClassifier`; `EvaluateRequestIntent` draait dezelfde keten dry-run voor Dev-admin **AI-invoer testen** (BL-104) zonder intakes, antwoorden, AI-runs, mail of jobs. Productie past alleen fill/suggestion toe via `DeriveIntentFromRequest` / `PrefillAnswersFromKnownContext`.

## Promptversionering

- Prompts in `app/Domains/AI/Prompts/{name}/prompt.md` + `meta.php`
- `prompt_version` opgeslagen per run
- Wijziging = bump version in meta

## Structured output

`OpenAiClient` stuurt standaard `response_format: {type: json_object}`. Callers die een JSON Schema meegeven (dossiersynthese) krijgen `response_format: {type: json_schema, …}` **behalve** Google/Gemini-modellen (via OpenRouter), die `json_object` houden — strikte schemas met `pattern`/`minItems`/`maxLength` leverden HTTP 400. Het wire-schema is een Gemini-veilige subset (type/enum/required/additionalProperties); servervalidatie blijft leidend. Afgekapte JSON krijgt 1 retry en `error_class=truncated/provider_error`. HTTP-fouten nemen `error.message` van de provider over in `ai_runs.error_message` / de trace (zonder PII).

Samenvatting vereist:

```json
{ "summary": "…", "highlights": ["…"] }
```

Server-side validatie vóór opslaan. Ongeldige output = `failed` (of `partial` bij dossiersynthese met nog geldige voorstellen).

## Privacy

- Input voor AI-aandachtspunten wordt door `IntakeAttentionContextBuilder` als één technisch dossier samengesteld: antwoorden met vraag-/sectielabels en prefillbron, automatisch verzamelde technische feiten (waarde, bron, zekerheid), uploads met MIME/omvang/kwaliteitsverdict, gerichte vervolgrondes, deterministische aandachtspunten, volledigheid, eerdere installateursreview en leidingroutes met segmentanalyses. Klantidentiteit, adresvelden, opslagpaden, bestandsbytes, coördinaten, geometrie/bounding boxes en locatie-identifiers worden niet opgenomen. Gevoelige facttypen (`location`, `parcel_ids`, `aerial_image`) worden volledig uitgesloten; nested en dotted keys voor URL's, BAG-hrefs, geometrie, coördinaten en enkel-/meervoudige ID-velden (`*_id`, `*_ids`) worden recursief verwijderd. Objectgebonden evidence gebruikt stabiele HMAC-referenties; interne database-ID's zijn niet terug te rekenen en worden niet verzonden. De builder begrenst aantallen, vrije tekst en het totale JSON-payload; bij overschrijding wordt veilig afgekapt. Eerdere AI-aandachtspunten worden niet als bron teruggevoerd, om zelfversterking te voorkomen.
- Extra redactielaag (`AiInputRedactor`) verwijdert e-mail/telefoon uit vrije tekst vóór verzending naar een externe provider. Restrisico (willekeurige NAW in vrije tekst) blijft; stuur geen adres/contact mee in prompts.
- Vision-acties lezen via `AiImageResolver`: nieuwe uploads gebruiken uitsluitend de metadata-vrije 1536px-analysevariant; historische rijen zonder variant hebben een expliciet gelabelde dossierfallback. De zwaardere Sol-routeherbeoordeling krijgt maximaal vier relevante, bruikbare segmentbeelden met de laagste zekerheid, nooit alle foto's blind opnieuw.
- Beeldbytes bestaan alleen in het uitgaande providerrequest. `ai_runs` bewaart een hash van promptversie + variantchecksums; database, activity-events en logs bevatten geen beeldbytes of data-URL. Afgeleide feiten bevatten alleen gecontroleerde waarden, korte bewijsomschrijving, provider/model en interne bewijsreferenties.
- Geen API-keys in logs of git (`.env`)
- De externe `openai`-provider staat standaard uit; activering is env-only (`AI_PROVIDER=openai` + key + budgetcaps + de gewenste featurevlaggen). Tests draaien met gemockte HTTP.
- `SurveySynthesisContextBuilder` hergebruikt expliciet de begrensde en geteste legacy-redactie voor gedeelde antwoord-/broncontext en voegt alleen allowlisted dossier- en aircovelden toe. Dossierobjectreferenties zijn interne runreferenties; klantidentiteit, adres, locatiegeometrie en opslagpaden ontbreken.
- Dossierobjecten verwijzen naar bestaand bewijs. AI-output mag geen kopie van klantfoto's, bronbeelden of onbeperkte vrije tekst in nieuwe JSON-velden opslaan.

## Aandachtspunten-voorstellen (BL-007)

- Systeemaandachtspunten zijn deterministisch en staan los van AI. BL-034 voegt bij meer dan één gewenste ruimte `review_split_configuration` toe, zodat de installateur één multi-split versus meerdere single-splits beoordeelt zonder de klant een technische keuze te laten maken. Dit punt is direct gezaghebbend (`source=system`), maar blijft alleen een controlepunt en geen definitief installatieadvies.
- `SuggestAttentionPoints` (mirror van `SummarizeIntake`) leidt via de gekozen provider aandachtspunten af; `HeuristicAiClient` doet dit deterministisch en lokaal. Prompt: `attention_points` (versioned).
- Voorstellen landen als `intake_attention_points` met `source=ai`, `status=proposed`. De installateur **accepteert** (→ `accepted`, komt in het rapport) of **verwijdert** (→ `dismissed`) ze op de opnamepagina. Alleen `accepted` (en system/reviewer) punten staan in het rapport.
- Idempotent en database-uniek op `(intake, source, code)`: automatische heranalyse dupliceert niet en respecteert een eerdere accept/dismiss-beslissing. Queuejobs voor dezelfde intake gebruiken `WithoutOverlapping`. Alle writers van providercontext (antwoorden, uploads, follow-ups, verrijkingsfeiten, reviews, foto-afleidingen en leidingroutes), voorstelopslag en installateursbeslissingen locken eerst dezelfde intake-row en daarna pas childrecords. Externe providercalls blijven buiten transacties. Vlak vóór voorstelopslag wordt onder de intake-lock de actuele begrensde context opnieuw gehasht; wijkt die af van `ai_runs.input_hash`, dan is het providerresultaat stale en wordt niets toegepast. `SuggestAttentionPointsJob` wordt automatisch gepland bij de eerste afronding én opnieuw na iedere afgeronde aanvullende ronde. Er is geen genereer-/opnieuw-knop of handmatige endpoint; de installateur beoordeelt alleen de voorstellen. Contextbouw, hashing, providercall, validatie en opslag vallen allemaal binnen de soft-failgrens; een fout blokkeert de kernflow niet.
- Prompt `attention_points-v3` beoordeelt het volledige dossier integraal. Elk voorstel bevat verplicht `confidence` en minimaal één concrete `evidence`-referentie. Elke combinatie van `source_type` en `reference` wordt server-side gecontroleerd tegen exact de naar de provider verzonden context; onbekende of verkeerd getypeerde modelreferenties maken de run ongeldig. Geldige provenance wordt machineleesbaar opgeslagen en vóór acceptatie getoond. Legacy AI-voorstellen zonder valide confidence/evidence worden tijdens de hardeningmigratie verwijderd en zijn ook server-side niet accepteerbaar. De prompt moet bronconflicten, onzekerheden en ontbrekende gegevens expliciet signaleren zonder afleidingen als bevestigde feiten te presenteren.
- Rapportrebuilds en AI-samenvattingspersistentie locken de intake en laden aandachtspunten opnieuw, zodat een stale relation-cache een recente installateursbeslissing niet kan overschrijven. Na acceptatie wordt de HTML direct herbouwd en een nieuwe PDF-job ingepland.

## Foto-categorie en stelligheid (BL-119 / BL-121 / BL-126 / BL-127)

- Foto-afleiding (`DerivePhotoAnswers`, `AssessFuseboxPhotos`, `AssessFollowUpPhotoSubject`) draait **niet** in de Livewire-webrequest maar in `AssessUploadedPhotoJob` (queue `ai-photo`, unique per upload). De uploadrequest slaat alleen op + lokale usability (`AssessPhotoUsability`) en returnt meteen. Wizard: fases Uploaden → Foto beoordelen; resultaat via `wire:poll.2s` (`pollPendingAssessments`) zonder page refresh.
- **Watchdog-veiligheid (BL-134):** `photos:requeue-pending-assessments` (scheduler `everyFiveMinutes`) herqueued alleen uploads die de app-pipeline zelf dispatchte (`assessment_attempts >= 1` via `PhotoAssessmentLifecycle::dispatch`), binnen `ai.photo_assessment.watchdog_max_age_hours` (default 24), op intakes in de klantfase (`sent`/`in_progress`/`awaiting_customer`), met cap `watchdog_max_per_run` (default 20). Submitted/closed/purged uploads worden **niet** aangeraakt (geen seal, geen dispatch). Legacy backfill-pending (`attempts=0`) wordt genegeerd; migratie `2026_10_03_220000_skip_legacy_pending_photo_assessments` zet pending om naar terminal (`assessed` bij bestaande content, anders `not_assessed`) en laat `assessment_status=NULL` met rust. Op submitted/closed intakes doet de job `sealPreservingContent` — alleen pipeline-status, geen AI-call, geen `content_assessment`-overwrite, geen activity-event.
- Beoordeelt **elke upload zonder definitieve assessment** (ook buiten het `max_images`-venster); `not_assessed` mag opnieuw. Fabriek: `PhotoContentAssessment::fromModelOutput`. Job: `$tries=2`, backoff, timeout > `AI_TIMEOUT_SECONDS`.
- Elke AI-fotobeoordeling schrijft precies één complete `ai_traces`-rij (`call_type=photo_analysis`) gekoppeld aan haar `ai_run` (model/provider/ms). Upload-persist traceert geen losse `photo_analysis` meer.
- **Routevragen (`pipe_route_photos`):** geaccepteerde categorieën zijn `pipe_route`, `room` (wand/plafond), `outdoor_unit` en `outdoor_location`. Wand/plafond/goot/doorvoer (ook met bestaande unit in beeld) telt als bruikbaar. Een herkende `pipe_route` **blokkeert nooit** (`wrong_subject`/`needs_clearer` worden onderdrukt). Prompt `pipe-route-assessment-v4`.
- Overige derive/fusebox: `subject_match=no` → `wrong_subject` (detected of `other`), nooit `ok`. AI-fout, timeout of inference uit → `not_assessed` (nooit null) met klanttekst “We konden je foto nu niet automatisch beoordelen; de installateur kijkt mee.” Klantmelding bij mismatch noemt het ontbrekende onderdeel (bij route: wand/plafond, goot of doorvoer).
- Klant: `wrong_subject` soft-blockt verplichte foto’s — **Vervang foto** / **Toch doorgaan**. Banner verdwijnt zodra `PhotoContentSatisfaction` tevreden is; verkeerde foto houdt installateursbadge. Definitieve assessments worden niet overschreven; `not_assessed` wel herbeoordeeld.
- `retake_instruction` op een bruikbare match → `needs_clearer` (ongeacht confidence), behalve op een geaccepteerde routefoto.
- Meterkast-mismatch zet **geen** `fusebox_clarity=needs_clearer_photo`; één taak: vervang de foto.
- Meterkastprompt `fusebox-assessment-v4` (BL-133): `empty_module_space` i.p.v. free_group-gok; wrong-subject → `confidence=low`; **nooit** `free_group_known` uit foto.
- Ruimteprompt `room-assessment-v7` (BL-133): `glazing_type`; glas/zon mogen `unknown`; size-banden = `RoomAreaAcceptance`. `room_outlet_status=unknown` schrijft geen antwoord en triggert geen `wall_outlet_photo`. Ruimteprompt `room-assessment-v8` (BL-137): `extra_overview_needed` → interne `room_extra_overview_needed`; alleen `needs_photo` toont `indoor_unit_position_photo` (airco v24).
- Technische routeconclusies staan alleen als dossierfeit (`pipe_route_photos_derivation`). Model-`drillings_needed=no` → `unknown` + voorstelnotitie.
- Interne velden `fusebox_clarity` / `room_outlet_status` nooit in klantstappen (`InternalCustomerQuestions`). Routevoorstellen via `TechnicalDecisionKeys::ROUTE_PROPOSAL_KEYS` (één class met #115-KEYS/`aiPrefillSources()`).
- Follow-up: accepted subjects per `decision_area_key` (power→fusebox; refrigerant→pipe_route|outdoor_unit|room|outdoor_location). Prompt `follow-up-photo-subject-v2`. Beoordeling via `AssessUploadedPhotoJob` (queue `ai-photo`). Onopgeloste `wrong_subject` telt niet mee voor follow-up-100% (`FollowUpProgressCalculator` → “Nog te vervangen”); voortgang wacht op `content_assessment` van de job. Installateur ziet mismatch-reden via `followUpMismatchReason` (BL-123). **Aanvulling versturen** blokkeert tot vervangen of **Toch versturen** (BL-130); `not_assessed` blijft soft; na latere OK-foto verdwijnt mismatch-reden en telt follow-up-powerfoto voor `hasFuseboxPhoto` (BL-130). Klantprompt bij mismatch-blocker = `customerRetakePrompt` (nooit de interne diagnose).
- Classificatiecalls (foto-afleiding, meterkast, follow-up subject) zetten `AiCompletionRequest::$temperature` op `config('ai.classification_temperature')` (default `0`). Standaardtekst blijft `config('ai.temperature')` (default `0.2`). OpenAiClient gebruikt `$request->temperature` wanneer gezet.
- `DecisionReadinessService::hasFuseboxPhoto` en voortgang gebruiken `PhotoContentSatisfaction`; follow-up OK-foto’s tellen mee.- Hosting/cron: zie `docs/DEPLOYMENT.md` § Cron (`--queue=ai-photo,default` + hourly scheduler-worker).

## Catalogus-prefill: eigendom en kamernamen (BL-126)

- Prompt `request-prefill-v7` forceert ownership-tokens `owned`/`rented` met NL-voorbeelden (koophuis, eigen woning, we huren, …) en neemt rollen letterlijk over in `room_name` (Ouders, Kind, …).
- Code-side `OwnershipNormalizer` in `RequestPrefillOutcomeClassifier` map’t synoniemen deterministisch vóór catalogusvalidatie (AI-trace normalisatie `ownership_synonym`).
- `syncRooms` zet `room_name`-antwoorden door naar `airco_rooms.name` / dossierlabels; `name_source=installer` wint altijd. Bekende ownership/`room_name` (AI of klant) worden niet opnieuw gevraagd (`skip_when_prefilled_by` + `shouldAskRoomName`).

## Fotokwaliteit (BL-007)

- `AssessPhotoUsability` beoordeelt elke geüploade foto **lokaal met GD** (`PhotoUsabilityHeuristic`): te donker of te lage resolutie → verdict op `intake_uploads.usability_verdict`. Geen externe API. Soft-fail (ontbrekend bestand, GD-fout): altijd fallback `ok` via `updateQuietly` — nooit `NULL` laten staan (anders herqueuet `recoverUnassessedUploads` eindeloos).
- Klantflow: niet-blokkerende hint bij de fotostap ("foto lijkt te donker — maak er eventueel nog één"); blokkeert afronden **nooit** (ADR-0004/0005). Installateur: subtiel kwaliteitslabel in de galerij. `AiRun` type `photo_quality` per beoordeling.

## Verbindingsgebonden routebackend (BL-029 → BL-040)

De bestaande stateful route-analyse beoordeelt per foto of wand/doorvoer zichtbaar is en of een route naar buiten aannemelijk is, vat segmenten samen tot een voorgestelde + alternatieve route met onzekerheden en ontbrekende controles, en kan één gerichte vervolgfoto voorstellen. De installateur keurt de route zelf goed (`ApprovePipeRoute`).

- **Contracten (float-confidence):** `route_photo_analysis` (per foto) en `route_synthesis` (route uit segmenten) — gestructureerde JSON, promptmappen onder `app/Domains/AI/Prompts/`.
- **Persistentie:** `pipe_route_sessions` + `pipe_route_segments` (elke foto = één segment met volledige analyse-JSON).
- **Modeltiering, los van `ai.model`:** `config('ai.route.model')` (default `gpt-5.6-terra`) doet de analyse; de synthese escaleert bij lage zekerheid of een niet-doorlopende route naar `config('ai.route.review_model')` (default `gpt-5.6-sol`). Model-ID's env-overschrijfbaar (`AI_ROUTE_MODEL`/`AI_ROUTE_REVIEW_MODEL`); de AI-laag heeft hiervoor een per-call `model`-override.
- **Gated + soft-fail:** achter `AI_ROUTE_ANALYSIS_ENABLED` (standaard uit) plus provider/key/budgetcaps. `ai_runs`-types `route_analysis` en `route_synthesis`.
- **Herijkt:** ADR-0009 is vervangen door ADR-0012 en BL-029 is `dropped` voor de resterende globale UI-scope. De backend blijft staan.
- **Verbindingskoppeling:** één routesessie is via een unieke FK gekoppeld aan één concrete `refrigerant`-, `condensate`- of `power`-verbinding binnen een installatieoptie. Nieuw bewijs heropent een eerder goedgekeurde sessie/verbinding veilig; synthese schrijft voorstel, onzekerheden en zekerheid terug naar die verbinding.
- **Beeldselectie:** per-fotoanalyse gebruikt de analysekopie. Alleen bij een onzekere/niet-doorlopende Terra-synthese krijgt Sol maximaal `AI_ROUTE_MAX_IMAGES` relevante analysekopieën; de inputhash bevat manifest en variant.
- **Grenzen:** de bestaande routeprompt is primair voor een leidingtracé ontworpen. Condens- en stroomverbindingen kunnen dezelfde bewijscontainer gebruiken, maar definitieve domeinspecifieke veiligheidscontrole blijft bij de installateur.

## Operationele gates en latere optimalisatie

- Externe foto-/route-inferentie op staging: zet `AI_PROVIDER=openai`, key, budgetcaps, daarna `AI_PHOTO_INFERENCE_ENABLED` / `AI_ROUTE_ANALYSIS_ENABLED`, en voer de functionele tests uit `functional-test-status.md` uit met fictieve representatieve beelden.
- Dossiersynthese afzonderlijk activeren met `AI_DOSSIER_SYNTHESIS_ENABLED`; controleer kosten, referentievalidatie en de installateursreview vóór productie.
- Een latere optimalisatie mag bij een aantoonbaar onleesbaar detail één crop of maximaal-2048px dossiervariant van precies die foto analyseren. Nooit alle originelen opnieuw; telefoonoriginelen bestaan niet op disk.


## Meterkast, glas en prefill (BL-133)

- Meterkastfoto levert `empty_module_space` + `phase`; **nooit** `free_group_known` (een foto ziet geen vrije groep). Wrong-subject → `confidence=low`.
- Ruimtefoto: `glazing_type`; `glass_amount`/`sun_exposure` mogen `unknown` (airco v23). Size-banden = `RoomAreaAcceptance` (<12 / ≤20 / >20).
- Catalogus-prefill weigert `cooling_heating` bij alleen “Nog geen airco”.
- Follow-upfoto’s: elke AI-call via trace handle; correlation per upload via `AiTraceRequestIdResolver::resolveCorrelationIdForUpload`; content_assessment altijd gezet (op BL-127 `upload_id`/lifecycle).
