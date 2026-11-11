# Plan & oplevering: embed-formulier (website → klantopname)

> **Documentversie:** 1.3 · **Laatste update:** 2026-11-11 · Onderhoud: zie [AGENTS.md](../../AGENTS.md)

**Status:** PR1–PR3 opgeleverd (BL-151/152/153); UX-afronding na Joris-stagingtest (sync-prefill freeze, ruimtelabels, maten-overslaan). Optioneel later: publieke adres-autocomplete, WP-plugin.

## Wat er staat

Installateur plakt een iframe-snippet op de eigen site. De klant vult naam, e-mail, postcode+huisnummer (+ optioneel toevoeging/telefoon) en AVG-vinkje in. Intake Engine maakt een opname **zonder AI**, mailt de klantlink als backup, en stuurt de browser in het top-window door naar `/o/{token}`. De hele intake blijft op IE.

| Onderdeel | BL | Inhoud |
|-----------|----|--------|
| Settings | BL-151 | `companies.embed_*`, sleutel/domeinen/limiet/snippet in bedrijfsinstellingen |
| Publiek formulier | BL-152 | `GET/POST /embed/{public_key}`, CSP, limieten, dedup, CreateIntake zonder AI |
| AI + badge + smoke | BL-153 | request-prefill bij eerste klantstap/foto; dashboardbadge; staging-testpagina |

## Flow

1. Installateur zet formulier aan, registreert hostnames, kopieert snippet.
2. Klant opent installateurssite → iframe laadt `/embed/{key}` (`frame-ancestors` allowlist + `'self'`).
3. POST (same-origin t.o.v. IE; HMAC `embed_form_token`, honeypot, throttle, dagplafond, Origin-check).
4. Nieuw: `CreateIntake` (`source=embed`, `created_by` = oudste company-user) + `EnrichIntakeAddress` + backup-mail → 303 `/o/{token}` (`target=_top`).
5. Bestaande open opname (zelfde company + e-mail): opnieuw mailen + neutrale bevestiging in iframe (geen token-lek); telt niet mee voor dagplafond.
6. Eerste echte klantstap of foto in de wizard: eerst antwoord opslaan, daarna precies één `DeriveIntentFromRequestJob` als die nog ontbreekt (geen sync `DeriveIntentFromRequest` in de Livewire-request — dat blokkeerde Volgende ~20 s). Pending job → wachtscherm **Even geduld, we zetten je vragen klaar**; daarna known-summary. Mount/GET alleen triggert geen AI. Niet-embed-opnames blijven create-time prefill houden.

## Snippet

```html
<iframe
  src="https://intake-engine.nl/embed/{public_key}"
  title="Opname starten"
  width="100%"
  height="720"
  style="border:0;max-width:40rem;"
  loading="lazy"
  referrerpolicy="strict-origin-when-cross-origin"
></iframe>
```

Op staging voor smoke: `https://staging.intake-engine.nl/embed-test`. Staging-deploy (`activate.sh` met `expected_env=staging`) draait `embed:prepare-staging-test` automatisch na migraties (idempotent, fail-soft). Handmatig herhalen mag. Die pagina host dezelfde iframe op de app-host (`'self'`).

## Beveiliging (kort)

- Publieke sleutel ≠ geheim; autorisatie = sleutel + Origin/Referer ∈ allowlist (app-host altijd ok voor same-origin iframe-POST).
- Framing stopt `Content-Security-Policy: frame-ancestors` (https-hosts + `'self'`), niet de Origin-check.
- Sessieloos: HMAC-formuliertoken (APP_KEY, max 2 u) i.p.v. CSRF-cookie.
- Honeypot `website`, throttle `embed-start` (IP + sleutel), dagplafond → 429 NL.
- Geen AI op create; foto-AI blijft via bestaande uploadjobs ná klantupload.
- Geen tokens/e-mail in activity-properties of logs (ADR-0002).

## Staging smoke

1. Na staging-deploy is `/embed-test` klaar (prepare zit in `deploy/activate.sh`). Alleen bij ontbrekende company: `php artisan embed:prepare-staging-test`.
2. Open `https://staging.intake-engine.nl/embed-test`
3. Invullen → top-window op `/o/{token}` → eerste vraag beantwoorden
4. Inloggen als `embed-staging-test@example.com` / `password` → badge **Nieuw via website**
5. Negatief (optioneel): verkeerd domein, honeypot, limiet — zie `docs/functional-test-status.md`

### Vaste BAG-check (live PDOK — niet in CI)

CI mag geen echte PDOK aanroepen. Op staging/local:

```bash
php artisan embed:prepare-staging-test
php artisan embed:smoke-bag-address
```

Vaste case **2037GR 273** (Haarlem, Bernadottelaan): na embed-create moeten straat, plaats, bouwjaar en woningtype gevuld zijn (geen `Onbekend`). Onbekend adres **2037GR 1** moet een nette validatiemelding geven (`We konden dit adres niet vinden…`) zonder opname. Optioneel `--keep` om de testopname te bewaren voor een 390 px browsercheck van de klantwizard-header.

Met `EP_ONLINE_ENABLED=true` + `EP_ONLINE_KEY` (shared `.env`, nooit in git): de klantkop toont ook **Energielabel** wanneer RVO een letter teruggeeft. Voorbeeld Warschaustraat 18:

```bash
php artisan embed:smoke-bag-address --postal-code=2034VL --house-number=18 --expect-energy-label=A
```

Zonder geregistreerd label of bij EP-storing/timeout: soft melding “Geen energielabel gevonden…” — flow blijft open. Zonder key blijft BAG werken; de soft melding verschijnt wel (`energy_label_status=unavailable`).

Browser (aanvulling op `/embed-test`): postcode `2037GR`, huisnummer `273` → header toont `Bernadottelaan 273` + `Haarlem`; bouwjaar-/woningtypevragen vervallen. Herhaal met huisnummer `1` → formulierfout, geen redirect. Met EP-key: `2034VL` / `18` → Energielabel A in de bekende-woninggegevenslijst.
