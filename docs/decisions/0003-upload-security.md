# ADR-0003: Beveiliging van uploads

- **Status:** Accepted
- **Datum:** 2026-07-17

## Context

Intake-foto’s bevatten privacygevoelige beelden (woning, meterkast). Ze mogen niet via voorspelbare publieke URLs bereikbaar zijn.

## Beslissing

- Opslag op private disk (`MEDIA_DISK=local` → `storage/app/private`).
- Pad: `intakes/{uuid}/…/{ulid}.ext` — geen klantnamen, geen raw tokens.
- Uitsluitend serveren via geautoriseerde applicatieroutes (installateur-auth of geldig klanttoken) en/of kortgeldige signed URLs.
- Validatie: allowlist MIME/extensie, max size, max count; nooit vertrouwen op client `Content-Type` alleen.
- Domeincode gebruikt altijd `config('filesystems.media')`.

## Alternatieven

| Alternatief | Afgewezen omdat |
|-------------|-----------------|
| `public` disk + obscure filenames | Security by obscurity; symlink maakt files web-reachable |
| Direct S3 public-read | Zelfde probleem |
| Alleen Spatie Media Library | Extra package zonder noodzaak in MVP |

## Gevolgen

- `.env*.example` corrigeren weg van `MEDIA_DISK=public` (Fase 4).
- `config/filesystems.php` krijgt `media`-key.
- cPanel PHP upload-limieten moeten ≥ app-limiet (lokaal nu 2M — te laag).
