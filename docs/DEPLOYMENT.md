# Deployment naar cPanel (staging)

> **Documentversie:** 1.6 · **Laatste update:** 2026-07-18 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

**Statusregel:** open handmatige acties (env/host) staan in [§ Handmatige acties producteigenaar](#handmatige-acties-producteigenaar).

Afgestemd op de huidige host:

| Gegeven | Waarde |
|---|---|
| Server | `s1155.hostingsecure.com` |
| cPanel-user | `intakeengine` |
| Home | `/home/intakeengine` |
| PHP | 8.4 via CloudLinux PHP Selector (voldoet aan de 8.3+-eis) |
| Beschikbaar | SSH Access, Terminal, Cron Jobs, Git, phpMyAdmin, LiteSpeed |

## Hoe het werkt

Push naar `main` → GitHub Actions bouwt (composer `--no-dev` + Vite-assets) → rsync naar `releases/<sha>` op de server → `deploy/activate.sh` koppelt shared `.env`/`storage`, draait `migrate --force`, seedt de **IntakeTemplateSeeder** (idempotente reference-data), cachet config/routes/views en wisselt de `current`-symlink. Rollback = symlink naar de vorige release terugzetten.

```
/home/intakeengine/apps/intake-engine-staging/
├── current -> releases/abc123def456     # actieve release (symlink)
├── releases/
│   ├── abc123def456/
│   └── ...                              # laatste 5 worden bewaard
└── shared/
    ├── .env                             # secrets, overleeft deploys
    └── storage/                         # uploads + logs, overleeft deploys
```

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
nano ~/apps/intake-engine-staging/shared/.env   # inhoud: zie .env.staging.example
chmod 600 ~/apps/intake-engine-staging/shared/.env
```

Vul minimaal `APP_URL`, DB-gegevens (stap 3) en genereer na de eerste deploy een key:

```bash
cd ~/apps/intake-engine-staging/current && php artisan key:generate --force
```

### 3. Database

cPanel → **Manage My Databases**: maak database + user aan (cPanel prefixt met `intakeengine_`, bv. `intakeengine_staging`) en geef de user *All Privileges*. Zet de gegevens in `shared/.env`.

### 4. PHP-binary bepalen

CloudLinux plaatst PHP-versies onder `/opt/alt/`. Check op de server:

```bash
which php && php -v
# meestal werkt gewoon `php`; anders expliciet:
/opt/alt/php84/usr/bin/php -v
```

Gebruik het pad dat 8.4 rapporteert als `STAGING_PHP_BIN`-secret.

### 5. Document root koppelen

Maak in cPanel → **Domains** het staging-(sub)domein aan en zet de document root op:

```
/home/intakeengine/apps/intake-engine-staging/current/public
```

Kan de document root niet buiten `public_html`? Gebruik dan een symlink:

```bash
ln -sfn ~/apps/intake-engine-staging/current/public ~/public_html/staging
```

Zet daarna SSL aan via **Lets Encrypt SSL** (het huidige self-signed certificaat is niet geschikt).

### 6. GitHub secrets

Repo → Settings → Secrets and variables → Actions (of environment `staging`):

| Secret | Waarde |
|---|---|
| `STAGING_SSH_HOST` | `s1155.hostingsecure.com` |
| `STAGING_SSH_PORT` | `22` (of afwijkende poort) |
| `STAGING_SSH_USER` | `intakeengine` |
| `STAGING_SSH_KEY` | inhoud van `~/.ssh/intake_engine_deploy` (private key) |
| `STAGING_DEPLOY_PATH` | `/home/intakeengine/apps/intake-engine-staging` |
| `STAGING_PHP_BIN` | uitkomst van stap 4 |

### 7. Cron: scheduler + queue-worker

cPanel → **Cron Jobs**, twee entries (pas `PHP_BIN` aan):

```
* * * * * cd /home/intakeengine/apps/intake-engine-staging/current && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/intakeengine/apps/intake-engine-staging/current && php artisan queue:work --stop-when-empty --max-time=50 >> /dev/null 2>&1
```

Geen supervisor op cPanel; `--stop-when-empty --max-time=50` per minuut is de pragmatische variant. `queue:restart` in de deploy zorgt dat workers na een release verse code draaien.

## Database bij deploy

`activate.sh` draait altijd:

1. `migrate --force`
2. `db:seed --class=IntakeTemplateSeeder --force` — publiceert/bevestigt de airco-template (idempotent; bestaande gepubliceerde versie wordt niet overschreven)

**Niet** in deploy: `DatabaseSeeder` / `DemoIntakeSeeder` (demo-users en demo-intakes blijven handmatig of alleen lokaal).

Templatewijzigingen: bump de versie in `database/data/templates/airco/` en laat de seeder een nieuwe published version aanmaken — in-place edits van een gepubliceerde versie gebeuren niet.

## Eerste deploy

1. Serversetup hierboven afronden (vooral `shared/.env`).
2. Push naar `main` (of Actions → *Deploy staging* → *Run workflow*).
3. Na afloop: `php artisan key:generate --force` in `current/` (alleen de eerste keer).
4. Check `https://<staging-domein>` en `shared/storage/logs/laravel-*.log`.

## Rollback

```bash
cd ~/apps/intake-engine-staging
ls -1t releases/            # kies vorige release
ln -sfn "$PWD/releases/<vorige>" current
cd current && php artisan config:cache && php artisan queue:restart
```

Let op: database-migraties worden niet automatisch teruggedraaid — vandaar de afspraak "alleen additieve migraties" (zie ARCHITECTURE.md).

## Production later

Kopieer `deploy-staging.yml` naar `deploy-production.yml`, trigger op tags (`v*`) i.p.v. push, gebruik `PRODUCTION_*`-secrets en een eigen `apps/intake-engine-production`-boom plus eigen database. De server-setup is identiek.

## Handmatige acties (producteigenaar)

Alles hieronder staat **niet** in git en moet jij (of de host) zetten. Bestand op staging:  
`/home/intakeengine/apps/intake-engine-staging/shared/.env`  
Sjabloon: [`.env.staging.example`](../.env.staging.example). Na elke `.env`-wijziging: `cd …/current && php artisan config:cache` (of wacht op de volgende deploy).

### Nu open op staging

| # | Actie | Waar | Vars / stappen | Ontgrendelt |
|---|--------|------|----------------|-------------|
| 1 | **SMTP voor klantlink-mail** (BL-004) | `shared/.env` | Zie [§ Mail](#mail-bl-004). Zonder dit blijft de app bij `MAIL_MAILER=log` en **stuurt geen** klantlink-mail (bewust, ADR-0002). | Echte bezorging + smoke-test BL-004; later BL-014/BL-015-mail |
| 2 | **Publieke demo aanzetten** (BL-001) | `shared/.env` | `DEMO_ENABLED=true` (optioneel `DEMO_TTL_HOURS=12`). Zie [§ Publieke demo](#publieke-demo-bl-001). | Knop **Start demo** op `/`; daarna BL-001 → `done` na smoke |
| 3 | **Eigen (sub)domein + Let’s Encrypt** (BL-011) | cPanel → Domains / SSL | Vervang `.cpanel.site` + self-signed. Zet daarna `APP_URL=https://…` in `shared/.env` en werk de README-omgevingstabel bij. | Geen Technical Domain-tussenscherm / browserwaarschuwing voor aanvragers |
| 4 | **Cron controleren** (scheduler + queue) | cPanel → Cron Jobs | Twee jobs uit [§ Cron](#7-cron-scheduler--queue-worker) moeten actief zijn (`schedule:run` + `queue:work`). | Demo-purge, AI-jobs, latere herinneringen |

### Optioneel / later (niet blokkerend voor de kernflow)

| Actie | Wanneer | Vars / stappen |
|--------|---------|----------------|
| `AI_PROVIDER` + `AI_API_KEY` | Na DPIA / akkoord (BL-006) | Nu bewust `null` (soft-fail). Nooit keys in git. |
| Productie-`.env` | Bij eerste echte productiegang (BL-010) | Sjabloon [`.env.production.example`](../.env.production.example): SMTP verplicht, `DEMO_ENABLED=false`, eigen DB + `APP_URL`. |
| `MEDIA_DISK=s3` + AWS-vars | Bij storagegroei / vertrek cPanel (BL-013) | Bestaande rijen behouden `disk`+`path`. |
| Demo-login seeden | Alleen als je `installateur@example.com` wilt | Deploy seedt **geen** users — registreer zelf, of lokaal `DatabaseSeeder`. |

### Bewust niet handmatig doen

- Geen handmatige staging-DB-edits (migraties + `IntakeTemplateSeeder` via deploy).
- Secrets (`APP_KEY`, DB-wachtwoord, `MAIL_PASSWORD`, API-keys) nooit committen.
- PHP-uploadlimieten: al ok op staging (BL-003); `.user.ini` in git is vangnet.

## Publieke demo (BL-001)

Zet in staging `shared/.env` (zie `.env.staging.example`):

```env
DEMO_ENABLED=true
DEMO_TTL_HOURS=12
```

Daarna `php artisan config:cache` (of wacht op de volgende deploy-activate). Homepage toont **Start demo**; verlopen demo-intakes worden hourly gepurged (`intakes:purge-demos`). Productie: `DEMO_ENABLED=false` houden.

## Mail (BL-004)

Na het aanmaken van een opname (en na “Nieuwe link genereren” / “Opnieuw mailen”) stuurt de app een klantlink-mail naar `customer_email`. De kopieerbare link op de detailpagina blijft de fallback.

**Belangrijk (ADR-0002):** bij `MAIL_MAILER=log` wordt de klantlink-mail **niet** verstuurd — anders belandt het access-token in `storage/logs`. Zet op staging/productie echte SMTP:

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

Volledige checklist van open host-/env-acties: [§ Handmatige acties producteigenaar](#handmatige-acties-producteigenaar).

## PHP upload-limieten (cPanel)

Foto-uploads (Fase 4) vereisen limieten ≥ applicatielimiet (5 MB per bestand).

**Voorkeur (in git):** `public/.user.ini` zet `upload_max_filesize=10M`, `post_max_size=12M`, `max_file_uploads=20`. Die file gaat mee met elke release naar de document root.

**Meten na deploy (geen SSH):**

```bash
curl -sk https://<staging-domein>/health | jq .php_upload
```

Minima via `.user.ini`: `upload_max_filesize=10M`, `post_max_size=12M`. Staging gemeten 2026-07-18 via `/health`: **512M / 512M** (host hoger dan minimum) — zie [docs/uploads.md](uploads.md).

**Alternatief / fallback:** cPanel → MultiPHP INI Editor met dezelfde minima. CLI (`php -i`) leest `.user.ini` niet; voor uploads telt de web-SAPI.

## Bekende beperkingen

- Geen Supervisor — queue via cron
- Rollback zet alleen de code-symlink terug, niet de database
- Geen production-deployworkflow nog
- Workflow-staplabel kan “PHP 8.3” noemen terwijl `php-version` 8.4 is
- `MEDIA_DISK` moet private `local` zijn voor intake-foto’s (niet `public`)
- Rapporten zijn HTML (`generated_reports`); PDF-export bewust later (geen zware PDF-deps op shared cPanel)
