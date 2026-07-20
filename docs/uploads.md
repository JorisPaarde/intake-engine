# Uploads & mediastorage

> **Documentversie:** 1.8 · **Laatste update:** 2026-07-20 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Status: **geïmplementeerd (Fase 4)** · BL-021 multiselect + galerijkeuze.

## Doelen

- Mobielvriendelijk: camera + galerij (geen `capture`-force; beide paden open)
- Meerdere foto's in één selectie (`multiple`), tot `meta.max_files`
- Privé: geen voorspelbare publieke paden
- Disk-agnostisch: local cPanel → S3 zonder domeinlogica te wijzigen
- Server-side validatie is leidend

## Uploadflow

1. Klant opent foto-vraag in Livewire-intake (`/o/{token}`).
2. File-input met `multiple` (zonder `capture`) → `wire:model` + `WithFileUploads` → `IntakeWizard::uploadPhotosForComposite` verwerkt elk bestand apart → `StoreIntakeUpload`.
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

Na elke intake- of vervolgfoto-upload voert de app lokaal een niet-blokkerende bruikbaarheidscheck uit. Bij te donker of te klein beeld noemt de melding zowel de kwaliteitsverbetering als de concrete `photo_instructions` van de gepinde vraag of de gerichte foto-opdracht van de installateur, zodat de klant vóór indienen precies weet hoe en wat opnieuw in beeld moet. Omdat het kwaliteitsverdict op de upload staat, wordt dezelfde instructie na verversen, hervatten of terugnavigeren opnieuw getoond.

## Storage disks

| Disk | Root | Gebruik |
|------|------|---------|
| `local` | `storage/app/private` | **Default `MEDIA_DISK`** |
| `public` | `storage/app/public` | Niet voor intake-foto’s |
| `s3` | bucket | Later via env |

## Gerichte PDF-documenten

Een PDF-upload verschijnt alleen wanneer de installateur in een aanvullende informatieronde expliciet antwoordvorm **Document (PDF)** kiest. Daardoor krijgt de normale intake geen extra scherm. `DocumentUploadNormalizer` vereist server-MIME `application/pdf`, controleert daarnaast de `%PDF-`-bestandssignatuur, begrenst de bestaande uploadlimiet en bewaart checksum/originele bestandsnaam. Documenten staan op dezelfde private `MEDIA_DISK`, zijn alleen via klanttoken of installateursauth te openen en worden met `Content-Disposition: attachment` plus `X-Content-Type-Options: nosniff` aangeboden; afbeeldingen blijven inline previews. Standaard zijn maximaal 3 PDF's per documentopdracht toegestaan (`INTAKE_FOLLOW_UP_MAX_DOCUMENTS`). Foto-normalisatie en fotokwaliteitsanalyse worden niet op documenten uitgevoerd.

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

## Multiselect & galerijkeuze (BL-021)

De klantwizard-input voor foto-vragen:

- heeft `multiple`, zodat de aanvrager tot `meta.max_files` (of `INTAKE_UPLOAD_MAX_FILES`) in één keer kan kiezen;
- **geen** `capture="environment"` — op mobiel blijven camera én galerij bereikbaar;
- toont hoeveel slots nog over zijn en verbergt de input bij het maximum;
- uploadt per bestand via de bestaande pijplijn (MIME, size, HEIC→JPEG); één mislukte foto blokkeert de rest van de selectie niet — succesvolle uploads blijven staan, fouten worden als samengevoegde melding getoond.

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
