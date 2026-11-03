#!/usr/bin/env bash
# Update the server to the latest version in the repository.
#   cd /var/www/fulafia_project && bash deploy/update.sh
set -euo pipefail
cd "$(dirname "$0")/.."

git fetch origin
git merge --ff-only origin/main      # refuses if the server copy has local edits that diverge

cd portal
php artisan down --retry=30 || true
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart || true
php artisan up
echo ">> Updated to $(git rev-parse --short HEAD)"
