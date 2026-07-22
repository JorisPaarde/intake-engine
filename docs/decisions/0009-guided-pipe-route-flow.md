# ADR-0009: Begeleide leidingroute met multi-foto LLM-analyse en modeltiering

- **Status:** Accepted
- **Datum:** 2026-07-22

## Context

De oude `pipe_route_assessment` (ADR-0007-profiel) leidt uit één fotovraag een route-classificatie af (`pipe_route_description` e.d.) en *vervangt* daarmee vragen. Dat beantwoordt de verkeerde vraag: de installateur wil weten of de foto's genoeg laten zien over **waar stroom beschikbaar is** en **waar de verbinding tussen binnen- en buitenunit gemaakt kan worden** — een geschiktheids-/volledigheidsoordeel dat meerdere foto's en zwaarder redeneren vergt dan een enkel licht vision-model per fotovraag levert.

De producteigenaar vroeg om een **begeleide flow**: gebruiker markeert de binnenunit-positie, AI beoordeelt per foto of wand/doorvoer zichtbaar is en of een route naar buiten aannemelijk is, vraagt steeds om één gerichte vervolgfoto, koppelt elke foto aan een routesegment, en bepaalt of de segmenten samen één doorlopende route vormen. De installateur keurt de route altijd zelf goed.

## Beslissing

- **Nieuwe, parallelle capaciteit** naast de bestaande `pipe_route`-sectie (die blijft op gepinde templateversies staan, ADR-0001). Eigen persistentie: `pipe_route_sessions` + `pipe_route_segments`.
- **Twee gestructureerde JSON-contracten** met float-`confidence` (0..1), los van de high/medium/low-enum van de bestaande foto-afleiding: `route_photo_analysis` (per foto) en `route_synthesis` (route uit segmenten).
- **Eigen modelkeuze, los van het globale `ai.model`.** `config('ai.route.model')` (default `gpt-5.6-terra`) doet de standaardanalyse; bij lage zekerheid of een niet-doorlopende route escaleert de synthese naar `config('ai.route.review_model')` (default `gpt-5.6-sol`). Model-ID's zijn env-overschrijfbaar, zodat een nieuwe generatie zonder codewijziging in te zetten is. Hiervoor kreeg de AI-laag een per-call `model`-override (`AiCompletionRequest`/`AiGateway`/`OpenAiClient`).
- **Menselijke eindbeoordeling verplicht:** AI levert alleen een voorzet (voorgestelde + alternatieve route, onzekerheden, ontbrekende controles); `ApprovePipeRoute` legt de expliciete goed-/afkeuring van de installateur vast. Dit volgt het vaste ontwerpprincipe "AI blijft ondersteunend".
- **Soft-fail en gated:** alle AI-calls staan achter `AI_ROUTE_ANALYSIS_ENABLED` (standaard uit) en falen zacht; de intake-flow hangt er nooit van af.

## Privacy / DPIA

Deze flow stuurt **meerdere** (interieur- en gevel)foto's naar een externe LLM — een bredere gegevensstroom dan de bestaande meterkast-/kamervoorzet. Dat valt onder dezelfde DPIA-voorwaarde als ADR-0005: activeren pas na akkoord en met key, en alleen op een omgeving met een passende grondslag. Redactie van tekstinvoer blijft via `AiInputRedactor`; er gaan geen persoonsgegevens, gezichten of documenttekst mee in de prompts (promptregels), en `intake_activity_events` bewaart alleen keys/status, nooit route-inhoud (ADR-0002).

## Alternatieven

| Alternatief | Afgewezen omdat |
|-------------|-----------------|
| Bestaande `pipe_route_assessment` uitbreiden | Dat contract classificeert een route en vervangt vragen; het geschiktheidsoordeel over stroom + verbinding vergt een ander contract, meerdere foto's en zwaarder redeneren |
| Eén model voor alles (globaal `ai.model`) | De complexe route-analyse rechtvaardigt een capabeler (duurder) model, terwijl de simpele voorzetten op een licht model horen te blijven — kostenbewust |
| Altijd het zwaarste model | Onnodig duur voor de vele eenvoudige gevallen; escalatie alleen bij twijfel is goedkoper en even betrouwbaar |
| AI de route laten vaststellen zonder installateursakkoord | Strijdig met het vaste ontwerpprincipe (AI ondersteunt, beslist niet) en risicovol voor een fysieke installatie |

## Gevolgen

- Nieuwe intakes kunnen de route begeleid vastleggen met per-foto feedback en een samengevatte route incl. onzekerheden en ontbrekende controles.
- De klant-wizard-UI en installateur-goedkeuringsweergave zijn nog niet gebouwd (deze ADR dekt de backend-slice); vervolg staat in de backlog.
- Toekomstige modelgeneraties zijn via `.env` in te zetten; een nieuw contractveld vergt wel een promptversiebump (`meta.php`).
