# Uploads & mediastorage

> **Documentversie:** 3.1 · **Laatste update:** 2026-07-31 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Status: klant-, gerichte bijdrage- en installateursfoto's, private serve-routes, generieke bewijslinks en dossier-/analysevarianten zijn **geïmplementeerd**. Directe installateurs-PDF-upload is niet gebouwd; een PDF kan wel als gerichte klanttaak worden gevraagd.

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
5. Verwijderen wist beide varianten; bij storagefalen neemt `DeleteStoredMediaJob` de retry over.
6. Installateursgalerij (detailpagina): `InstallerPhotoGalleryBuilder` groepeert foto’s per sectie/instantie en toont vraaglabels uit de gepinde templateversie (geen rauwe `question_key` / `section_instance_key`) — BL-024.

Na elke intake- of vervolgfoto-upload voert de app lokaal een niet-blokkerende bruikbaarheidscheck uit. Bij te donker of te klein beeld noemt de melding zowel de kwaliteitsverbetering als de concrete `photo_instructions` van de gepinde vraag of de gerichte foto-opdracht van de installateur, zodat de klant vóór indienen precies weet hoe en wat opnieuw in beeld moet. Omdat het kwaliteitsverdict op de upload staat, wordt dezelfde instructie na verversen, hervatten of terugnavigeren opnieuw getoond.

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
| `s3` | bucket | Later via env |

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
| Private disk | `MEDIA_DISK=local` |
| Serve-routes | customer-token of installer `auth` + intake-match |
| Inputtypes | jpeg, png, webp, heic/heif |
| Opgeslagen fototypes | uitsluitend JPEG; beide varianten zijn metadata-vrij |
| Max size | `INTAKE_UPLOAD_MAX_KB` (default 5120 = 5 MB) |
| Max files | vraag-`meta.max_files` of `INTAKE_UPLOAD_MAX_FILES` |

## Validatie

| Regel | Waarde |
|-------|--------|
| Max per bestand | 5 MB (configureerbaar) |
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
| Dossier | 2048 px | 82 | Menselijke preview, galerij, HTML/PDF en installateurzoom |
| AI-analyse | 1536 px | 80 | Vision-calls; modelescalatie krijgt alleen relevante analysekopieën |

Beide worden georiënteerd en van metadata/EXIF ontdaan; het telefoonorigineel blijft niet op disk. `path` blijft de dossiervariant; `analysis_path`, `analysis_mime_type`, `analysis_size_bytes` en `analysis_checksum` wijzen naar de AI-kopie. Nieuwe uploads gebruiken altijd de analysevariant. `AiImageResolver` heeft alleen voor historische rijen van vóór BL-030 een gecontroleerde dossierfallback, zodat bestaande opnames niet breken; de variantnaam gaat mee in de inputhash.

Uitvoering en verificatie: [plans/bl-030-dossier-ai-image-variants.md](plans/bl-030-dossier-ai-image-variants.md).

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
