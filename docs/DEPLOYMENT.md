# Deployment naar cPanel (staging + production)

> **Documentversie:** 2.2 · **Laatste update:** 2026-07-21 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

**Statusregel:** staging en production zijn fysiek en logisch gescheiden; open handmatige acties (env/host) staan in [§ Handmatige acties producteigenaar](#handmatige-acties-producteigenaar).

Afgestemd op de huidige host:

| Gegeven | Waarde |
|---|---|
| Server | `s1155.hostingsecure.com` |
| cPanel-user | `intakeengine` |
| Home | `/home/intakeengine` |
| PHP | 8.4 via CloudLinux PHP Selector (voldoet aan de 8.3+-eis) |
| Beschikbaar | SSH Access, Terminal, Cron Jobs, Git, phpMyAdmin, LiteSpeed |

## Hoe het werkt

| Omgeving | URL | Trigger | GitHub environment | Serverpad | Database |
|---|---|---|---|---|---|
| staging | `https://staging.intake-engine.nl/` | push naar `main` of handmatige dispatch | `staging` | `/home/intakeengine/apps/intake-engine-staging` | `intakeengine_staging` |
| production | `https://intake-engine.nl/` | tag `v*` of bewuste handmatige dispatch | `production` | `/home/intakeengine/apps/intake-engine-production` | `intakeengine_production` |

Beide workflows bouwen in GitHub Actions (Composer `--no-dev` + Vite-assets), rsyncen naar hun eigen `releases/<sha>` en roepen `deploy/activate.sh` aan. Het script controleert het verwachte `APP_ENV`, koppelt alleen de eigen shared `.env`/storage, verwijdert eventuele runtimecache uit een gekopieerde release, draait migraties + `IntakeTemplateSeeder`, cachet config/routes/views en wisselt de `current`-symlink atomisch. Per omgeving blijven de laatste drie releases bewaard.

```
/home/intakeengine/apps/
├── intake-engine-staging/
│   ├── current -> releases/<sha>
│   ├── releases/                        # laatste 3
│   └── shared/{.env,storage/}
└── intake-engine-production/
    ├── current -> releases/<sha>
    ├── releases/                        # laatste 3
    └── shared/{.env,storage/}
```

De twee `shared`-bomen worden nooit gekoppeld of gesynchroniseerd tijdens normale deploys. De SSH-key mag gedeeld zijn; runtimecredentials, database, app-key, sessiecookie en mediaopslag niet.

## Eenmalige serversetup

Alles kan via cPanel → **Terminal** (of SSH).

### 1. SSH-deploy-key

Lokaal:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/intake_engine_deploy -C "gh-actions-deploy" -N ""
```

In cPanel → **SSH Access → Manage SSH Keys → Import Key**: plak de *publieke* key (`intake_engine_deploy.pub`) en klik daarna op **Manage → Authorize**. Test lokaal:

```bash
ssh -i ~/.ssh/intake_engine_deploy intakeengine@s1155.hostingsecure.com
# poort wijkt af? Vraag de provider (vaak 22, soms 2222)
```

### 2. Mappen + .env

Op de server:

```bash
mkdir -p ~/apps/intake-engine-staging/{releases,shared/storage}
mkdir -p ~/apps/intake-engine-production/{releases,shared/storage}
nano ~/apps/intake-engine-staging/shared/.env   # inhoud: zie .env.staging.example
nano ~/apps/intake-engine-production/shared/.env # inhoud: zie .env.production.example
chmod 600 ~/apps/intake-engine-staging/shared/.env
chmod 600 ~/apps/intake-engine-production/shared/.env
```

Gebruik per omgeving een eigen `APP_KEY`, `APP_URL`, `SESSION_COOKIE`, DB-user/database en mail-/AI-config. Genereer keys pas nadat de eerste release aanwezig is:

```bash
cd ~/apps/intake-engine-staging/current && php artisan key:generate --force
cd ~/apps/intake-engine-production/current && php artisan key:generate --force
```

### 3. Database

cPanel → **Manage My Databases**: maak twee databases met twee users aan en geef iedere user alleen *All Privileges* op zijn eigen database. Huidige namen: `intakeengine_staging` en `intakeengine_production`. Zet de credentials uitsluitend in de bijbehorende `shared/.env`.

Op 2026-07-21 zijn de bestaande gebruikers, dossiers en private media eenmalig van staging naar production gekopieerd om continuïteit op het hoofddomein te bewaren. Sessies, caches en queuejobs zijn niet meegenomen. Dit is geen terugkerende synchronisatie: beide omgevingen divergeren vanaf dat moment.

### 4. PHP-binary bepalen

CloudLinux plaatst PHP-versies onder `/opt/alt/`. Check op de server:

```bash
which php && php -v
# meestal werkt gewoon `php`; anders expliciet:
/opt/alt/php84/usr/bin/php -v
```

Gebruik het pad dat 8.4 rapporteert als `STAGING_PHP_BIN` en `PRODUCTION_PHP_BIN`; op de huidige host is dit `/usr/local/bin/php`.

### 5. Document root koppelen

De huidige koppelingen in cPanel → **Domains** zijn:

```
staging.intake-engine.nl -> /home/intakeengine/apps/intake-engine-staging/current/public
intake-engine.nl         -> /home/intakeengine/public_html
```

Het hoofddomein gebruikt de atomisch verwisselbare symlink:

```bash
ln -sfn apps/intake-engine-production/current/public ~/public_html.next
mv -Tf ~/public_html.next ~/public_html
```

Beide domeinen hebben een eigen actief Let’s Encrypt-certificaat. **Force HTTPS Redirect** hoort in cPanel voor beide domeinen aan te staan. Wijzig de hoofddomeinsymlink pas nadat production via CLI en `/health` is gecontroleerd.

### 6. GitHub secrets

Repo → Settings → Environments → `staging` / `production`. Gebruik dezelfde suffixen per omgeving:

| Staging secret | Production secret | Waarde |
|---|---|---|
| `STAGING_SSH_HOST` | `PRODUCTION_SSH_HOST` | `s1155.hostingsecure.com` |
| `STAGING_SSH_PORT` | `PRODUCTION_SSH_PORT` | `22` |
| `STAGING_SSH_USER` | `PRODUCTION_SSH_USER` | `intakeengine` |
| `STAGING_SSH_KEY` | `PRODUCTION_SSH_KEY` | inhoud van de private deploy-key |
| `STAGING_DEPLOY_PATH` | `PRODUCTION_DEPLOY_PATH` | respectievelijk `...-staging` / `...-production` |
| `STAGING_PHP_BIN` | `PRODUCTION_PHP_BIN` | `/usr/local/bin/php` |

De workflows weigeren een deploypad dat niet eindigt op de verwachte omgevingsnaam. `activate.sh` weigert daarnaast een `.env` waarvan `APP_ENV` niet overeenkomt met `staging` of `production`.

### 7. Cron: scheduler + queue-worker

cPanel → **Cron Jobs**, twee entries per omgeving:

```
* * * * * cd /home/intakeengine/apps/intake-engine-staging/current && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/intakeengine/apps/intake-engine-staging/current && /usr/local/bin/php artisan queue:work --stop-when-empty --max-time=50 >> /dev/null 2>&1
* * * * * cd /home/intakeengine/apps/intake-engine-production/current && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/intakeengine/apps/intake-engine-production/current && /usr/local/bin/php artisan queue:work --stop-when-empty --max-time=50 >> /dev/null 2>&1
```

Geen supervisor op cPanel; `--stop-when-empty --max-time=50` per minuut is de pragmatische variant. `queue:restart` in de deploy zorgt dat workers na een release verse code draaien.

`schedule:run` dekt o.a. hourly `intakes:purge-demos`, daily `intakes:send-reminders` (BL-015) en daily `intakes:purge-deleted` (BL-009). De queue-worker verwerkt AI-samenvatting en PDF-export (BL-005).

## Database bij deploy

`activate.sh` draait altijd:

1. doelpad en `APP_ENV` controleren;
2. shared `.env`/storage koppelen en stale runtimecache verwijderen;
3. `migrate --force`;
4. `db:seed --class=IntakeTemplateSeeder --force` — publiceert/bevestigt de airco-template (idempotent; bestaande gepubliceerde versie wordt niet overschreven);
5. caches opbouwen, `current` atomisch wisselen, queue herstarten en oude releases tot drie opruimen.

**Niet** in deploy: `DatabaseSeeder` / `DemoIntakeSeeder` (demo-users en demo-intakes blijven handmatig of alleen lokaal).

Templatewijzigingen: bump de versie in `database/data/templates/airco/` en laat de seeder een nieuwe published version aanmaken — in-place edits van een gepubliceerde versie gebeuren niet.

## Deployen

### Staging

1. Merge/push naar `main` of start Actions → **Deploy staging** handmatig.
2. Controleer `https://staging.intake-engine.nl/health` (`environment=staging`).
3. Controleer `apps/intake-engine-staging/shared/storage/logs/`.

### Production

1. Zorg dat de te releasen commit op `main` staat en CI groen is.
2. Maak en push een semver-tag `v*`, of start Actions → **Deploy production** bewust handmatig op de juiste ref.
3. Controleer `https://intake-engine.nl/health` (`environment=production`).
4. Controleer `apps/intake-engine-production/shared/storage/logs/` en bevestig dat staging ongewijzigd bleef.

## Rollback

```bash
cd ~/apps/intake-engine-<staging|production>
ls -1t releases/            # kies vorige release
ln -sfn "$PWD/releases/<vorige>" current
cd current
rm -f bootstrap/cache/config.php bootstrap/cache/routes-*.php bootstrap/cache/events.php
php artisan config:cache && php artisan queue:restart
```

Let op: database-migraties worden niet automatisch teruggedraaid — vandaar de afspraak "alleen additieve migraties" (zie ARCHITECTURE.md).

## Eenmalige scheiding uitgevoerd

Op 2026-07-21 is de eerdere situatie (`intake-engine.nl` → stagingmap) zonder dataverlies opgesplitst. Eerst zijn productiondatabase, media, `.env` en release geverifieerd; daarna is `public_html` atomisch naar production omgezet en is `staging.intake-engine.nl` op de bestaande stagingmap gezet. Herhaal deze kopieerprocedure niet: vervolgdeploys lopen uitsluitend via hun eigen workflow.

## Handmatige acties (producteigenaar)

Alles hieronder staat **niet** in git en moet jij (of de host) per omgeving zetten. Bestanden: `apps/intake-engine-staging/shared/.env` en `apps/intake-engine-production/shared/.env`; sjablonen: [`.env.staging.example`](../.env.staging.example) en [`.env.production.example`](../.env.production.example). Na elke `.env`-wijziging: `cd …/current && php artisan config:cache` (of wacht op de volgende deploy).

### Nu open op staging

| # | Actie | Waar | Vars / stappen | Ontgrendelt |
|---|--------|------|----------------|-------------|
| 1 | **SMTP voor mails** (BL-004/014/015/027) | `shared/.env` | Zie [§ Mail](#mail-bl-004). Zonder dit blijft de app bij `MAIL_MAILER=log` en **stuurt geen** klant-/installateursmails met tokens of notificaties (bewust, ADR-0002). | Echte bezorging + smoke-tests BL-004/014/015/027 |

### Optioneel / later (niet blokkerend voor de kernflow)

| Actie | Wanneer | Vars / stappen |
|--------|---------|----------------|
| Publieke demo uitzetten | Alleen bij misbruik/load | `DEMO_ENABLED=false` in `shared/.env` + `config:cache`. Demo staat **standaard aan** (zie [§ Publieke demo](#publieke-demo-bl-001)). |
| Externe AI + foto-inferentie | Na DPIA / akkoord (BL-006/020) | `AI_PROVIDER=openai`, `AI_API_KEY=…`, geschikt multimodaal `AI_MODEL` en pas daarna `AI_PHOTO_INFERENCE_ENABLED=true`. Nu bewust `null`/`false` (soft-fail). Nooit keys in git. |
| `MEDIA_DISK=s3` + AWS-vars | Bij storagegroei / vertrek cPanel (BL-013) | Bestaande rijen behouden `disk`+`path`. |
| `PDOK_ENABLED=false` | Alleen als uitgaande adres-/locatiebevraging juridisch of technisch nog niet mag | Adres-autocomplete, BAG-verrijking en luchtfoto uit; handmatig adres/bouwjaar en klantfoto’s blijven werken. Geen API-key nodig. |
| `PDOK_AERIAL_ENABLED=false` | BAG mag wel, luchtfoto nog niet of WMS-verkeer ongewenst | Alleen server-side luchtfotocapture uit; BAG-feiten blijven werken. |
| Demo-login seeden | Alleen als je `installateur@example.com` wilt | Deploy seedt **geen** users — registreer zelf, of lokaal `DatabaseSeeder`. |

### Bewust niet handmatig doen

- Geen handmatige staging-DB-edits (migraties + `IntakeTemplateSeeder` via deploy).
- Secrets (`APP_KEY`, DB-wachtwoord, `MAIL_PASSWORD`, API-keys) nooit committen.
- PHP-uploadlimieten: al ok op staging (BL-003); `.user.ini` in git is vangnet.

## PDOK adres/BAG/luchtfoto (BL-019)

Standaard staan `PDOK_ENABLED=true` en `PDOK_AERIAL_ENABLED=true`. De app gebruikt alleen openbare HTTPS-endpoints van PDOK Locatieserver, BAG OGC API en Luchtfoto RGB WMS; er is geen API-key. Per nieuw dossier wordt het ingevoerde adres naar PDOK gestuurd om een exact BAG-object te vinden. Bij een BAG-coördinaat haalt de server een actuele luchtfoto rond die locatie op. Uitkomsten en private luchtfoto vallen onder dezelfde dossierbewaartermijn/purge; de browser benadert WMS niet rechtstreeks.

```env
PDOK_ENABLED=true
PDOK_SEARCH_BASE_URL=https://api.pdok.nl/bzk/locatieserver/search/v3_1
PDOK_BAG_BASE_URL=https://api.pdok.nl/kadaster/bag/ogc/v2
PDOK_TIMEOUT_SECONDS=5
PDOK_AERIAL_ENABLED=true
PDOK_AERIAL_WMS_URL=https://service.pdok.nl/hwh/luchtfotorgb/wms/v1_0
PDOK_AERIAL_LAYER=Actueel_orthoHR
PDOK_AERIAL_TIMEOUT_SECONDS=4
PDOK_AERIAL_WIDTH=900
PDOK_AERIAL_HEIGHT=600
PDOK_AERIAL_GROUND_WIDTH_METERS=180
```

Vereisten: uitgaand HTTPS naar `api.pdok.nl` én `service.pdok.nl`, schrijfrechten op `MEDIA_DISK`, bronvermelding (de UI/PDF noemt PDOK Luchtfoto RGB) en een passende grondslag/privacytekst voor adres-/locatiebevraging met echte klantdata. Bij time-out of storing gaat aanmaken gewoon door; een WMS-storing wist geen BAG-feiten. Staging-smoke staat in `docs/functional-test-status.md`.

## Multimodale meterkastbeoordeling (BL-020)

Standaard wordt geen foto extern verstuurd. Activeer pas na DPIA/akkoord en met een model dat beeldinput ondersteunt:

```env
AI_PROVIDER=openai
AI_API_KEY=...
AI_MODEL=...
AI_PHOTO_INFERENCE_ENABLED=true
AI_PHOTO_INFERENCE_MAX_IMAGES=2
```

De server leest maximaal twee recente private meterkastfoto's van `MEDIA_DISK` en verstuurt ze als base64 data-URL in het providerrequest. Data-URL's/beeldbytes komen niet in database, events of logs; alleen uploadchecksums vormen de inputhash. Fout, timeout of ongeldige output blokkeert upload of intake nooit. Zet de flag bij twijfel terug op `false`; lokale fotokwaliteit, handmatige vrije-groepvraag en installateurscontrole blijven werken. Voer vóór echte klantdata de BL-020-smoke uit `docs/functional-test-status.md` uit met fictieve beelden.

## Publieke demo (BL-001)

De knop **Start demo** staat **standaard aan** (`DEMO_ENABLED` default `true`) voor **gasten**. Ingelogde gebruikers zien hem niet (wel **Open dashboard**). Elke anonieme bezoeker kan zonder account een tijdelijke airco-intake starten. Optioneel in `shared/.env`:

```env
DEMO_ENABLED=true
DEMO_TTL_HOURS=12
DEMO_THROTTLE_PER_HOUR=5
```

Zet `DEMO_ENABLED=false` alleen om de knop/route uit te schakelen (bijv. misbruik). Daarna `php artisan config:cache` (of wacht op de volgende deploy-activate). Verlopen demo-intakes worden hourly gepurged (`intakes:purge-demos`). **Let op:** als een bestaande `shared/.env` nog expliciet `DEMO_ENABLED=false` heeft, verwijder die regel of zet `true` — anders blijft de oude waarde leidend.

## Mail (BL-004)

Deze configuratie geldt ook voor BL-014, BL-015 en BL-027.

De app stuurt (bij werkende SMTP):

- **Klantlink** na aanmaken / hergenereren / “Opnieuw mailen” (BL-004)
- **Afrondingsmail** naar de installateur na klant-afronden (BL-014)
- **Herinnering** naar de klant na `INTAKE_REMINDER_DAYS` zonder afronding (BL-015; max. één)
- **Gerichte aanvulling** naar de klant na `need_more_info`, daarna opnieuw een afrondingsnotificatie naar de installateur (BL-027)

De kopieerbare klantlink op de detailpagina blijft de fallback. Dashboard-markering **Nieuw afgerond** (BL-014) werkt ook zonder SMTP.

**Belangrijk (ADR-0002):** bij `MAIL_MAILER=log` worden mails met access-tokens **niet** verstuurd — anders belandt het token in `storage/logs`. Installateurs-afrondingsmail bevat geen token maar wordt om dezelfde staging-reden overgeslagen. Zet op staging/productie echte SMTP:

```env
MAIL_MAILER=smtp
MAIL_HOST=               # bijv. mail van de host of externe provider
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="no-reply@jouwdomein.nl"
MAIL_FROM_NAME="${APP_NAME}"
```

Daarna `php artisan config:cache` (of wacht op de volgende deploy-activate). Demo-intakes (`is_demo`) mailen nooit. Lokaal: Mailpit/`array`, of bewust `log` (dan alleen kopiëren). Zie `.env.staging.example` / `.env.production.example`.

BL-027-limieten staan in dezelfde `shared/.env`:

```env
INTAKE_FOLLOW_UP_MAX_ROUNDS=3
INTAKE_FOLLOW_UP_MAX_ITEMS=5
INTAKE_FOLLOW_UP_MAX_PHOTOS=5
```

Bij `MAIL_MAILER=log` blijft de ronde bruikbaar via de bestaande kopieerbare klantlink; er wordt bewust geen token naar logs geschreven.

Volledige checklist van open host-/env-acties: [§ Handmatige acties producteigenaar](#handmatige-acties-producteigenaar).

## PHP upload-limieten (cPanel)

Foto-uploads (Fase 4) vereisen limieten ≥ applicatielimiet (5 MB per bestand).

**Voorkeur (in git):** `public/.user.ini` zet `upload_max_filesize=10M`, `post_max_size=12M`, `max_file_uploads=20`. Die file gaat mee met elke release naar de document root.

**Meten na deploy (geen SSH):**

```bash
curl -sS https://staging.intake-engine.nl/health | jq .php_upload
curl -sS https://intake-engine.nl/health | jq .php_upload
```

Minima via `.user.ini`: `upload_max_filesize=10M`, `post_max_size=12M`. Staging gemeten 2026-07-18 via `/health`: **512M / 512M** (host hoger dan minimum) — zie [docs/uploads.md](uploads.md).

**Alternatief / fallback:** cPanel → MultiPHP INI Editor met dezelfde minima. CLI (`php -i`) leest `.user.ini` niet; voor uploads telt de web-SAPI.

## Bekende beperkingen

- Geen Supervisor — queue via cron
- Rollback zet alleen de code-symlink terug, niet de database
- De hosting heeft 1 GB diskquotum; beide omgevingen bewaren daarom maximaal drie releases
- `MEDIA_DISK` moet private `local` zijn voor intakefoto’s en aangeleverde documenten (niet `public`)
- Rapporten zijn HTML (`generated_reports`); PDF via lichte Dompdf-job (BL-005) — queue-worker nodig voor generatie
