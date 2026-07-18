# Deployment naar cPanel (staging)

> **Documentversie:** 1.2 · **Laatste update:** 2026-07-18 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

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

## Mail

Staging `.env` gebruikt `MAIL_MAILER=log`. Automatische klantmails zijn **niet** betrouwbaar tot SMTP is geconfigureerd. MVP stuurt daarom geen intake-link per mail; de installateur kopieert de link. Productievoorbeeld bevat SMTP-placeholders.

## PHP upload-limieten (cPanel)

Foto-uploads (Fase 4) vereisen limieten ≥ applicatielimiet (5 MB per bestand).

**Voorkeur (in git):** `public/.user.ini` zet `upload_max_filesize=10M`, `post_max_size=12M`, `max_file_uploads=20`. Die file gaat mee met elke release naar de document root.

**Meten na deploy (geen SSH):**

```bash
curl -sk https://<staging-domein>/health | jq .php_upload
```

Verwacht na actieve `.user.ini`: `upload_max_filesize=10M`, `post_max_size=12M`. Bron van waarheid voor documentatie: [docs/uploads.md](uploads.md).

**Alternatief / fallback:** cPanel → MultiPHP INI Editor met dezelfde minima. CLI (`php -i`) leest `.user.ini` niet; voor uploads telt de web-SAPI.

## Bekende beperkingen

- Geen Supervisor — queue via cron
- Rollback zet alleen de code-symlink terug, niet de database
- Geen production-deployworkflow nog
- Workflow-staplabel kan “PHP 8.3” noemen terwijl `php-version` 8.4 is
- `MEDIA_DISK` moet private `local` zijn voor intake-foto’s (niet `public`)
- Rapporten zijn HTML (`generated_reports`); PDF-export bewust later (geen zware PDF-deps op shared cPanel)
