# Installing and updating from the repository

Source of truth: https://github.com/ladiran/fulafia_project (branch `main`).
The repository holds the code only. These are never committed and stay on each machine:
`portal/.env`, the database, uploaded files (`portal/storage/app/private`), and `vendor/`.

## Linux server (production)

First install:

```bash
git clone https://github.com/ladiran/fulafia_project.git /var/www/fulafia_project
cd /var/www/fulafia_project
bash deploy/install.sh        # creates .env and stops so you can edit it
nano portal/.env              # APP_ENV=production, APP_DEBUG=false, APP_URL, DB_*, BEHIND_CLOUDFLARE=true
bash deploy/install.sh        # migrates and caches
```

Point the web server (or the Cloudflare Tunnel origin) at `portal/public`.
Add the scheduler to cron: `* * * * * cd /var/www/fulafia_project/portal && php artisan schedule:run >> /dev/null 2>&1`.

Every update:

```bash
cd /var/www/fulafia_project && bash deploy/update.sh
```

It pulls `main` (fast-forward only), installs packages, runs migrations, rebuilds caches and restarts the queue worker.
Do not edit files on the server; change them in the repository and update.

## Local WAMP (Windows)

```powershell
git clone https://github.com/ladiran/fulafia_project.git
cd fulafia_project\portal
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
```

Later updates, from the repository root: `powershell -File deploy\update.ps1`.
See LOCAL_WAMP_GUIDE.md for the virtual host and database options.

## Making changes

Work on a branch, commit, push, then merge to `main`; servers only ever pull `main`.
A private repository needs a deploy key or token on the server (`git clone` over SSH with a read-only deploy key is recommended).
