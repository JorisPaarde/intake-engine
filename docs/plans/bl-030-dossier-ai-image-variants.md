# Plan: Dossier- + AI-beeldvarianten per upload

> **Status:** ready (backlog) · **BL-030** · Canonical item: [`docs/backlog.md` § BL-030](../backlog.md) · Geen code tot implementatie-PR

## Overview

Per foto-upload twee genormaliseerde varianten: dossier (~2048px) voor installateur/preview/PDF, en AI-analyse (~1536px) voor alle vision-calls. Geen telefoon-originelen op disk; Sol escaleert alleen met relevante analysekopieën.

## Beslissing (vast — geen open A/B)

Per foto-upload bewaren we **geen** volle telefoonresolutie (geen 4032×3024 op disk). We schrijven altijd twee private bestanden:

| Variant | Lange zijde | Formaat | Kwaliteit | Gebruik |
|---------|-------------|---------|-----------|---------|
| **Dossier** | **2048 px** | JPEG | **82** | Preview, galerij, HTML/PDF, installateur-zoom (meterkast/leidingdetail) |
| **AI-analyse** | **1536 px** | JPEG | **80** | Alleen vision-calls (Terra e.d.) |

Beide: auto-orient, EXIF/metadata strippen, HEIC/HEIF → JPEG. PNG/WebP-uploads worden voor opslag ook JPEG (één pad, minder edge-cases). Uploadlimiet (`INTAKE_UPLOAD_MAX_KB`) blijft gelden op het **binnenkomende** bestand vóór conversie.

**Waarom 2048 dossier (niet full phone, niet 1536):** genoeg om in te zoomen op groepen/aansluitingen/obstakels; geen posterdrukwerk; ~halve lineaire resolutie t.o.v. typische 4k-phone → veel minder storage zonder dossierkwaliteit te verliezen.

**Scope AI-consumenten (gedeelde bouwsteen):** alle vision-paden gebruiken de analysekopie — `AnalyzeRoutePhoto`, `SynthesizePipeRoute`-escalatie (Sol), `AssessFuseboxPhotos`, `DerivePhotoAnswers`. Lokale `AssessPhotoUsability` gebruikt **dossier** (zelfde beeld dat de mens ziet).

**Sol-escalatie:** stuurt opnieuw alleen de **relevante** segmentfoto’s als **analysekopie** (niet dossier, niet telefoon-origineeel). Synthese blijft tekst+segmentmetadata + die images.

**Fase 2 (zelfde BL, latere slice):** bij “detail onleesbaar” → één hogere-res of crop van **die** foto (uit dossierbron, max ~2048 of gerichte crop), opnieuw alleen die ene image naar het model — nooit alle originelen opnieuw.

## Token-voorbeeld (richting, niet contract)

| Versie | Afmetingen | Geschat beeldgebruik |
|--------|------------|----------------------|
| Telefoon-origineeel (niet opslaan) | 4032 × 3024 | ±11.970 tokens |
| AI-kopie (wel naar Terra) | 1536 × 1152 | ±1.728 tokens |
| Besparing | | ±86% |

Vijf foto’s: ±60.000 → ±8.640 beeldtokens. Dossier op disk blijft ~2048 voor mensen; gaat niet naar het model tenzij fase-2 detail-escalatie.

## Huidige gap

- [`app/Domains/Intake/Services/PhotoUploadNormalizer.php`](app/Domains/Intake/Services/PhotoUploadNormalizer.php): JPEG/PNG/WebP = passthrough (EXIF + volle resolutie blijven); HEIC alleen → JPEG max **3000** px.
- AI (`AnalyzeRoutePhoto::imageInput`, `DerivePhotoAnswers`, `AssessFuseboxPhotos`): leest `Storage::disk($upload->disk)->get($upload->path)` als data-URL → volle opgeslagen bytes.
- [`app/Domains/Intake/Actions/HardDeleteIntake.php`](app/Domains/Intake/Actions/HardDeleteIntake.php): verwijdert alleen `path`, geen tweede bestand.

## Architectuur

```mermaid
flowchart LR
  upload[UploadedFile] --> normalize[PhotoUploadNormalizer]
  normalize --> dossier[Dossier JPEG 2048 q82]
  normalize --> analysis[AI JPEG 1536 q80]
  dossier --> diskD[MEDIA_DISK path]
  analysis --> diskA[MEDIA_DISK analysis_path]
  diskD --> ui[Preview PDF galerij]
  diskA --> terra[Terra vision]
  terra -->|"lage confidence / onleesbaar detail"| sol[Sol alleen relevante analysis of crop]
```

### Datamodel

Migratie op `intake_uploads`:

- `analysis_path` (nullable string) — pad AI-kopie; zelfde `disk`
- `analysis_mime_type` (nullable, default `image/jpeg`)
- `analysis_size_bytes` (nullable uint)
- `analysis_checksum` (nullable string)
- optioneel: `width` / `height` (dossierafmetingen) voor diagnostics

`path` / `mime_type` / `size_bytes` / `checksum` blijven de **dossier**-variant (UI/PDF ongewijzigd voor callers die `path` gebruiken).

### Code

1. **`PhotoUploadNormalizer`** — altijd Imagick-pipeline (niet alleen HEIC):
   - lees → `autoOrient` → `stripImage` → twee writes (dossier 2048/q82, analysis 1536/q80)
   - DTO [`NormalizedPhotoUpload`](app/Domains/Intake/Services/NormalizedPhotoUpload.php) uitbreiden met analysis temp-pad + meta + cleanupPaths voor beide temps
2. **`StoreIntakeUpload` / `StoreFollowUpUpload`** — beide bestanden op `MEDIA_DISK` (bijv. `…/{ulid}.jpg` + `…/{ulid}.analysis.jpg`); vul analysis-kolommen
3. **`AiImageResolver`** (nieuw) — `forAnalysis(IntakeUpload): AiImageInput` (analysis_path; legacy: lazy-generate of dossier-fallback); alle vision-actions hiernaartoe
4. **`HardDeleteIntake`** — ook `analysis_path` deleten
5. **Config** in [`config/intake.php`](config/intake.php):

```php
'conversion' => [
    'dossier_max_long_edge' => (int) env('INTAKE_DOSSIER_MAX_LONG_EDGE', 2048),
    'dossier_jpeg_quality' => (int) env('INTAKE_DOSSIER_JPEG_QUALITY', 82),
    'analysis_max_long_edge' => (int) env('INTAKE_ANALYSIS_MAX_LONG_EDGE', 1536),
    'analysis_jpeg_quality' => (int) env('INTAKE_ANALYSIS_JPEG_QUALITY', 80),
],
```

Verwijder/vervang oude `max_long_edge` / `heic_to_jpeg_quality` (één bron van waarheid; bump docs).

6. **Fase 2 (later in zelfde BL):** `AiDetailCropper` + prompt-signaal/`missing_information` → optionele tweede call met één crop; config `INTAKE_ANALYSIS_DETAIL_LONG_EDGE` default 2048; geen batch-heranalyse.

### Tests

- Normalizer: EXIF weg, lange zijde ≤2048/≤1536, beide JPEG, checksums verschillend
- Store: twee files op disk; HardDelete ruimt beide op
- AI actions (Fake/Http::fake): data-URL komt uit analysis-bytes (mock kleiner dan dossier)
- Legacy upload zonder `analysis_path`: resolver valt terug zonder crash (lazy regenerate of dossier)

### Docs / backlog / changelog

- Nieuw **BL-030**: “Foto-varianten dossier + AI-analyse”
- Bijwerken: [`docs/uploads.md`](docs/uploads.md), [`docs/ai.md`](docs/ai.md) (tokens/Terra/Sol alleen analysis), korte verwijzing bij ADR-0009 / BL-029 indien route-escalatie geraakt wordt
- [`CHANGELOG.md`](CHANGELOG.md) `[Unreleased]`; documentversiebumps
- Env-voorbeelden: knobs documenteren; geen secrets

### Niet in scope

- Client-side resize vóór upload (server blijft source of truth)
- WebP als opslagformaat (JPEG vast)
- Backfill-job voor alle historische uploads (lazy op eerste AI-gebruik is genoeg)
- UI-wijzigingen behalve dat previews iets scherper/kleiner kunnen ogen (2048 i.p.v. 4k)

## Uitvolgorde

1. Config + migratie + DTO/normalizer (dossier+analysis)
2. Store + HardDelete + tests opslag
3. `AiImageResolver` + alle vision-actions omschakelen + tests
4. Docs + BL-030 + CHANGELOG
5. (Slice 2) detail-crop / hogere-res-escalatie per foto

## Todos

- [ ] Config knobs (2048/82, 1536/80) + migratie `analysis_*` op `intake_uploads`
- [ ] `PhotoUploadNormalizer`: altijd orient/strip/JPEG; schrijf dossier + analysis temps
- [ ] `StoreIntakeUpload` / FollowUp + `HardDeleteIntake` beide bestanden
- [ ] `AiImageResolver` + vision-actions op `analysis_path`
- [ ] Pest + `uploads.md` / `ai.md` / CHANGELOG + BL-030
- [ ] Later: onleesbaar detail → één crop/hogere-res; geen heranalyse alle foto’s
