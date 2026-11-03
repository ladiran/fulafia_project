# Running the Physics Department portal on your local WAMP server

> Installing and updating from the Git repository: see INSTALL_AND_UPDATE.md

A step-by-step guide for a **local test** on your own Windows PC. Everything here was checked against your current WAMP setup (WAMP 64-bit, Apache 2.4.62, PHP 8.3.14, MySQL 9.1 and MariaDB 11.5.2).

The application lives in the **`portal`** folder next to this file:

```
C:\Users\ladir\Documents\Departmental_Website\
    DESIGN_FRAMEWORK.md      the design document
    LOCAL_WAMP_GUIDE.md      this guide
    Result_Templates\        the department's result templates
    portal\                  the Laravel application (this is what you run)
```

There are two ways to run it. **Start with Option A**: it is quicker and needs nothing else. Use Option B if you want a proper local address such as `http://physics.test` served by WAMP's Apache.

| | Option A: built-in server | Option B: WAMP Apache virtual host |
|---|---|---|
| Address | `http://localhost:8000` | `http://physics.test` |
| Needs Apache running | No | Yes |
| Needs a hosts-file edit (admin) | No | Yes |
| Database | SQLite (nothing to set up) or MySQL | SQLite or MySQL |
| Good for | Quick tests, trying features | Testing like a real site, using WAMP's tools |

---

## 1. Before you start (one-time checks)

1. **WAMP is installed and its icon is green** (all services running). If it is orange or red, see section 10.
2. **PHP must be 8.3 or newer** (the application will not run on 8.2). Yours is 8.3.14. To check or change it: left-click the WAMP tray icon, then **PHP > Version**, and pick **8.3.14** (or 8.4.0). WAMP restarts Apache.
3. **PHP extensions** needed: `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `pdo_sqlite`, `sqlite3`, `zip`, `curl`, `fileinfo`. All of them are already switched on in your PHP 8.3.14, for both Apache and the command line.
4. **Composer** is installed at `C:\Users\ladir\tools\composer\composer.bat`.
5. The project's libraries are already in `portal\vendor`. If that folder is ever missing, run `composer install` (see 2).

### Using PHP and Composer from the command line

`php` is **not** on your Windows PATH, so type the full path, or set short names for the current PowerShell window:

```powershell
Set-Alias php "C:\wamp64\bin\php\php8.3.14\php.exe"
Set-Alias composer "C:\Users\ladir\tools\composer\composer.bat"
```

You must do this again each time you open a new PowerShell window. (To make it permanent, add the two lines to your PowerShell profile.) All commands below assume the aliases are set and that you are inside the portal folder:

```powershell
cd C:\Users\ladir\Documents\Departmental_Website\portal
```

---

## 2. First-time setup of the application

Run these once, inside the `portal` folder.

```powershell
# Only if the vendor folder is missing or you are told a package is missing:
composer install

# Only if the file .env does not exist:
copy .env.example .env
php artisan key:generate
```

Your existing `portal\.env` already works for a local test, so normally you skip both of the last two commands.

Create the database tables and the demo data (this uses SQLite by default, so no database server is needed):

```powershell
php artisan migrate --seed
```

> **Fresh start at any time:** `php artisan migrate:fresh --seed` deletes everything and rebuilds the demo data. Your current test database already holds some records from development (for example a Level Adviser, a graduate with a correction, a renamed demo student). A fresh start gives you a clean, predictable demo.

Optional extras:

```powershell
# A demo final-year student with complete results, ready for the graduation steps:
php artisan db:seed --class=DemoGraduationSeeder
```

---

## 3. Option A: run it with the built-in PHP server

```powershell
php artisan serve
```

Leave that window open, then browse to **http://localhost:8000**. Press `Ctrl+C` in the window to stop it.

Other devices on your network (a phone, for instance) can use it if you start it with `php artisan serve --host=0.0.0.0` and open `http://YOUR-PC-IP:8000`. Windows Firewall may ask you to allow PHP; allow it for **Private networks** only.

You do not need Apache for Option A, so WAMP can even be stopped if you use SQLite.

---

## 4. Option B: run it through WAMP's Apache at http://physics.test

### 4.1 Add the virtual host

1. Open this file in Notepad (it already exists):
   `C:\wamp64\bin\apache\apache2.4.62.1\conf\extra\httpd-vhosts.conf`
2. Add this block at the **end** of the file and save:

```apache
<VirtualHost *:80>
  ServerName physics.test
  DocumentRoot "C:/Users/ladir/Documents/Departmental_Website/portal/public"
  <Directory "C:/Users/ladir/Documents/Departmental_Website/portal/public/">
    Options +FollowSymLinks
    AllowOverride All
    Require local
  </Directory>
</VirtualHost>
```

   - Use forward slashes `/` in these paths, as shown.
   - `Require local` makes the site reachable only from this PC. To let other computers on your network in, change it to `Require all granted`.
   - `AllowOverride All` is needed so the application's `.htaccess` (pretty URLs) works. Apache's `mod_rewrite` is already enabled on your WAMP.
   - The point of `portal\public` is important: **the web server must point at the `public` folder, never at `portal` itself.** That keeps your `.env` file and source code out of the web.

### 4.2 Tell Windows about the name

1. Open Notepad **as Administrator** (right-click Notepad, Run as administrator).
2. Open `C:\Windows\System32\drivers\etc\hosts` (set the file filter to "All files").
3. Add this line at the end and save:

```
127.0.0.1   physics.test
```

### 4.3 Restart WAMP and test

Left-click the WAMP tray icon and choose **Restart DNS**, then **Restart All Services**. Wait for the icon to turn green. Then browse to **http://physics.test**.

If you prefer, WAMP also has its own helper: open `http://localhost/add_vhost.php`, add a virtual host called `physics.test` with the folder `C:\Users\ladir\Documents\Departmental_Website\portal\public`, then restart DNS. The result is the same.

### 4.4 Match the application's address

In `portal\.env` set:

```
APP_URL=http://physics.test
```

Then clear the cached settings:

```powershell
php artisan optimize:clear
```

(Sessions and sign-ins belong to one address. If you switch between `localhost:8000` and `physics.test` you will need to sign in again on each.)

---

## 5. Choosing the database

### 5.1 SQLite (the default, nothing to do)

`portal\.env` already contains `DB_CONNECTION=sqlite`. The data lives in one file, `portal\database\database.sqlite`. This is the easiest choice for a local test and is what the automated tests assume.

### 5.2 MySQL or MariaDB from WAMP (closer to the real server)

1. In WAMP, make sure the database service is running. Your WAMP has **MySQL 9.1** (port **3306**) and **MariaDB 11.5.2** (port **3307**). Use whichever one is active (the WAMP menu shows it, and `http://localhost/phpmyadmin` will tell you).
2. Create an empty database. Open `http://localhost/phpmyadmin`, sign in as `root` (WAMP's default password is empty), choose **New**, name it `physics_portal`, collation `utf8mb4_unicode_ci`, and create it.
3. Edit `portal\.env` (comment out or replace the sqlite line):

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=physics_portal
DB_USERNAME=root
DB_PASSWORD=
```

   Use `DB_PORT=3307` if you are using MariaDB.
4. Build the tables and demo data, and clear the settings cache:

```powershell
php artisan optimize:clear
php artisan migrate:fresh --seed
```

To go back to SQLite, put `DB_CONNECTION=sqlite` back and run `php artisan optimize:clear`.

---

## 6. Signing in

The demo accounts are created by the seeder. They share one development password. **You can find it in `portal\.env` as `SEED_PASSWORD`** (if that line is absent, the default is set in `portal\config\portal.php`). It is for local testing only: never use it on a real server.

| Role | Sign in with |
|---|---|
| Head of Department (Principal Admin) | `hod@physics.test` |
| Examination Officer (Secondary Admin) | `eo@physics.test` |
| Lecturers | `lecturer1@physics.test`, `lecturer2@physics.test`, `lecturer3@physics.test` |
| Students | `student@physics.test` or matric `DEMO/PHY/001`; also `DEMO/PHY/002`, `DEMO/PHY/003` (Direct Entry), `DEMO/PHY/004` (final year) |

Students sign in with their **matric number**; staff with their **email or staff ID**.

### Two-factor login for admins

By default the HoD, EO and appointed admins must set up two-factor login (an authenticator app such as Google Authenticator or Microsoft Authenticator) the first time they sign in. Your `portal\.env` currently has:

```
REQUIRE_ADMIN_2FA=false
```

which switches that requirement off so local testing is quick. **You can set it to `true` (or delete the line) to try the real behaviour.** On a real server it must always be on. If you lock yourself out while experimenting:

```powershell
php artisan user:reset-2fa hod@physics.test
php artisan user:reset-password hod@physics.test
```

### Emails (password-reset links)

The mailer is set to `log` for local testing, so no email is really sent. A "forgot password" link appears in `portal\storage\logs\laravel.log`. To send real email, fill in the `MAIL_*` settings in `.env`.

---

## 7. A 15-minute walk-through to test everything

Use a fresh start (`php artisan migrate:fresh --seed`) so every step behaves the same.

1. **Sign in as the HoD.** Open **Sessions**: the session 2025/2026 is current. Open **Specializations** and **Courses** to see the sample data. (The sample course list is a placeholder; the real curriculum goes in under Courses.)
2. **Staff accounts:** create a new lecturer. Note the one-time temporary password, sign in as that lecturer in a private window, and change the password.
3. **Level Advisers:** appoint `Dr. Lecturer One` as adviser for 100 level.
4. **Students > Import CSV:** download the template, add two or three students, upload, check the preview, confirm, and download the temporary passwords file (it works once).
5. **Student side:** sign in as `DEMO/PHY/001`, open **Registration**, submit the courses.
6. **Level Adviser:** sign in as `lecturer1@physics.test`, open **My level**, approve the registration.
7. **Results:** as the EO open **Results**, assign lecturer one to a course, then sign in as that lecturer, enter CA 1, CA 2 and exam scores, and submit. As the EO vet it; as the HoD approve it; as the EO publish it.
8. **Student side again:** the student sees the published result, the GPA and the printable slip.
9. **PDFs:** from the course, open **Course result sheet (PDF)**. As an admin open **Broadsheets**, pick 2025/2026, first semester, 100 level.
10. **Progression and graduation:** under **Sessions** use **Start the next session** (students move up automatically; do this on a throw-away copy of the data, because it cannot be undone), then use `DemoGraduationSeeder` (section 2) to try **Graduation**, the HoD's elective **Waive** button, **Graduates**, **Corrections**, and the public **Verify** page.
11. **Public site:** under **Site content** add a news post and a page, then look at the public home page.

---

## 8. Everyday commands

```powershell
php artisan serve                      # Option A: start the built-in server
php artisan optimize:clear             # after changing .env or config files
php artisan migrate                    # apply new database changes after an update
php artisan migrate:fresh --seed       # wipe everything and rebuild the demo data
php artisan test                       # run the automated tests (they use a temporary in-memory database and never touch your data)
php artisan schedule:work              # run the scheduled jobs while this window stays open
```

**Scheduled jobs.** Three things run on a timer in a real installation: the Level Adviser expiry reminders, the lapsed-deferment check, and the clean-up of imported files. For a local test either leave `php artisan schedule:work` running in a window, or run them by hand when you want to see them work:

```powershell
php artisan advisers:send-expiry-reminders
php artisan deferments:flag-lapsed
php artisan imports:purge
```

**Where things are stored**

| What | Where |
|---|---|
| Database (SQLite) | `portal\database\database.sqlite` |
| Uploaded files and private documents | `portal\storage\app\private` |
| Error log | `portal\storage\logs\laravel.log` |
| Settings | `portal\.env` |

To keep a copy of your test data, copy the SQLite file and the `storage\app\private` folder somewhere safe.

---

## 9. Starting and stopping on a normal day

- **Option A:** start WAMP only if you use its MySQL, open PowerShell, `cd` into `portal`, run `php artisan serve`, browse to `http://localhost:8000`. Stop with `Ctrl+C`.
- **Option B:** start WAMP and wait for the green icon, then browse to `http://physics.test`. Nothing else to run.

---

## 10. If something goes wrong

| Symptom | What to do |
|---|---|
| WAMP icon stays orange or red | Port 80 may be taken (Skype, IIS or another web server). Stop the other program, or in WAMP use **Tools > Test Port 80**. A MySQL problem usually shows in `C:\wamp64\logs\`. |
| `php` is not recognised | You are in a new window. Run the two `Set-Alias` lines from section 1, or use the full path `C:\wamp64\bin\php\php8.3.14\php.exe`. |
| "Your PHP version is not compatible" / composer errors about PHP | WAMP's command-line PHP is older than 8.3. Use the full 8.3.14 path as above. |
| White page or "500 Server Error" | Open `portal\storage\logs\laravel.log`; the last lines name the problem. For more detail set `APP_DEBUG=true` in `.env` (local only), then `php artisan optimize:clear`. |
| `could not find driver` | The database driver is off in the PHP that Apache or the command line uses. Check `pdo_sqlite` or `pdo_mysql` is enabled in WAMP's PHP menu (PHP > PHP extensions). |
| `SQLSTATE ... Connection refused` | The database service is not running, or the port is wrong. MySQL is 3306 and MariaDB is 3307 on your WAMP. |
| Pages show but have no styling or logo | `APP_URL` is wrong, or the web server points at `portal` instead of `portal\public`. Fix the virtual host and run `php artisan optimize:clear`. |
| `physics.test` says "server not found" | The hosts-file line is missing, or you did not **Restart DNS** in WAMP. |
| `physics.test` shows WAMP's start page or "Forbidden" | The virtual host block is not at the end of `httpd-vhosts.conf`, has a typo, or Apache was not restarted. Check `Require local` and the folder path. |
| "419 Page Expired" after submitting a form | The page was open too long, or you changed address (cookies are per address). Reload the page and try again. |
| "Class ... not found" or missing package | Run `composer install` inside `portal`. |
| Nothing works after pulling a new version | `composer install`, `php artisan migrate`, `php artisan optimize:clear`. |
| A PDF is blank or fails | Make sure `portal\storage\app\dompdf` exists and PHP's `gd` extension is on. Large broadsheets need more memory; the application raises it by itself to 256 MB. |
| A student's or staff password is lost | `php artisan user:reset-password their-email@physics.test` gives a new temporary password. |

---

## 11. What is different on the real server

This guide is for local testing only. The real server needs more care: production settings, secure cookies, a dedicated database user, real email, backups, and the Cloudflare Tunnel set-up. Follow the **Production checklist** in `portal\README.md` before the department uses it for real, and never copy the demo accounts or the development `.env` to the server.
