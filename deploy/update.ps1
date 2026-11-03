# Update the local WAMP copy from the repository (run from the repo root).
$ErrorActionPreference = 'Stop'
git fetch origin
git merge --ff-only origin/main
Set-Location "$PSScriptRoot\..\portal"
composer install --no-interaction
php artisan migrate --force
php artisan optimize:clear
Write-Host "Updated to $(git rev-parse --short HEAD)"
