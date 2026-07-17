# Uploads & mediastorage

> **Documentversie:** 1.1 · **Laatste update:** 2026-07-17 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

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
   - MIME + size + max aantal
   - veilige bestandsnaam (`ulid` + extensie)
   - schrijf naar `Storage::disk(config('filesystems.media'))`
   - rij in `intake_uploads` + sync `intake_answers.value.upload_ids`
4. Preview via `customer.uploads.show` / `installer.uploads.show`.
5. Verwijderen: `DeleteIntakeUpload` (soft delete + file delete + sync).

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
| MIME allowlist | jpeg, png, webp |
| Max size | `INTAKE_UPLOAD_MAX_KB` (default 5120 = 5 MB) |
| Max files | vraag-`meta.max_files` of `INTAKE_UPLOAD_MAX_FILES` |

## Validatie

| Regel | Waarde |
|-------|--------|
| Max per bestand | 5 MB (configureerbaar) |
| Max per vraag | default 5 |
| Types | jpeg, png, webp |

## PHP- en cPanel-limieten

### Lokaal gemeten (dev machine, juli 2026)

| Setting | Waarde |
|---------|--------|
| `upload_max_filesize` | **2M** (te laag — verhogen) |
| `post_max_size` | **8M** |

Aanbevolen: `upload_max_filesize=10M`, `post_max_size=12M`. Staging-waarden: nog meten op cPanel (zie `docs/DEPLOYMENT.md`).

## Migratie naar S3

1. AWS-vars in `.env`
2. `MEDIA_DISK=s3`
3. Bestaande rijen behouden `disk` + `path`
