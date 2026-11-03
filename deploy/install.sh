#!/usr/bin/env bash
# First-time install on the Linux server.
#   git clone https://github.com/ladiran/fulafia_project.git /var/www/fulafia_project
#   cd /var/www/fulafia_project && bash deploy/install.sh
set -euo pipefail
cd "$(dirname "$0")/../portal"

command -v php >/dev/null || { echo "PHP 8.3+ is required"; exit 1; }
command -v composer >/dev/null || { echo "Composer is required"; exit 1; }

composer install --no-dev --optimize-autoloader --no-interaction

if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --force
    echo ">> .env created. Edit it now: APP_ENV=production, APP_DEBUG=false, APP_URL, DB_*, BEHIND_CLOUDFLARE=true."
    echo ">> Then run:  bash deploy/install.sh   again to finish."
    exit 0
fi

[ "${DB_CONNECTION:-$(grep -E '^DB_CONNECTION=' .env | cut -d= -f2)}" = "sqlite" ] && touch database/database.sqlite
php artisan migrate --force
php artisan storage:link || true
php artisan config:cache && php artisan route:cache && php artisan view:cache
echo ">> Installed. Add the scheduler to cron:  * * * * * cd $(pwd) && php artisan schedule:run >> /dev/null 2>&1"
