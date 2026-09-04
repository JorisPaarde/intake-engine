# Deployment naar cPanel (staging + production)

> **Documentversie:** 2.18 · **Laatste update:** 2026-09-04 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

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

Beide workflows bouwen in GitHub Actions (Composer `--no-dev` + Vite-assets), rsyncen naar hun eigen `releases/<sha>` en roepen `deploy/activate.sh` aan. Het script controleert het verwachte `APP_ENV`, koppelt alleen de eigen shared `.env`/storage, verwijdert eventuele runtimecache uit een gekopieerde release, draait migraties + `IntakeTemplateSeeder`, seedt op staging optioneel de demo-installateur-login, cachet config/routes/views en wisselt de `current`-symlink atomisch. Per omgeving blijven de laatste drie releases bewaard.

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

`schedule:run` dekt o.a. hourly `intakes:purge-demos`, daily `intakes:send-reminders` (BL-015), daily `intakes:purge-deleted` (BL-009) en daily `product-interests:purge` (BL-043). De queue-worker verwerkt AI-samenvatting, PDF-export (BL-005) en optionele interne interesse-notificaties.

## Database bij deploy

`activate.sh` draait altijd:

1. doelpad en `APP_ENV` controleren;
2. shared `.env`/storage koppelen en stale runtimecache verwijderen;
3. `migrate --force`;
4. `db:seed --class=IntakeTemplateSeeder --force` — publiceert/bevestigt de airco-template (idempotent; bestaande gepubliceerde versie wordt niet overschreven);
5. caches opbouwen, `current` atomisch wisselen, queue herstarten en oude releases tot drie opruimen.

**Niet** in deploy: `DatabaseSeeder` / `DemoIntakeSeeder` (demo-users en demo-intakes blijven handmatig of alleen lokaal).

Templatewijzigingen: bump de versie in `database/data/templates/airco/` en laat de seeder een nieuwe published version aanmaken — in-place edits van een gepubliceerde versie gebeuren niet.

MySQL commit DDL-stappen zoals `ALTER TABLE` ook wanneer een latere stap in dezelfde Laravel-migration faalt. Daarom moeten migrations met meerdere DDL-stappen niet alleen uitbreidend maar ook hervatbaar zijn: controleer per stap `Schema::hasColumn()` / `Schema::hasTable()`, gebruik bij lange tabel- en kolomnamen een expliciete constraintnaam van maximaal 64 tekens en maak eventuele backfill idempotent. De CI-poort draait hiervoor naast de gewone SQLite-suite een volledige `migrate:fresh` tegen MySQL 8.4 en voert de dossiermigration daarna nogmaals uit alsof haar registratie na een onderbreking ontbreekt.

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

### HTTP 5xx / LiteSpeed 503 debuggen (BL-092)

LiteSpeed toont soms een kale **503 Service Unavailable** (“The server is temporarily busy…”) vóór of tijdens PHP — vooral bij zware POSTs zoals **Opname aanmaken** (adresverrijking).

1. **Applicatielog** (`shared/storage/logs/laravel.log`): zoek op `HTTP server error response` (Laravel gaf zelf ≥500, o.a. `abort(503)`) of `HTTP request ended without clean response` (PHP fatal/abort mid-request). Context bevat o.a. `request_id`, `method`, `path` (klanttokens geredacteerd), `route`, `duration_ms`, `user_id`, `demo_mode`. Domeincode kan dezelfde context meeloggen via `app(AppErrorLogger::class)->error(...)` / `->warning(...)`.
2. **Host-errorlog** (cPanel → Errors / LiteSpeed): nodig wanneer de 503 komt **zonder** PHP-trail (entry-process limit, SIGKILL, crash vóór bootstrap). Die gevallen kan de app niet loggen.
3. Vergelijk tijdstempel met de testeractie (bijv. submit op `/intakes`).

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
| 2 | **Interesseformulier intern melden** (BL-043) | `shared/.env` | Zet na SMTP ook `PRODUCT_INTEREST_MAIL_TO` op het interne opvolgadres; zie [§ Interesseformulier](#interesseformulier-bl-043). | Directe e-mailmelding naast de altijd opgeslagen inzending |
| 3 | **EP-Online voor isolatie** (BL-048) | `shared/.env` | Vraag de RVO-webservicekey aan via `epbdwebservices.rvo.nl`; zet `EP_ONLINE_ENABLED=true` en `EP_ONLINE_KEY=…`, daarna `php artisan config:cache`. Gebruik bij een bestaand dossier **Adres opnieuw controleren**. Key nooit in git. | Geregistreerd energielabel, isolatie-indicatie/energiebehoefte en waar herkenbaar woningtype; de bijbehorende klantvragen vervallen |

### Optioneel / later (niet blokkerend voor de kernflow)

| Actie | Wanneer | Vars / stappen |
|--------|---------|----------------|
| Publieke demo uitzetten | Alleen bij misbruik/load | `DEMO_ENABLED=false` in `shared/.env` + `config:cache`. Demo staat **standaard aan** (zie [§ Publieke demo](#publieke-demo-bl-001)). |
| Externe AI + foto-inferentie | Na DPIA / akkoord (BL-006/020) | `AI_PROVIDER=openai`, `AI_API_KEY=…`, geschikt multimodaal `AI_MODEL` en pas daarna `AI_PHOTO_INFERENCE_ENABLED=true`. Nu bewust `null`/`false` (soft-fail). Nooit keys in git. |
| AI-budgetcap | Verplicht vóór `AI_PROVIDER=openai` op staging/productie | Zet minstens `AI_BUDGET_DAILY_CENTS` of `AI_BUDGET_MONTHLY_CENTS`, plus conservatieve token-/beeldrates. Zonder cap faalt OpenAI bewust vóór de provider-call. |
| `MEDIA_DISK=s3` + AWS-vars | Bij storagegroei / vertrek cPanel (BL-013, klaar in app) | Zet `MEDIA_DISK=s3`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` (+ optioneel `AWS_URL`/`AWS_ENDPOINT`/`AWS_USE_PATH_STYLE_ENDPOINT`) in `shared/.env`, daarna `config:cache`. Bucketobjecten privé houden. Bestaande rijen behouden `disk`+`path` op de oude disk; geen bestands-/DB-migratie. Details: [uploads.md § Migratie naar S3](uploads.md#migratie-naar-s3-bl-013). |
| `PDOK_ENABLED=false` | Alleen als uitgaande adres-/locatiebevraging juridisch of technisch nog niet mag | Adres-autocomplete, BAG-verrijking en luchtfoto uit; handmatig adres/bouwjaar en klantfoto’s blijven werken. Geen API-key nodig. |
| `PDOK_AERIAL_ENABLED=false` | BAG mag wel, luchtfoto nog niet of WMS-verkeer ongewenst | Alleen server-side luchtfotocapture uit; BAG-feiten blijven werken. |
| Vast demo-installateuraccount | Alleen voor losse staging-inspectie buiten de publieke sessieflow | Optioneel `DEMO_INSTALLER_PASSWORD` privé zetten; de publieke demo heeft dit account niet nodig en maakt per bezoeker een eigen tijdelijk account. |
| Dev-admin (`/dev`) op staging uitzetten | Alleen als staging-inzage niet gewenst is | `DEV_ADMIN_ENABLED=false` in `shared/.env` + `config:cache`. Staat op **staging standaard aan** en op **production automatisch uit** — op production is **geen** env-var nodig (hard 404 via `EnsureDevAccess`). Toont ruwe klant-PII, dus bewust nooit op production (ADR-0008). |

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
AI_BUDGET_DAILY_CENTS=500
AI_BUDGET_MONTHLY_CENTS=5000
AI_BUDGET_RESERVE_CENTS_PER_CALL=1
AI_BUDGET_INPUT_CENTS_PER_1K_TOKENS=...
AI_BUDGET_OUTPUT_CENTS_PER_1K_TOKENS=...
AI_BUDGET_IMAGE_CENTS_PER_IMAGE=...
AI_PHOTO_INFERENCE_ENABLED=true
AI_PHOTO_INFERENCE_MAX_IMAGES=2
```

De server leest maximaal twee recente private meterkastfoto's van `MEDIA_DISK` en verstuurt ze als base64 data-URL in het providerrequest. Data-URL's/beeldbytes komen niet in database, events of logs; alleen uploadchecksums vormen de inputhash. Fout, timeout, ongeldige output of budgetlimiet blokkeert upload of intake nooit. Zet de flag bij twijfel terug op `false`; lokale fotokwaliteit, handmatige vrije-groepvraag en installateurscontrole blijven werken. Voer vóór echte klantdata de BL-020-smoke uit `docs/functional-test-status.md` uit met fictieve beelden.

## Publieke demo (BL-001)

De CTA **Probeer de demo** staat standaard aan (`DEMO_ENABLED=true`) voor gasten. Elke start maakt een uniek tijdelijk `Company`-/`User`-paar, logt de bezoeker in als installateur en begeleidt via dashboard → *Nieuwe opname* → rolkeuze. Reguliere tenants en andere demosessies blijven door dezelfde policies afgeschermd.

Na opslaan volgt dezelfde adresverrijking (PDOK/BAG/luchtfoto e.d.) en tekstinterpretatie als productie. Foto-AI, dossiersynthese en route-AI mogen meedraaien wanneer die integraties in de omgeving aan staan — zo zien prospects het echte product. Het optionele *Toon voorbeelddossier* laadt synthetische foto’s, ruimtes en vaste AI-voorbeeldresultaten als snelle boost; een live PDOK-luchtfoto van het getypeerde adres wordt daarbij niet overschreven (BL-075). Demo-opnames versturen geen mail/notificatie en genereren geen PDF.

```env
DEMO_ENABLED=true
DEMO_TTL_HOURS=2
DEMO_USER_EMAIL=demo@intake-engine.invalid
DEMO_INSTALLER_PASSWORD=          # optioneel vast account; niet nodig voor publieke demo
DEMO_THROTTLE_PER_HOUR=5
```

`intakes:purge-demos` draait hourly. Een verlopen demo wordt hard verwijderd inclusief dossierrecords, luchtfoto, dossier-/analysebeelden en daarna uitsluitend het veilig aan de slug/e-mailprefix herkenbare verweesde demo-account en -bedrijf. Een actief of regulier account wordt nooit via deze cleanup verwijderd.

`DEMO_USER_EMAIL` en `DEMO_INSTALLER_PASSWORD` blijven alleen bestaan voor een optioneel vast staging-inspectieaccount via `DemoInstallerSeeder`; de publieke sessieflow gebruikt altijd unieke `@demo.invalid`-users.

Zet `DEMO_ENABLED=false` alleen om nieuwe starts uit te schakelen, bijvoorbeeld bij misbruik/load. Daarna `php artisan config:cache` of wacht op de volgende deploy-activate. Bestaande tijdelijke demo’s blijven volgens hun TTL opruimen.

## Interesseformulier (BL-043)

De publieke funnel op `/` verwerkt `POST /interesse`. Iedere geldige inzending wordt eerst in `product_interests` opgeslagen; uitval van SMTP of queue kan de bevestiging aan de prospect daarom niet verliezen. Er wordt geen IP-adres opgeslagen. Een honeypot en IP-gebaseerde rate-limit beperken geautomatiseerde spam zonder extra persoonsgegevens te bewaren.

```env
PRODUCT_INTEREST_MAIL_TO=info@jpwebcreation.nl   # intern leadadres; leeg = alleen databaseopslag
PRODUCT_INTEREST_THROTTLE_PER_HOUR=5
PRODUCT_INTEREST_RETENTION_DAYS=365
```

Default is `info@jpwebcreation.nl` (homepage-interesse én demo-PDF-aanvragen, BL-043/BL-051). Alleen met een geldig adres én een mailer anders dan `log` wordt een interne mailable ingepland. De mail gebruikt het adres van de prospect als `Reply-To`. Bij `MAIL_MAILER=log` wordt zij bewust overgeslagen, zodat contactgegevens niet in applicatielogs belanden. Demo-PDF-aanvragen genereren dan wel de PDF voor download in de sessie.

`product-interests:purge` draait dagelijks via de scheduler en verwijdert rijen waarvan `expires_at` is verstreken. De standaardtekst op de landingspagina communiceert de maximale bewaartermijn van twaalf maanden; wijzig `PRODUCT_INTEREST_RETENTION_DAYS` daarom niet naar een langere periode zonder die tekst en het privacybeleid mee te beoordelen.

## Mail (BL-004)

Deze configuratie geldt ook voor BL-014, BL-015, BL-027 en de optionele BL-043-interessemelding.

De app stuurt (bij werkende SMTP):

- **Klantlink** na aanmaken / hergenereren / “Opnieuw mailen” (BL-004)
- **Afrondingsmail** naar de installateur na klant-afronden (BL-014)
- **Herinnering** naar de klant na `INTAKE_REMINDER_DAYS` zonder afronding (BL-015; max. één)
- **Gerichte aanvulling** naar de klant na `need_more_info`, daarna opnieuw een afrondingsnotificatie naar de installateur (BL-027)
- **Nieuwe productinteresse** naar `PRODUCT_INTEREST_MAIL_TO`, zonder dossier- of klanttoken (BL-043)
- **Demo-PDF-aanvraag** naar de prospect (PDF-bijlage) plus interne leadmail naar `PRODUCT_INTEREST_MAIL_TO` (BL-051)

De kopieerbare klantlink op de detailpagina blijft de fallback. Dashboard-markering **Nieuw afgerond** (BL-014) werkt ook zonder SMTP.

**Belangrijk (ADR-0002/privacy):** bij `MAIL_MAILER=log` worden mails met access-tokens **niet** verstuurd — anders belandt het token in `storage/logs`. Installateurs-afrondingsmail en de productinteressemelding bevatten geen token, maar worden om dezelfde reden overgeslagen: ook contactgegevens horen niet in applicatielogs. Zet op staging/productie echte SMTP:

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
- `MEDIA_DISK` moet een **private** disk zijn voor intakefoto’s en aangeleverde documenten: default `local`, of `s3` na BL-013 — nooit `public`
- Rapporten zijn HTML (`generated_reports`); PDF via lichte Dompdf-job (BL-005) — queue-worker nodig voor generatie
