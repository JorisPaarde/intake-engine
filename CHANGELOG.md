# Changelog

Alle noemenswaardige wijzigingen aan dit project.

## [Unreleased]

### Added

- Fase 1 documentatie: productdoel, feitelijke stack, schema-ontwerp, intake-engine, uploads, AI-roadmap, implementatieplan.
- ADRs: templateversionering, klanttoegang zonder account, uploadbeveiliging, sync/async, AI uitgesteld, geen multi-tenancy in MVP.
- `docs/database.md` met Mermaid ER-diagram (ontwerp, nog geen migraties).

### Changed

- README stackversies gecorrigeerd (Laravel 13.20, niet 12).
- Architectuurdoc bijgewerkt aan auditbevindingen.

### Known limitations

- Geen domeinmigraties/features nog.
- `MEDIA_DISK` in env nog niet bedraad in `config/filesystems.php`.
- Staging mail = `log` (geen auto-mail van klantlinks).
- Lokale PHP `upload_max_filesize=2M` te laag voor foto’s; cPanel-waarden nog te meten.
- `APP_TIMEZONE` env wordt nog niet door `config/app.php` gelezen (hardcoded UTC).

## [0.1.0] — infrastructuur (pre-domein)

### Added

- Laravel-skelet, Breeze Blade auth, Livewire package, Pest/Pint/PHPStan.
- CI (Pint, PHPStan, Pest) en staging deploy via GitHub Actions + `deploy/activate.sh`.
- Health endpoint, domain folder scaffolds, basisdocumentatie deploy/architectuur.
