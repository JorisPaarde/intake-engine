# ADR-0006: Geen multi-tenancy in MVP

- **Status:** Accepted
- **Datum:** 2026-07-17

## Context

De brief noemt `companies` optioneel. De codebase heeft één User-model zonder tenant.

## Beslissing

- Geen `companies`-tabel in MVP.
- Alle intakes zijn zichtbaar voor elke ingelogde installateur-user op die installatie.
- Policies controleren authenticatie + intake-statusregels, niet “ander bedrijf”.
- Multi-company later: dan `company_id` op users/intakes + scoped queries; schema bewust niet geblokkeerd (nullable FK kan later).

## Gevolgen

- Testdekking “geen opnames van ander bedrijf” is N/A tot multi-tenancy bestaat; wel documenteren.
- Eén staging-omgeving = één virtueel bedrijf.
