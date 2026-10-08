# Playwright E2E — klantflow

> Browser end-to-end tests for the customer intake / follow-up flow. Runs in CI as job **E2E (Playwright)**.

## Vereisten

- PHP 8.4, Composer, Node 20+
- Chromium via Playwright
- App met `AI_PROVIDER=fake` (nooit echte AI in E2E)
- `E2E_HELPERS=true` (zet `/__e2e__/*` open voor scenario-setup)

## Lokaal draaien

```bash
# Eenmalig / bij dependency-wijziging
composer install
npm ci
npm run build
npx playwright install chromium
npm run e2e:fixtures

# Database + seed (SQLite-bestand)
cp .env.example .env   # indien nodig
php artisan key:generate
# Zet in .env (of exporteer):
#   APP_ENV=local
#   DB_CONNECTION=sqlite
#   DB_DATABASE=database/e2e.sqlite
#   QUEUE_CONNECTION=sync
#   AI_PROVIDER=fake
#   E2E_HELPERS=true
#   DEMO_ENABLED=true
#   PDOK_ENABLED=false
#   CACHE_STORE=file
#   SESSION_DRIVER=file
touch database/e2e.sqlite
php artisan e2e:prepare --fresh

# App starten
php artisan serve --host=127.0.0.1 --port=8000

# Tests (andere terminal)
E2E_BASE_URL=http://127.0.0.1:8000 npm run test:e2e
```

Met `QUEUE_CONNECTION=sync` hoeft er geen aparte queue-worker. Voor database-queue:

```bash
php artisan queue:work --queue=ai-photo,default --sleep=1 --tries=2
```

## Specs

| Bestand | Thema |
|---------|--------|
| `specs/01-wizard-happy-path.spec.ts` | Lege woonkamer → bedankt, geen raw keys |
| `specs/02-large-photo-upload.spec.ts` | 12 MP progressive JPEG meterkast + client-downscale |
| `specs/03-livewire-503-retry.spec.ts` | 503-retry na upload (Even geduld → Opnieuw proberen, BL-143) |
| `specs/04-targeted-customer-task.spec.ts` | Follow-up wrong/low-res + Toch doorgaan |
| `specs/05-photo-feedback.spec.ts` | Geen dubbele/stale feedback |
| `specs/06-progress.spec.ts` | % en Vraag X van Y consistent |
| `specs/07-drain-and-facade-reuse.spec.ts` | Afvoer-tekst + gevel-hergebruik |
| `specs/08-room-name-autosave.spec.ts` | Kamernaam-autosave verschuift vraag niet (BL-140 sticky) |

## Helpers

- `POST /__e2e__/scenarios/{name}` — maakt intake + token (alleen met `E2E_HELPERS=true`)
- `POST /__e2e__/ai-scenario` — zet FakeAiClient-scenario (`good_photo`, `wrong_subject`, …)
- `GET /__e2e__/intakes/{id}/uploads` — server-side afmetingen/status na upload
- `php artisan e2e:prepare --fresh` / `php artisan e2e:scenario {name}`

AI-scenario’s leven in `storage/framework/e2e-ai-scenario.txt` zodat web + queue dezelfde stub zien.
