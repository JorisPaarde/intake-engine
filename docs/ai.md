# AI — Digitale Opname

> **Documentversie:** 3.1 · **Laatste update:** 2026-07-30 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Status: **samenvatting, aandachtspunten, lokale fotokwaliteit, tekst-/foto-afleiding, verbindingsgebonden routeanalyse en bewijsgerichte dossiersynthese zijn geïmplementeerd**. Externe provider en tekst-/foto-/route-/dossierinferentie staan standaard uit (DPIA + key + budgetcaps + staging-smoke vereist).

De verplichte korte dossiersamenvatting is deterministisch en staat los van deze AI-laag. AI kan daarbovenop alleen een herkenbaar niet-bindend voorstel toevoegen.

## Wat AI wél mag

AI levert een herleidbare technische voorzet en mag werk actief overnemen:

- Samenvatting van antwoorden voor het interne rapport
- Voorstel voor aandachtspunten dat de installateur accepteert of verwijdert
- Signaleren van een onduidelijke meterkastfoto met een concrete nieuwe foto-opdracht
- Indicatie of een foto waarschijnlijk bruikbaar is
- Bevestigbare voorzet voor vrije groep en fase uit meterkastfoto's
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
- Persoonsgegevens naar een provider sturen zonder DPIA/akkoord en redactiestrategie
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
  DTOs\AiImageInput
  Services\AiGateway
  Services\AiImageResolver
  Services\AiBudgetGuard
  Services\PromptVersionRepository
  Services\SurveySynthesisContextBuilder
  Prompts\summary\ | attention_points\ | fusebox_assessment\
  Prompts\request_intent\ | room_assessment\ | outdoor_assessment\
  Prompts\pipe_route_assessment\ | route_photo_analysis\ | route_synthesis\
  Prompts\dossier_synthesis\
  Actions\SummarizeIntake
  Actions\SuggestAttentionPoints | AssessFuseboxPhotos | DerivePhotoAnswers
  Actions\AnalyzeRoutePhoto | SynthesizePipeRoute
  Actions\SynthesizeSurveyDossier
  Jobs\SummarizeIntakeJob | SynthesizeSurveyDossierJob
  Models\AiRun
```

Provider via `.env`: `AI_PROVIDER`, `AI_API_KEY`, `AI_TIMEOUT_SECONDS`. Multimodale wizardafleiding vereist `AI_PHOTO_INFERENCE_ENABLED=true`; routeanalyse `AI_ROUTE_ANALYSIS_ENABLED=true`; integrale dossiersynthese `AI_DOSSIER_SYNTHESIS_ENABLED=true`. Alle staan standaard uit. Dossiersynthese gebruikt maximaal `AI_DOSSIER_MAX_IMAGES` (default 12) relevante analysekopieën. `AI_PROVIDER=openai` valt door de budgetguard fail-closed als er geen dag- of maandcap is gezet.

| Provider | Gedrag |
|----------|--------|
| `null` (default) | Soft-fail; afronding/rapport blijven intact |
| `fake` | Vaste testdata (Pest) |
| `heuristic` | Lokale deterministische samenvatting + aandachtspunten, geen externe API |
| `openai` | Externe OpenAI-compatibele provider (BL-006). **Standaard uit**; vereist `AI_API_KEY` (+ `AI_BASE_URL`/`AI_MODEL`) én DPIA/akkoord. PII wordt vóór verzending geredigeerd (`AiInputRedactor`); bij fout/timeout → soft-fail |

Kernintake hangt **niet** van AI af. Klant-, installateur- en gerichte bijdrageafronding dispatchen de passende jobs ná commit; falen = `ai_runs.status=failed` + privacyveilige log en blokkeert het dossier niet.

## Budgetguard

Alle betaalde externe calls lopen door `OpenAiClient`, dus één guard dekt samenvatting, aandachtspunten, tekstafleiding, foto-afleiding, routeanalyse/-synthese en dossiersynthese. De guard doet vóór de HTTP-call een budgetcheck en gooit een normale `AiClientException` wanneer de cap ontbreekt of bereikt is. Callers behandelen dat als soft-fail: intake, upload, dossier en review blijven bruikbaar.

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
- De pre-call check telt geslaagde OpenAI-runs sinds dag-/maandstart plus `AI_BUDGET_RESERVE_CENTS_PER_CALL`.
- Na succes bewaart `ai_runs` de provider-usage (`input_tokens`, `output_tokens`, `total_tokens`), `image_count` en `estimated_cost_cents`. Als tokenusage ontbreekt, telt de reservering als minimum.
- `/dev` toont provider/model/tekst-/foto-/routeflags en budgetcaps zonder API-key; `/dev/ai-runs` toont token- en kostengebruik per run.

## Datastructuur `ai_runs`

| Kolom | Doel |
|-------|------|
| `intake_id` | Koppeling |
| `type` | o.a. `summary`, `attention_points`, `photo_quality`, `photo_assessment`, `route_analysis`, `route_synthesis`, `dossier_synthesis` |
| `provider` | bv. `heuristic` / `fake` / `null` |
| `model` | modelidentifier |
| `prompt_version` | versiestring (`summary-v1`) |
| `input_hash` | sha256 van gereduceerde input (geen raw PII in logs) |
| `output` | json (gestructureerd, gevalideerd) |
| `status` | `pending` / `succeeded` / `failed` |
| `error_message` | nullable |
| `input_tokens` / `output_tokens` / `total_tokens` | providerusage, nullable |
| `image_count` | aantal meegestuurde beelden |
| `estimated_cost_cents` | budgettelling in centen of budget-units |
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

Dossiersynthese loopt na iedere afgeronde klant-, installateur- of gerichte bijdrage en kan ook bewust vanuit de technische werkplek worden gestart:

1. `DossierManager` synchroniseert antwoorden, bronnen, uploads en klantbijdragen; `SurveySynthesisContextBuilder` voegt gewenste ruimtes, dossierrecords, bestaande posities, opties en verbindingen toe.
2. Identiteit, adres, coördinaten, geometrie, opslagpaden en ongecontroleerde identifiers worden verwijderd. Maximaal twaalf relevante dossierfoto's gaan als analysevariant mee, evenwichtig over dossieronderwerpen.
3. Prompt `dossier-synthesis-v2` mag alleen beeldgebonden kandidaatposities voorstellen met geldige onderwerp-/ruimte- en `dossier_image:*`-referenties.
4. Servervalidatie controleert enumwaarden, alle evidence-referenties, configuratiecardinaliteit, positiegrenzen, drie verbindingstypen en een eigen koel-/condensroute voor iedere binnenpositie.
5. Een geldige run vervangt alleen eerdere nog-kandidaat AI-posities/-opties en nog-voorgestelde AI-taken. Geselecteerde of menselijke objecten blijven staan.
6. AI-klanttaken blijven `proposed`; pas na installateurscontrole maakt de app de beperkte klanttaak en activeert zij toegang. Geen AI-actie keurt verbindingen of offertebesluiten goed.
7. Vlak vóór opslag wordt dezelfde geschoonde context inclusief beeldmanifest onder de intake-lock opnieuw gehasht. Een stale resultaat wordt niet toegepast.

## Openingszin: lokaal vóór externe AI

`DeriveIntentFromRequest` gebruikt eerst `LocalRequestIntentParser`. Die parser is bewust klein en deterministisch: hij herkent alleen expliciete Nederlandse koel-/verwarmdoelen, aantallen van één tot acht, bekende ruimtetypen en de expliciete ligging “op zolder”. De zin `Ik wil twee airco’s om m’n slaapkamers op zolder te koelen` levert daardoor lokaal koelen, twee slaapkamers en voor beide `floor_level=attic` op; “zolder” wordt niet als derde ruimte behandeld. Tegenstrijdige aantallen en onduidelijke doelen vallen terug op de normale vragen.

De lokale run bewaart alleen parserversie, inputhash, gecontroleerde output en toegepaste vraagsleutels; de vrije openingszin komt niet in activity-properties. Afgeleide antwoorden krijgen `prefill_source=request_text`. Dit pad draait direct na installateursaanmaak en als herstel bij een oudere actieve klantlink, ook wanneer `AI_PROVIDER=null` en `AI_TEXT_INFERENCE_ENABLED=false`.

Alleen als de lokale parser niets zekers vindt, mag de versioned `request_intent`-prompt naar de geconfigureerde provider. Dat externe fallbackpad blijft achter `AI_TEXT_INFERENCE_ENABLED`; de klantlink-herstelpass zet externe calls expliciet uit.

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

- Input voor AI-aandachtspunten wordt door `IntakeAttentionContextBuilder` als één technisch dossier samengesteld: antwoorden met vraag-/sectielabels en prefillbron, automatisch verzamelde technische feiten (waarde, bron, zekerheid), uploads met MIME/omvang/kwaliteitsverdict, gerichte vervolgrondes, deterministische aandachtspunten, volledigheid, eerdere installateursreview en leidingroutes met segmentanalyses. Klantidentiteit, adresvelden, opslagpaden, bestandsbytes, coördinaten, geometrie/bounding boxes en locatie-identifiers worden niet opgenomen. Gevoelige facttypen (`location`, `parcel_ids`, `aerial_image`) worden volledig uitgesloten; nested en dotted keys voor URL's, BAG-hrefs, geometrie, coördinaten en enkel-/meervoudige ID-velden (`*_id`, `*_ids`) worden recursief verwijderd. Objectgebonden evidence gebruikt stabiele HMAC-referenties; interne database-ID's zijn niet terug te rekenen en worden niet verzonden. De builder begrenst aantallen, vrije tekst en het totale JSON-payload; bij overschrijding wordt veilig afgekapt. Eerdere AI-aandachtspunten worden niet als bron teruggevoerd, om zelfversterking te voorkomen.
- Extra redactielaag (`AiInputRedactor`) verwijdert e-mail/telefoon uit vrije tekst vóór verzending naar een externe provider. Restrisico (willekeurige NAW in vrije tekst) wordt in de DPIA afgewogen.
- Vision-acties lezen via `AiImageResolver`: nieuwe uploads gebruiken uitsluitend de metadata-vrije 1536px-analysevariant; historische rijen zonder variant hebben een expliciet gelabelde dossierfallback. De zwaardere Sol-routeherbeoordeling krijgt maximaal vier relevante, bruikbare segmentbeelden met de laagste zekerheid, nooit alle foto's blind opnieuw.
- Beeldbytes bestaan alleen in het uitgaande providerrequest. `ai_runs` bewaart een hash van promptversie + variantchecksums; database, activity-events en logs bevatten geen beeldbytes of data-URL. Afgeleide feiten bevatten alleen gecontroleerde waarden, korte bewijsomschrijving, provider/model en interne bewijsreferenties.
- Geen API-keys in logs of git (`.env`)
- De externe `openai`-provider staat standaard uit en wordt pas geactiveerd ná DPIA/akkoord (key in `.env`). Tests draaien met gemockte HTTP.
- `SurveySynthesisContextBuilder` hergebruikt expliciet de begrensde en geteste legacy-redactie voor gedeelde antwoord-/broncontext en voegt alleen allowlisted dossier- en aircovelden toe. Dossierobjectreferenties zijn interne runreferenties; klantidentiteit, adres, locatiegeometrie en opslagpaden ontbreken.
- Dossierobjecten verwijzen naar bestaand bewijs. AI-output mag geen kopie van klantfoto's, bronbeelden of onbeperkte vrije tekst in nieuwe JSON-velden opslaan.

## Aandachtspunten-voorstellen (BL-007)

- Systeemaandachtspunten zijn deterministisch en staan los van AI. BL-034 voegt bij meer dan één gewenste ruimte `review_split_configuration` toe, zodat de installateur één multi-split versus meerdere single-splits beoordeelt zonder de klant een technische keuze te laten maken. Dit punt is direct gezaghebbend (`source=system`), maar blijft alleen een controlepunt en geen definitief installatieadvies.
- `SuggestAttentionPoints` (mirror van `SummarizeIntake`) leidt via de gekozen provider aandachtspunten af; `HeuristicAiClient` doet dit deterministisch en lokaal. Prompt: `attention_points` (versioned).
- Voorstellen landen als `intake_attention_points` met `source=ai`, `status=proposed`. De installateur **accepteert** (→ `accepted`, komt in het rapport) of **verwijdert** (→ `dismissed`) ze op de opnamepagina. Alleen `accepted` (en system/reviewer) punten staan in het rapport.
- Idempotent en database-uniek op `(intake, source, code)`: automatische heranalyse dupliceert niet en respecteert een eerdere accept/dismiss-beslissing. Queuejobs voor dezelfde intake gebruiken `WithoutOverlapping`. Alle writers van providercontext (antwoorden, uploads, follow-ups, verrijkingsfeiten, reviews, foto-afleidingen en leidingroutes), voorstelopslag en installateursbeslissingen locken eerst dezelfde intake-row en daarna pas childrecords. Externe providercalls blijven buiten transacties. Vlak vóór voorstelopslag wordt onder de intake-lock de actuele begrensde context opnieuw gehasht; wijkt die af van `ai_runs.input_hash`, dan is het providerresultaat stale en wordt niets toegepast. `SuggestAttentionPointsJob` wordt automatisch gepland bij de eerste afronding én opnieuw na iedere afgeronde aanvullende ronde. Er is geen genereer-/opnieuw-knop of handmatige endpoint; de installateur beoordeelt alleen de voorstellen. Contextbouw, hashing, providercall, validatie en opslag vallen allemaal binnen de soft-failgrens; een fout blokkeert de kernflow niet.
- Prompt `attention_points-v3` beoordeelt het volledige dossier integraal. Elk voorstel bevat verplicht `confidence` en minimaal één concrete `evidence`-referentie. Elke combinatie van `source_type` en `reference` wordt server-side gecontroleerd tegen exact de naar de provider verzonden context; onbekende of verkeerd getypeerde modelreferenties maken de run ongeldig. Geldige provenance wordt machineleesbaar opgeslagen en vóór acceptatie getoond. Legacy AI-voorstellen zonder valide confidence/evidence worden tijdens de hardeningmigratie verwijderd en zijn ook server-side niet accepteerbaar. De prompt moet bronconflicten, onzekerheden en ontbrekende gegevens expliciet signaleren zonder afleidingen als bevestigde feiten te presenteren.
- Rapportrebuilds en AI-samenvattingspersistentie locken de intake en laden aandachtspunten opnieuw, zodat een stale relation-cache een recente installateursbeslissing niet kan overschrijven. Na acceptatie wordt de HTML direct herbouwd en een nieuwe PDF-job ingepland.

## Fotokwaliteit (BL-007)

- `AssessPhotoUsability` beoordeelt elke geüploade foto **lokaal met GD** (`PhotoUsabilityHeuristic`): te donker of te lage resolutie → verdict op `intake_uploads.usability_verdict`. Geen externe API.
- Klantflow: niet-blokkerende hint bij de fotostap ("foto lijkt te donker — maak er eventueel nog één"); blokkeert afronden **nooit** (ADR-0004/0005). Installateur: subtiel kwaliteitslabel in de galerij. `AiRun` type `photo_quality` per beoordeling.

## Verbindingsgebonden routebackend (BL-029 → BL-040)

De bestaande stateful route-analyse beoordeelt per foto of wand/doorvoer zichtbaar is en of een route naar buiten aannemelijk is, vat segmenten samen tot een voorgestelde + alternatieve route met onzekerheden en ontbrekende controles, en kan één gerichte vervolgfoto voorstellen. De installateur keurt de route zelf goed (`ApprovePipeRoute`).

- **Contracten (float-confidence):** `route_photo_analysis` (per foto) en `route_synthesis` (route uit segmenten) — gestructureerde JSON, promptmappen onder `app/Domains/AI/Prompts/`.
- **Persistentie:** `pipe_route_sessions` + `pipe_route_segments` (elke foto = één segment met volledige analyse-JSON).
- **Modeltiering, los van `ai.model`:** `config('ai.route.model')` (default `gpt-5.6-terra`) doet de analyse; de synthese escaleert bij lage zekerheid of een niet-doorlopende route naar `config('ai.route.review_model')` (default `gpt-5.6-sol`). Model-ID's env-overschrijfbaar (`AI_ROUTE_MODEL`/`AI_ROUTE_REVIEW_MODEL`); de AI-laag heeft hiervoor een per-call `model`-override.
- **Gated + soft-fail:** achter `AI_ROUTE_ANALYSIS_ENABLED` (standaard uit); meer beeld naar een externe LLM valt onder de DPIA-voorwaarde (ADR-0005/0009). `ai_runs`-types `route_analysis` en `route_synthesis`.
- **Herijkt:** ADR-0009 is vervangen door ADR-0012 en BL-029 is `dropped` voor de resterende globale UI-scope. De backend blijft staan.
- **Verbindingskoppeling:** één routesessie is via een unieke FK gekoppeld aan één concrete `refrigerant`-, `condensate`- of `power`-verbinding binnen een installatieoptie. Nieuw bewijs heropent een eerder goedgekeurde sessie/verbinding veilig; synthese schrijft voorstel, onzekerheden en zekerheid terug naar die verbinding.
- **Beeldselectie:** per-fotoanalyse gebruikt de analysekopie. Alleen bij een onzekere/niet-doorlopende Terra-synthese krijgt Sol maximaal `AI_ROUTE_MAX_IMAGES` relevante analysekopieën; de inputhash bevat manifest en variant.
- **Grenzen:** de bestaande routeprompt is primair voor een leidingtracé ontworpen. Condens- en stroomverbindingen kunnen dezelfde bewijscontainer gebruiken, maar definitieve domeinspecifieke veiligheidscontrole blijft bij de installateur.

## Operationele gates en latere optimalisatie

- Externe foto-/route-inferentie pas op staging activeren na DPIA/akkoord, budgetcaps, fictieve representatieve beelden en de functionele tests uit `functional-test-status.md`.
- Dossiersynthese afzonderlijk activeren met `AI_DOSSIER_SYNTHESIS_ENABLED`; controleer kosten, referentievalidatie en de installateursreview vóór productie.
- Een latere optimalisatie mag bij een aantoonbaar onleesbaar detail één crop of maximaal-2048px dossiervariant van precies die foto analyseren. Nooit alle originelen opnieuw; telefoonoriginelen bestaan niet op disk.
