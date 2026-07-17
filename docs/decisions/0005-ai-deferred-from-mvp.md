# ADR-0005: AI niet in de eerste MVP

- **Status:** Accepted (Fase 6-slice later alsnog gebouwd)
- **Datum:** 2026-07-17
- **Bijgewerkt:** 2026-07-17 — minimale summarize-slice in Fase 6

## Context

AI kan samenvattingen en aandachtspunten voorstellen, maar mag validatie en afronding niet bepalen. De opdracht staat toe AI uit te stellen.

## Beslissing

- **Fase 2–5 zonder AI.** Eerst deterministische engine, uploads, compleetheid, HTML-rapport, review.
- **Fase 6:** optionele `SummarizeIntake` via queue + `ai_runs`; soft-fail t.o.v. kernintake.
- Providers: `null` / `fake` / `heuristic` eerst; externe LLM pas na DPIA.
- Geen prompts in controllers/views; wel `AiClientInterface` + versioned prompts.

## Alternatieven

| Alternatief | Afgewezen omdat |
|-------------|-----------------|
| AI vanaf dag één voor follow-up vragen | Risico op niet-deterministische flow; afhankelijk van provider |
| AI als enige compleetheidscheck | Verboden door productregels |

## Gevolgen

- Sneller een bruikbare intake zonder API-keys.
- Duidelijke uitbreidingsnaad zonder kern te koppelen aan een LLM.
