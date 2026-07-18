# Uploads & mediastorage

> **Documentversie:** 1.5 · **Laatste update:** 2026-07-18 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Status: **geïmplementeerd (Fase 4)**.

## Doelen

- Mobielvriendelijk: camera + galerij
- Privé: geen voorspelbare publieke paden
- Disk-agnostisch: local cPanel → S3 zonder domeinlogica te wijzigen
- Server-side validatie is leidend

## Uploadflow

1. Klant opent foto-vraag in Livewire-intake (`/o/{token}`).
2. `wire:model` + `WithFileUploads` → `StoreIntakeUpload`.
3. Action:
   - authz via token-middleware / intake-koppeling
   - max aantal + server-side MIME-detectie + size
   - HEIC/HEIF → JPEG-normalisatie (Imagick: auto-orient, metadata strippen, resize/kwaliteit binnen limiet)
   - veilige bestandsnaam (`ulid` + extensie)
   - schrijf naar `Storage::disk(config('filesystems.media'))`
   - rij in `intake_uploads` + sync `intake_answers.value.upload_ids`
4. Preview via `customer.uploads.show` / `installer.uploads.show`.
5. Verwijderen: `DeleteIntakeUpload` (soft delete + file delete + sync).
6. Installateursgalerij (detailpagina): `InstallerPhotoGalleryBuilder` groepeert foto’s per sectie/instantie en toont vraaglabels uit de gepinde templateversie (geen rauwe `question_key` / `section_instance_key`) — BL-024.

## Storage disks

| Disk | Root | Gebruik |
|------|------|---------|
| `local` | `storage/app/private` | **Default `MEDIA_DISK`** |
| `public` | `storage/app/public` | Niet voor intake-foto’s |
| `s3` | bucket | Later via env |

```php
'media' => env('MEDIA_DISK', 'local'),
```

## Directorystructuur

```
{disk-root}/intakes/{intake_uuid}/{question_key}/{section_instance?}/{ulid}.jpg
```

## Beveiliging

| Maatregel | Invulling |
|-----------|-----------|
| Private disk | `MEDIA_DISK=local` |
| Serve-routes | customer-token of installer `auth` + intake-match |
| Inputtypes | jpeg, png, webp, heic/heif |
| Opgeslagen types | jpeg, png, webp (HEIC/HEIF wordt JPEG) |
| Max size | `INTAKE_UPLOAD_MAX_KB` (default 5120 = 5 MB) |
| Max files | vraag-`meta.max_files` of `INTAKE_UPLOAD_MAX_FILES` |

## Validatie

| Regel | Waarde |
|-------|--------|
| Max per bestand | 5 MB (configureerbaar) |
| Max per vraag | default 5 |
| Inputtypes | jpeg, png, webp, heic/heif |
| Opgeslagen types | jpeg, png, webp |

## HEIC/HEIF-normalisatie (BL-008)

iPhone-foto's in HEIC/HEIF worden server-side verwerkt; de aanvrager hoeft geen instellingen te wijzigen of zelf te converteren. `UploadMimeDetector` gebruikt server-side MIME-detectie en sniffed ISO BMFF-brands wanneer PHP/host alleen `application/octet-stream` ziet. Client-MIME of extensie alleen is niet genoeg om een bestand te accepteren.

`PhotoUploadNormalizer` zet HEIC/HEIF via Imagick om naar JPEG:

- auto-orient op basis van EXIF/oriëntatie;
- metadata strippen;
- lange zijde maximaal `config('intake.uploads.conversion.max_long_edge')` (default 3000px);
- JPEG-kwaliteit start op `config('intake.uploads.conversion.heic_to_jpeg_quality')` (default 82) en wordt stap voor stap verlaagd tot het resultaat binnen `INTAKE_UPLOAD_MAX_KB` past.

De database bewaart de metadata van het opgeslagen bestand (`mime_type=image/jpeg`, `.jpg`-pad, JPEG-size en checksum). `/health` exposeert `image_conversion.imagick_loaded` en `image_conversion.heic_read` zodat staging snel kan worden gecontroleerd.

## PHP- en cPanel-limieten

Applicatielimiet: **5 MB** per foto (`INTAKE_UPLOAD_MAX_KB`). PHP moet daarboven zitten.

### Gewenste waarden (in git)

`public/.user.ini` zet voor web-requests (cPanel/LiteSpeed):

| Setting | Waarde |
|---------|--------|
| `upload_max_filesize` | **10M** |
| `post_max_size` | **12M** |
| `max_file_uploads` | **20** |

### Meten

- **Remote (staging):** `GET /health` → veld `php_upload` (geen SSH nodig).
- **Op de server (CLI):** `php -i | grep -E 'upload_max_filesize|post_max_size|max_file_uploads'` — CLI leest `.user.ini` niet; voor uploads telt de web-SAPI.

### Staging gemeten (web-SAPI via `/health`, 2026-07-18)

| Setting | Waarde |
|---------|--------|
| `upload_max_filesize` | **512M** |
| `post_max_size` | **512M** |
| `max_file_uploads` | **20** |
| App-limiet | **5120 KB** (5 MB) |

Hostlimieten liggen ruim boven het minimum; `public/.user.ini` blijft als vangnet voor omgevingen met lage defaults. BL-003: done.

### Lokaal gemeten (dev CLI, juli 2026)

| Setting | Waarde |
|---------|--------|
| `upload_max_filesize` | **2M** (CLI-default; lokaal verhogen of via `public/.user.ini` bij `php artisan serve` afhankelijk van SAPI) |
| `post_max_size` | **8M** |

Alternatief op cPanel: MultiPHP INI Editor met dezelfde minima — zie [docs/DEPLOYMENT.md](DEPLOYMENT.md). Voorkeur: `.user.ini` in git zodat limieten deploys overleven.

## Migratie naar S3

1. AWS-vars in `.env`
2. `MEDIA_DISK=s3`
3. Bestaande rijen behouden `disk` + `path`
