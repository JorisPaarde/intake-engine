# ADR-0005: AI niet in de eerste MVP

- **Status:** Accepted
- **Datum:** 2026-07-17

## Context

AI kan samenvattingen en aandachtspunten voorstellen, maar mag validatie en afronding niet bepalen. De opdracht staat toe AI uit te stellen.

## Beslissing

- **Fase 2–5 zonder AI.** Eerst deterministische engine, uploads, compleetheid, HTML-rapport, review.
- Domeinmap `app/Domains/AI/` blijft scaffold; env-placeholders blijven staan.
- Concrete AI-slice pas in Fase 6 volgens `docs/ai.md`.
- Geen prompts in controllers/views wanneer AI komt; wel interface + `ai_runs`.

## Alternatieven

| Alternatief | Afgewezen omdat |
|-------------|-----------------|
| AI vanaf dag één voor follow-up vragen | Risico op niet-deterministische flow; afhankelijk van provider |
| AI als enige compleetheidscheck | Verboden door productregels |

## Gevolgen

- Sneller een bruikbare intake zonder API-keys.
- Duidelijke uitbreidingsnaad zonder kern te koppelen aan een LLM.
