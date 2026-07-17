#!/usr/bin/env bash
#
# Intake Engine — eenmalige lokale setup (macOS).
# Installeert het Laravel-skelet ONDER deze repo zonder bestaande
# projectbestanden (README, workflows, configs, app/Domains) te overschrijven.
#
# Vereisten: PHP 8.3+, Composer 2, Node 20+, MySQL lokaal (of pas .env aan).
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo "==> Controle vereisten"
command -v php >/dev/null || { echo "PHP ontbreekt"; exit 1; }
command -v composer >/dev/null || { echo "Composer ontbreekt"; exit 1; }
command -v npm >/dev/null || { echo "npm ontbreekt"; exit 1; }
php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' \
  || { echo "PHP 8.3+ vereist, gevonden: $(php -r 'echo PHP_VERSION;')"; exit 1; }

if [ -f artisan ]; then
  echo "==> Laravel is al geïnstalleerd (artisan gevonden) — skelet-stap wordt overgeslagen."
else
  echo "==> Laravel-skelet aanmaken (nieuwste stabiele versie)"
  TMP="$(mktemp -d)"
  composer create-project laravel/laravel "$TMP/app" --no-interaction --prefer-dist
  # Kopieer skelet zonder onze eigen bestanden te overschrijven.
  rsync -a --ignore-existing "$TMP/app/" "$ROOT/"
  rm -rf "$TMP"
fi

echo "==> Runtime-dependencies"
composer require livewire/livewire --no-interaction

echo "==> Dev-dependencies (Breeze, Pint, PHPStan/Larastan, Pest)"
composer require --dev \
  laravel/breeze \
  laravel/pint \
  larastan/larastan \
  pestphp/pest \
  pestphp/pest-plugin-laravel \
  --no-interaction --with-all-dependencies

if [ ! -d app/Http/Controllers/Auth ]; then
  echo "==> Breeze installeren (Blade + Alpine + Tailwind + Pest)"
  php artisan breeze:install blade --pest --no-interaction
fi

echo "==> Composer scripts registreren"
composer config scripts.lint "pint --test"
composer config scripts.fix "pint"
composer config scripts.analyse "phpstan analyse --memory-limit=1G"
composer config scripts.test "pest"
composer config scripts.check --json '["@lint","@analyse","@test"]'

echo "==> .env voorbereiden"
if [ ! -f .env ]; then
  cp .env.example .env
  php artisan key:generate
fi

echo "==> Frontend build"
npm install
npm run build

echo "==> Klaar. Volgende stappen:"
echo "   1. Pas DB-gegevens aan in .env en draai: php artisan migrate"
echo "   2. Start dev:  php artisan serve  +  npm run dev"
echo "   3. Kwaliteit:  composer check   (pint + phpstan + pest)"
