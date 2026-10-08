# Uploads & mediastorage

> **Documentversie:** 3.22 · **Laatste update:** 2026-10-08 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Status: klant-, gerichte bijdrage- en installateursfoto's, private serve-routes, generieke bewijslinks en dossier-/analysevarianten zijn **geïmplementeerd**. `MEDIA_DISK=s3` is ondersteund via Laravel’s `s3`-disk (BL-013). Directe installateurs-PDF-upload is niet gebouwd; een PDF kan wel als gerichte klanttaak worden gevraagd.

## Doelen

- Mobielvriendelijk: camera + galerij (geen `capture`-force; beide paden open)
- Meerdere foto's in één selectie (`multiple`), tot `meta.max_files`
- Privé: geen voorspelbare publieke paden
- Disk-agnostisch: local cPanel → S3 zonder domeinlogica te wijzigen
- Server-side validatie is leidend
- Dezelfde private mediapipeline voor klant en installateur
- Media is bewijs bij een dossierobject; een templatevraag is slechts één mogelijke herkomst
- Actor, vaststellingswijze, bron, tijdstip en eventuele AI-analyse blijven herleidbaar

## Gedeelde uploadflow

1. Een klant opent een templatefoto of gerichte foto-opdracht; een installateur start **Foto maken** rechtstreeks bij een ruimte, positie of verbinding. Het dossieronderwerp volgt uit die context en is niet handmatig kiesbaar.
2. De hoofdwizard gebruikt multiselect zonder geforceerde camera. Gerichte klanttaken en de camera-first installateurswerkplek verwerken één concreet bestand per uploadactie.
3. Action:
   - authz via token-middleware of installateurspolicy + intake/onderwerp-match
   - max aantal + server-side MIME-detectie + size
   - ieder ondersteund beeld → twee georiënteerde, metadata-vrije JPEG-varianten
   - veilige bestandsnamen (`ulid`) voor dossier- en analysekopie
   - atomisch werkende opslagcleanup wanneer één variant of de DB-transactie faalt
   - rij in `intake_uploads`; templatefoto's synchroniseren daarnaast `intake_answers.value.upload_ids`
   - `DossierManager` koppelt bewijs aan ruimte, plaatsing, verbinding of algemene dossierroot
4. Preview via `customer.uploads.show` / `installer.uploads.show`.
5. AI-categoriecheck (BL-119) + queue-pipeline (BL-121/BL-127/BL-134): elke klantfoto krijgt precies één terminale `assessment_status` (`pending` → `assessed` / `heuristic_rejected` / `not_assessed` / `reused`). `too_small` → `heuristic_rejected` zonder AI-wacht; AI-fout → `not_assessed`; dezelfde bytes met hetzelfde expected subject → `reused` + `assessment_source_upload_id`. `content_assessment` (`ok` / `wrong_subject` / `needs_clearer` / `not_assessed`) blijft het inhoudelijke oordeel. Wizard-poll stopt op elke terminale status; na ~90 s soft-timeout (“check volgt later”) mag de klant door terwijl pending voor de watchdog blijft. Watchdog `photos:requeue-pending-assessments` (elke vijf minuten) herdispatched alleen pipeline-dispatched pending (`assessment_attempts >= 1`) ouder dan ~3 min, binnen max-age (24 u), op klantfase-intakes, met cap per run; legacy backfill-pending wordt niet herqueued. Submitted/closed intakes: job zet alleen terminal, geen AI. `ai_runs.upload_id` koppelt foto-runs. Verkeerd onderwerp: **Vervang foto** of **Toch doorgaan**. Niet-controleerbare gebieden zonder `photo_analysis` → `assessed` zonder content_assessment.
6. Verwijderen wist beide varianten; bij storagefalen neemt `DeleteStoredMediaJob` de retry over. **Vervolgronde (BL-147):** het prullenbak-icoon (44×44) soft-deletet de foto eerst (`DeleteFollowUpUpload::softRemove`) en toont 8 s **Foto verwijderd. · Ongedaan maken** (`restore`); daarna, of bij Volgende/Vorige/Aanvulling versturen/nieuwe upload, ruimt `finalize` bewijslinks + media op en logt `follow_up_upload_deleted`. Zolang een weggehaalde foto in deze “prullenbak” staat (soft-deleted, `purged_at` null) telt hij nergens mee: rapport, AI-samenvatting, dossier-AI en aandachtspunten lezen via Eloquent en slaan hem over (Pest `FollowUpUploadBinTest`). Sluit de klant het tabblad binnen 8 s, dan gaat de foto echt weg bij het volgende bezoek aan de klantlink (`purgePendingFor` in `mount`), bij **Aanvulling versturen** (`CompleteFollowUpRound`) of via `photos:purge-removed` (uurlijks; alles langer dan `INTAKE_FOLLOW_UP_REMOVED_UPLOAD_PURGE_MINUTES`, standaard 10 min, actor `system`) — niet bij het sluiten zelf. Echt wissen zet `purged_at`; ongedaan maken daarna geeft “Ongedaan maken mislukt.”. De hoofdwizard verwijdert nog direct (melding “Foto verwijderd.”) en zet `purged_at` meteen.
7. Installateursgalerij (detailpagina): `InstallerPhotoGalleryBuilder` groepeert foto’s per sectie/instantie en toont vraaglabels uit de gepinde templateversie (geen rauwe `question_key` / `section_instance_key`) — BL-024.

Na elke intake- of vervolgfoto-upload voert de app lokaal een niet-blokkerende bruikbaarheidscheck uit. Bij te donker of te klein beeld noemt de melding zowel de kwaliteitsverbetering als de concrete `photo_instructions` van de gepinde vraag of de gerichte foto-opdracht van de installateur, zodat de klant vóór indienen precies weet hoe en wat opnieuw in beeld moet. Omdat het kwaliteitsverdict op de upload staat, wordt dezelfde instructie na verversen, hervatten of terugnavigeren opnieuw getoond. **Resolutie (BL-124/BL-128/BL-143):** `TooSmall` gebruikt `processing_timings.original_width/height` van het bronbeeld (telefoonorigineel of grootste HEIC-frame), niet de verkleinde dossier-/analysevariant. **Client-bytes (BL-143):** browser verkleint JPEG/PNG/WebP tot lange zijde ≤**2000** px (kwaliteit ~0,85, EXIF-oriëntatie via `createImageBitmap`/`imageOrientation`; timeout ~8 s → upload origineel). `photoClientOriginals` gaat via **deferred** Livewire-`$set(..., false)` mee in dezelfde update als `_finishUpload` — geen aparte concurrente POST (staging intake 81: twee updates in één seconde → LiteSpeed 503 → stille loss). Tijdens upload-in-flight blokkeert de client polls en live `$set`. **Upload-timeouts (BL-128/BL-143):** Alpine-timeout is *inactiviteit* (~45 s zonder progress) tijdens de byte-transfer; ná 100% wacht de UI op het serverantwoord (~120 s). Lege/ongeldige 200 én 5xx/netwerk op `/livewire/upload-file` én op de Livewire-`update` erna (store) worden client-side opnieuw geprobeerd: max 3 pogingen, backoff ~2 s / ~20 s / ~60 s + jitter, `Retry-After` (cap 90 s), **één retry tegelijk per pagina**, `wire:poll` pauzeert tijdens busy; update-retry hergebruikt het **zelfde tmp-bestand** (geen tweede byte-upload). Rustige tekst “Even geduld, we proberen het opnieuw.”; na uitputting “De server is even druk. Probeer het zo opnieuw.” + Opnieuw proberen (cleart Livewire-uploadbag). **Sync vs queue (BL-143):** de Livewire-update slaat alleen bronbytes op + rij (`variants_pending`); `ProcessIntakePhotoVariantsJob` (`ai-photo`) doet decode/resize/thumbnail/usability en dispatcht daarna AI; na store roept de wizard `pollPendingAssessments()` zodat sync-terminal resultaten de assessing-fase in dezelfde request clearen. Follow-up kwaliteit/mismatch-copy alleen via override-panel (geen dubbele error-bag). **Beoordeling (BL-127):** elke foto eindigt in terminale `assessment_status`; poll stopt op terminal; UI soft-timeout ~90 s; watchdog herpakt pending. Livewire 5xx/503 toont NL-status i.p.v. Engelse LiteSpeed-overlay.

## Gedeeld bewijs

### Klant

- Krijgt uitsluitend uploads die bij een toegewezen veilige taak horen.
- Ziet één concrete opdracht en kan **Niet veilig / niet bereikbaar** kiezen.
- Klanttoegang wordt alleen geactiveerd en de link alleen verzonden wanneer klanttaken bestaan.

### Installateur

- Kan vanuit de mobiele opnameweergave rechtstreeks foto's maken of kiezen, zonder actieve klantlink of lineaire wizard.
- Start de upload bij een ruimte, positie of verbinding; de app koppelt de foto automatisch aan precies dat dossieronderwerp.
- Een foto bij een verbinding wordt tegelijk als segmentbewijs aan die concrete aircoverbinding toegevoegd.
- Kan zonder foto bij hetzelfde onderdeel een **Technische notitie** toevoegen. Sleutel, methode en herkomst worden server-side bepaald; er is geen handmatige bron- of methodeselectie.
- Bij een ruimte- of positiefoto mag beeld-AI alleen beslisrelevante constateringen voorstellen. Zo’n voorstel blijft herkenbaar onbevestigd totdat de installateur **Klopt** kiest of de tekst aanpast.

### Datakoppeling

- `intake_uploads` blijft de private bestandsbron.
- `question_key`, `section_instance_key` en `intake_follow_up_item_id` blijven als compatibele bronkoppeling bestaan.
- `dossier_evidence_links` koppelt dezelfde upload aan één of meer technische onderwerpen/records; `pipe_route_segments` kan dezelfde bronfoto aan een verbinding koppelen.
- Bestandsbytes, EXIF en brondata worden nooit gekopieerd naar observatie-/AI-JSON.
- Eén upload mag meerdere conclusies ondersteunen zonder het bestand te dupliceren.

## Storage disks

| Disk | Root | Gebruik |
|------|------|---------|
| `local` | `storage/app/private` | **Default `MEDIA_DISK`** |
| `public` | `storage/app/public` | Niet voor intake-foto’s |
| `s3` | AWS-bucket (privé) | Via `MEDIA_DISK=s3` + AWS-env (BL-013) |

Domeincode schrijft altijd naar `config('filesystems.media')` en bewaart die diskwnaam op de rij (`disk` / `pdf_disk` / `logo_disk` / `media_disk`). Lezen, previewen, verwijderen en hard purge gebruiken de **opgeslagen** disk — nooit opnieuw `MEDIA_DISK`. Zo blijft een env-wissel naar S3 veilig zonder bestanden of DB-rijen te migreren.

## Gerichte PDF-documenten

Een PDF-upload verschijnt alleen wanneer de installateur in een aanvullende informatieronde expliciet antwoordvorm **Document (PDF)** kiest. Daardoor krijgt de normale intake geen extra scherm. `DocumentUploadNormalizer` vereist server-MIME `application/pdf`, controleert daarnaast de `%PDF-`-bestandssignatuur, begrenst de bestaande uploadlimiet en bewaart checksum/originele bestandsnaam. Documenten staan op dezelfde private `MEDIA_DISK`, zijn alleen via klanttoken of installateursauth te openen en worden met `Content-Disposition: attachment` plus `X-Content-Type-Options: nosniff` aangeboden; afbeeldingen blijven inline previews. Standaard zijn maximaal 3 PDF's per documentopdracht toegestaan (`INTAKE_FOLLOW_UP_MAX_DOCUMENTS`). Foto-normalisatie en fotokwaliteitsanalyse worden niet op documenten uitgevoerd.

Dezelfde private serve-routes blijven gelden. De technische werkplek kan nu een gerichte PDF-taak aan de klant sturen; rechtstreekse installateursdocumenten blijven buiten deze slice.

```php
'media' => env('MEDIA_DISK', 'local'),
```

## Directorystructuur

```
{disk-root}/intakes/{intake_uuid}/{question_key}/{section_instance?}/{ulid}.jpg
{disk-root}/intakes/{intake_uuid}/{question_key}/{section_instance?}/analysis/{ulid}.jpg
{disk-root}/intakes/{intake_uuid}/installer/{subject_id}/{ulid}.jpg
{disk-root}/intakes/{intake_uuid}/installer/{subject_id}/analysis/{ulid}.jpg
{disk-root}/intakes/{intake_uuid}/follow-up/{round}/{item}/{ulid}.jpg
{disk-root}/intakes/{intake_uuid}/follow-up/{round}/{item}/analysis/{ulid}.jpg
```

## Beveiliging

| Maatregel | Invulling |
|-----------|-----------|
| Private disk | `MEDIA_DISK=local` of `MEDIA_DISK=s3` (nooit `public`) |
| Serve-routes | customer-token of installer `auth` + intake-match |
| Inputtypes | jpeg, png, webp, heic/heif |
| Opgeslagen fototypes | uitsluitend JPEG; beide varianten zijn metadata-vrij |
| Max size | `INTAKE_UPLOAD_MAX_KB` (default 8192 = 8 MB) |
| Max files | vraag-`meta.max_files` of `INTAKE_UPLOAD_MAX_FILES` |

## Validatie

| Regel | Waarde |
|-------|--------|
| Max per bestand | 8 MB (configureerbaar) |
| Max per vraag | default 5 |
| Inputtypes | jpeg, png, webp, heic/heif |
| Opgeslagen fototypes | jpeg |

## Multiselect & galerijkeuze (BL-021)

De klantwizard-input voor foto-vragen:

- heeft `multiple`, zodat de aanvrager tot `meta.max_files` (of `INTAKE_UPLOAD_MAX_FILES`) in één keer kan kiezen;
- **geen** `capture="environment"` — op mobiel blijven camera én galerij bereikbaar;
- toont hoeveel slots nog over zijn en verbergt de input bij het maximum;
- uploadt per bestand via de bestaande pijplijn (MIME, size, HEIC→JPEG); één mislukte foto blokkeert de rest van de selectie niet — succesvolle uploads blijven staan, fouten worden als samengevoegde melding getoond.

## HEIC/HEIF-normalisatie (BL-008)

iPhone-foto's in HEIC/HEIF worden server-side verwerkt; de aanvrager hoeft geen instellingen te wijzigen of zelf te converteren. `UploadMimeDetector` gebruikt server-side MIME-detectie en sniffed ISO BMFF-brands wanneer PHP/host alleen `application/octet-stream` ziet. Client-MIME of extensie alleen is niet genoeg om een bestand te accepteren.

`PhotoUploadNormalizer` zet ieder ondersteund beeld via Imagick of GD om naar JPEG:

- auto-orient op basis van EXIF/oriëntatie;
- metadata strippen;
- dossiervariant maximaal `INTAKE_DOSSIER_MAX_LONG_EDGE` (default 2048px) met startkwaliteit `INTAKE_DOSSIER_JPEG_QUALITY` (82);
- analysevariant maximaal `INTAKE_ANALYSIS_MAX_LONG_EDGE` (default 1536px) met startkwaliteit `INTAKE_ANALYSIS_JPEG_QUALITY` (80);
- kwaliteit wordt per variant stap voor stap verlaagd tot het resultaat binnen `INTAKE_UPLOAD_MAX_KB` past.

De database bewaart voor beide varianten pad, MIME, grootte en checksum. `/health` exposeert `image_conversion.imagick_loaded` en `image_conversion.heic_read` zodat staging snel kan worden gecontroleerd.

## Beeldvarianten (BL-030)

BL-030 normaliseert iedere foto — niet alleen HEIC — naar twee private JPEG-varianten:

| Variant | Lange zijde | Kwaliteit | Gebruik |
|---------|-------------|-----------|---------|
| Dossier | 2048 px | 82 | Menselijke preview, galerij, HTML en installateurzoom |
| PDF-embed (alleen rendering) | 1600 px | ~75 JPEG | `EmbedPrivateReportMedia` downscaled data-URI’s voor Dompdf; originelen op disk blijven ongemoeid |
| AI-analyse | 1536 px | 80 | Vision-calls; modelescalatie krijgt alleen relevante analysekopieën |

Beide worden georiënteerd en van metadata/EXIF ontdaan; het telefoonorigineel blijft niet op disk. `path` blijft de dossiervariant; `analysis_path`, `analysis_mime_type`, `analysis_size_bytes` en `analysis_checksum` wijzen naar de AI-kopie. Nieuwe uploads gebruiken altijd de analysevariant. `AiImageResolver` heeft alleen voor historische rijen van vóór BL-030 een gecontroleerde dossierfallback, zodat bestaande opnames niet breken; de variantnaam gaat mee in de inputhash. `processing_timings` bewaart `persist_ms`/`preprocess_ms`, dossier-/analyse-afmetingen, en optioneel `network_upload_ms` (client via progress=100 → event `ai-upload-stored` → `IntakeWizard::recordNetworkUploadTiming(uploadId, ms)` → `AiTraceRecorder::recordNetworkUploadMs`, niet via Store*) voor AI-traces (BL-116 / P2).

Uitvoering en verificatie: [plans/bl-030-dossier-ai-image-variants.md](plans/bl-030-dossier-ai-image-variants.md).

## PHP- en cPanel-limieten

Applicatielimiet: **8 MB** per foto (`INTAKE_UPLOAD_MAX_KB`). PHP moet daarboven zitten.

### Gewenste waarden (in git)

`public/.user.ini` zet voor web-requests (cPanel/LiteSpeed LSAPI):

| Setting | Waarde |
|---------|--------|
| `upload_max_filesize` | **10M** |
| `post_max_size` | **12M** |
| `max_file_uploads` | **20** |
| `memory_limit` | **256M** |

**Waarom 256M (BL-141):** het Hoasted-account heeft 512 MB PMEM; Hoasted adviseert ≈ helft daarvan per PHP-proces. Was 512M (BL-106); dat liet één PHP-proces het hele PMEM-budget opeisen.

`.user.ini` geldt **niet** voor CLI (`queue:work`, `schedule:run` via cron). Op Hoasted staat CLI al op `memory_limit=256M`; `AppServiceProvider` is alleen een **vangnet** (`config('intake.php.cli_memory_limit')`) wanneer de default `-1` (unlimited) is of lager dan 256M — een hogere bewuste limiet (bijv. `phpstan --memory-limit=1G`) wordt niet verlaagd. De queue-worker blijft `--memory=256` (Laravel-restartthreshold in MB, `config('intake.php.queue_worker_memory_mb')`), gelijk aan de bestaande worker op staging/prod.

### Foto-geheugenpad (12 MP)

Zware decode/resize zit in `PhotoUploadNormalizer` (upload → dossier 2048 + analyse 1536 JPEG; EXIF/autoOrient/strip). Preferentie: **Imagick** (HEIC + resource limits 128M memory / 192M map; bron eerst naar dossier-max vóór clones). Fallback: **GD**. AI-vision (`AiImageResolver`) leest alleen de al verkleinde analysevariant; usability (`PhotoUsabilityHeuristic`) decodeert diezelfde kleinere JPEG. Gemeten `memory_get_peak_usage` voor 4032×3024 JPEG: ≈ **39 MB** piek (Imagick; delta ≈ 11 MB) — ruim onder 200 MB (test `PhotoUploadNormalizerMemoryTest`).

### Meten

- **Remote (staging):** `GET /health` → veld `php_upload` (inclusief `memory_limit`; geen SSH nodig).
- **Op de server (CLI):** `php -i | grep -E 'upload_max_filesize|post_max_size|max_file_uploads|memory_limit'` — CLI leest `.user.ini` niet; voor uploads telt de web-SAPI.

### Staging gemeten (web-SAPI via `/health`, 2026-07-18)

| Setting | Waarde |
|---------|--------|
| `upload_max_filesize` | **512M** |
| `post_max_size` | **512M** |
| `max_file_uploads` | **20** |
| App-limiet | **8192 KB** (8 MB) |

Hostlimieten liggen ruim boven het minimum; `public/.user.ini` blijft als vangnet voor omgevingen met lage defaults. BL-003: done.

### Lokaal gemeten (dev CLI, juli 2026)

| Setting | Waarde |
|---------|--------|
| `upload_max_filesize` | **2M** (CLI-default; lokaal verhogen of via `public/.user.ini` bij `php artisan serve` afhankelijk van SAPI) |
| `post_max_size` | **8M** |

Alternatief op cPanel: MultiPHP INI Editor met dezelfde minima — zie [docs/DEPLOYMENT.md](DEPLOYMENT.md). Voorkeur: `.user.ini` in git zodat limieten deploys overleven.

## Migratie naar S3 (BL-013)

Omschakelen is een env-wijziging; geen datamigratie van bestaande media.

1. Zet in `shared/.env` (nooit in git):
   ```env
   MEDIA_DISK=s3
   AWS_ACCESS_KEY_ID=…
   AWS_SECRET_ACCESS_KEY=…
   AWS_DEFAULT_REGION=eu-central-1
   AWS_BUCKET=…
   # Optioneel (MinIO/compat of custom URL):
   # AWS_URL=
   # AWS_ENDPOINT=
   # AWS_USE_PATH_STYLE_ENDPOINT=false
   ```
2. `php artisan config:cache` (of wacht op de volgende deploy).
3. Nieuwe uploads/PDF’s/logo’s/luchtfoto’s landen op S3 (visibility private).
4. Bestaande rijen behouden hun opgeslagen `disk` + `path` en blijven via de app-routes bereikbaar zolang de oude disk (meestal `local`) beschikbaar blijft.
5. Optioneel later: bestanden handmatig kopiën en rijen bijwerken — buiten scope van BL-013.

Vereiste package: `league/flysystem-aws-s3-v3` (Composer). Secrets en keys staan alleen in de server-`.env`. Zie ook [DEPLOYMENT.md § Handmatige acties](DEPLOYMENT.md#handmatige-acties-producteigenaar).
