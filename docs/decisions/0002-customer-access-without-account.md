# ADR-0002: Klanttoegang zonder account

- **Status:** Accepted
- **Datum:** 2026-07-17

## Context

Klanten mogen geen account nodig hebben. Ze openen een persoonlijke link en werken alleen aan hun eigen opname. De installateur moet de link kunnen kopiëren (ook later opnieuw).

## Beslissing

- URL met cryptografisch sterke bearer-token (`/o/{token}`), gegenereerd via CSPRNG (64 url-safe karakters).
- Token opgeslagen in `intakes.access_token` (unique). Entropie is wachtwoord-equivalent; HTTPS verplicht; tokens nooit loggen.
- `token_expires_at` (default 60 dagen, configureerbaar) en `token_revoked_at` voor intrekken.
- Middleware: token → intake; weigeren bij verlopen, ingetrokken, of status `cancelled`.
- Rate limiting op customer-routes.
- Geen automatische e-mail in MVP zolang staging `MAIL_MAILER=log` is — alleen kopieerbare link.

## Alternatieven

| Alternatief | Afweging |
|-------------|----------|
| Alleen hash opslaan | Veiliger bij DB-lek, maar link niet hertoonbaar zonder e-mail |
| Signed URLs | Slecht voor hervatten over weken |
| Klantaccounts | Buiten MVP |

Hash-only kan later zonder domeinbreuk (migratie + eenmalige heruitgifte).

## Gevolgen

- Aparte customer-guard/middleware naast session-auth voor installateurs.
- Policies voor uploads/antwoorden langs beide paden.
- Schema in `docs/database.md` gebruikt `access_token` (plaintext-at-rest in DB); ADR prevaleert boven eerdere hash-schetsen indien die conflicteren.
