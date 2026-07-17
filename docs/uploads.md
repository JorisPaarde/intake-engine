# Uploads & mediastorage

Status: **ontwerp (Fase 1)**. Nog geen uploadcode. `MEDIA_DISK` staat in env-voorbeelden maar is **nog niet** aangesloten in `config/filesystems.php` — dat volgt in Fase 4.

## Doelen

- Mobielvriendelijk: camera + galerij
- Privé: geen voorspelbare publieke paden
- Disk-agnostisch: local cPanel → S3 zonder domeinlogica te wijzigen
- Server-side validatie is leidend

## Uploadflow (gepland)

1. Klant opent foto-vraag in intake (token-middleware).
2. Livewire/Alpine upload naar geautoriseerde route.
3. Action `StoreIntakeUpload`:
   - authz (token ↔ intake)
   - MIME + extensie + size + max aantal
   - veilige bestandsnaam (`ulid` + genormaliseerde extensie)
   - schrijf naar `Storage::disk(config('filesystems.media'))`
   - rij in `intake_uploads`
4. Response: upload-id, preview-URL (geautoriseerde route), voortgangsfeedback.
5. Verwijderen: soft delete record + bestandsdelete (of deferred purge).

Installateur bekijkt dezelfde bestanden via auth + policy.

## Storage disks

| Disk | Root | Gebruik |
|------|------|---------|
| `local` | `storage/app/private` | **Aanbevolen `MEDIA_DISK` voor foto’s** |
| `public` | `storage/app/public` | Alleen echt publieke assets — **niet** voor klantfoto’s |
| `s3` | bucket | Toekomstige object storage |

Config-aanvulling (Fase 4):

```php
'media' => env('MEDIA_DISK', 'local'),
```

Alle uploadcode: `Storage::disk(config('filesystems.media'))` — nooit hardcoded disknamen.

**Correctie t.o.v. eerdere env-voorbeelden:** `MEDIA_DISK=public` is onveilig voor privacyfoto’s. Standaard wordt `local` (private). Staging/production examples worden in Fase 4 gelijkgetrokken.

## Directorystructuur

```
{disk-root}/intakes/{intake_uuid}/{question_key}/{section_instance?}/{ulid}.jpg
```

Geen klantnaam of oplopend id in het pad. UUID van de intake is niet hetzelfde als het klanttoken.

## Beveiliging

| Maatregel | Invulling |
|-----------|-----------|
| Geen public disk voor intake-foto’s | `MEDIA_DISK=local` |
| Geen directory listing | webserver / buiten docroot |
| Toegang | routes met `auth` (installateur) of customer-token middleware |
| Optioneel | tijdelijk signed URL (`URL::temporarySignedRoute`) voor `<img>` |
| MIME | finfo / Symfony Mime, allowlist `image/jpeg`, `image/png`, `image/webp`, `image/heic` (indien ondersteund) |
| Extensie | allowlist, niet vertrouwen op client |
| Max size | app-limiet ≤ PHP/cPanel-limiet |
| Mass assignment | alleen via Actions |
| Activity log | event zonder binary / zonder token |

Zie ADR-0003.

## Validatie (MVP-voorstel)

| Regel | Waarde |
|-------|--------|
| Max per bestand | **5 MB** (app) |
| Max per foto-vraag | configureerbaar in `meta.max_files` (default 5) |
| Types | jpeg, png, webp (+ heic als runtime het aankan) |
| Min afmeting (optioneel later) | waarschuwing, geen harde blokkade in MVP |

## PHP- en cPanel-limieten

### Lokaal gemeten (dev machine, juli 2026)

| Setting | Waarde |
|---------|--------|
| `upload_max_filesize` | **2M** |
| `post_max_size` | **8M** |
| `memory_limit` | **128M** |

**2M is te laag** voor mobiele foto’s. Lokaal (Herd/php.ini) verhogen naar minimaal:

```ini
upload_max_filesize = 10M
post_max_size = 12M
```

### Staging (cPanel) — te verifiëren vóór Fase 4

Op de server controleren:

```bash
php -i | grep -E 'upload_max_filesize|post_max_size|max_file_uploads'
```

En via cPanel MultiPHP INI Editor / Select PHP Version → Options:

- `upload_max_filesize` ≥ 10M
- `post_max_size` ≥ 12M
- eventueel LiteSpeed/proxy body size

Documenteer de **werkelijke** stagingwaarden hier zodra gecontroleerd. Zonder verhoging falen uploads stil of met generieke 413.

| Omgeving | upload_max | post_max | Gecontroleerd |
|----------|------------|----------|---------------|
| Local (huidige CLI) | 2M | 8M | 2026-07-17 |
| Staging cPanel | *onbekend* | *onbekend* | nog te doen |
| Production | *n.v.t.* | *n.v.t.* | nog geen workflow |

## UI-eisen

- `capture="environment"` waar zinvol + `accept="image/*"`
- Uploadvoortgang
- Preview + verwijderen + opnieuw
- Meerdere foto’s per opdracht
- Foutmelding bij limiet/type, zonder andere antwoorden te wissen

## Migratie naar S3

1. Zet AWS-vars in `.env`
2. `MEDIA_DISK=s3`
3. Optioneel: eenmalig sync-commando oude bestanden
4. Bestaande rijen behouden `disk` + `path` — nieuwe uploads gebruiken s3
5. Serve-routes blijven abstracted via Storage

Geen wijziging in CompletenessChecker of rapport-domeinlogica.

## Shared storage op staging

`deploy/activate.sh` symlinkt release-`storage` → `shared/storage`. Uploads overleven deploys. Dit blijft zo.
