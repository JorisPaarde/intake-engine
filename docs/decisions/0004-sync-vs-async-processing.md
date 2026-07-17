# ADR-0004: Synchronische vs asynchrone verwerking

- **Status:** Accepted
- **Datum:** 2026-07-17

## Context

cPanel biedt database-queues via cron, geen Supervisor. Niet alles hoeft async, maar het mentale model en de infra (`QUEUE_CONNECTION=database`) bestaan al.

## Beslissing

| Werk | Modus | Reden |
|------|-------|-------|
| Antwoord opslaan / autosave | **Sync** | Directe UX-feedback |
| Compleetheidscheck | **Sync** | Nodig vóór afronden |
| HTML-rapport genereren | **Sync** bij afronden | Voldoende klein in MVP |
| Foto opslaan | **Sync** request | Livewire upload; geen aparte job nodig |
| AI-samenvatting / fotokwaliteit | **Async** (later) | Latency + providerfouten |
| PDF-export | **Async** (later, indien überhaupt) | CPU/zware deps op shared hosting |

## Gevolgen

- Geen queue-dependency voor de kernintake.
- `queue:work` cron blijft relevant voor latere jobs; deploy blijft `queue:restart` doen.
- Tests voor sync-paden zonder Horizon/Redis.
