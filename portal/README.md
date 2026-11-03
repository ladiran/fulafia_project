# Department of Physics, FULafia: Website & Portal

Laravel 13 / PHP 8.3+. Design: see `../DESIGN_FRAMEWORK.md`.

## Run locally (WAMP)

1. In WAMP, switch PHP to 8.3 or newer (needs `openssl, mbstring, pdo_mysql, pdo_sqlite, fileinfo, zip, intl`).
2. Dependencies are already installed in `vendor/`. To reinstall: `composer install`.
3. Quick start without Apache: from this folder run `php artisan serve`, then open http://localhost:8000.
4. Or via WAMP Apache: add a virtual host whose DocumentRoot is `portal/public` (for example `physics.test`), and set `APP_URL` in `.env` to match.

## Database

Dev default is SQLite (`database/database.sqlite`), so nothing else needs to run. To use WAMP's MariaDB/MySQL instead, create a database and set `DB_CONNECTION=mysql`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` in `.env`, then:

```
php artisan migrate --seed
```

## Demo accounts (development only)

Seeded by `database/seeders/DatabaseSeeder.php`. The shared password is `SEED_PASSWORD` in `.env` (default in `config/portal.php`). Change it before sharing the site with anyone.

| Role | Login |
|---|---|
| HoD (Principal Admin) | hod@physics.test |
| Examination Officer (Secondary Admin) | eo@physics.test |
| Lecturers | lecturer1@physics.test … lecturer3@physics.test |
| Student | student@physics.test or DEMO/PHY/001 |

## Scheduled job

Level Adviser expiry reminders run through the Laravel scheduler (daily 06:00). Add one cron entry (Linux server) or a Task Scheduler task (Windows):

```
* * * * * php /path/to/portal/artisan schedule:run
```

## Corrections to graduate records

Graduate records stay permanent: no screen or code path edits or deletes them. A mistake is fixed by a **correction** that is laid over the original every time the record is read (Admin > Graduates > open a graduate > Corrections; queue under Admin > Corrections).
- The EO or HoD requests it with a reason. **Only the HoD approves or rejects** (the HoD may also request). Nothing changes while it waits.
- Details that can be corrected: name, gender, matric number (must be unused), session admitted, specialization, graduation session, graduation year, degree. A score can be corrected too (CA 1, CA 2, exam, or a flag); waived and exempted courses have no score. The grade, **CGPA, credits passed and class of degree are recalculated** from the corrected scores. A correction that would take the CGPA below 1.00 is refused (that is withdrawing a degree, not correcting one).
- Approval records exactly what the register said just before. Decided corrections can never be changed or deleted. Every step is audit-logged and the requester is notified.
- A corrected name also updates the alumnus's login account. The matric number on a graduate record is corrected on the record only.
- The corrected record is what the alumni statement and dashboard, the public verification page, the graduates list and search, the departmental transcript (which lists the corrections applied) and the Senate broadsheet's class of degree show. Corrected scores are marked † on the statement.
- Database level: keep denying UPDATE/DELETE on `graduates`, `graduate_results` and `graduate_amendments` (only inserts, plus the one-time status change on `graduate_amendments` while a request is waiting).

## Result sheets in the department's templates

Built from the sample PDFs in `Result_Templates/`:

**Course result sheet** ("Examination result", per course): Results > open a course > "Course result sheet (PDF)" (the assigned lecturer, EO, HoD, or an admin with `manage_results`). Logo and heading, session, semester, course code and units, title, lecturer, then S/No, matric number, **CA 1, CA 2**, exam, total, grade and remark (Pass / Fail / Absent / Incomplete / Withheld), a grade summary (A to F and total), and signature lines for the lecturer(s) and the HoD. A sheet that is not yet approved is marked DRAFT.

**Level broadsheet in Senate format**: Admin > Broadsheets (`manage_results`). Pick a session, semester and level. One landscape page set (A3 when there are many course columns) with S/N and matric number, a **SCR / GP** pair per course (course code and units above, GP shaded, `-` for not registered), **previous cumulative / current semester / current cumulative** blocks (TCR, TCE, TGP, GPA and the CT... equivalents; the cumulative blocks appear when students have earlier results), a **remarks** column (`PASS`, `RPT: courses to repeat`, `TAKE: required courses not registered`), the summary (pass, repeat, unregistered, repeat and unregistered, probation, withdraw, total), HoD and Dean signature lines and the legend. At 400 level, second semester, graduates show `PASS (class of degree)` and the class-of-degree table is added.
- Only **published** results are used. If some courses are not published, the sheet carries a red INCOMPLETE warning and leaves them out.
- Students appear on the level they were at when they registered (`level_at_registration`), so promotion does not move them off earlier sheets.
- GPA = grade points / credits registered (failed courses count; incomplete and withheld do not; absent counts as F). Credits earned count each course once. Remarks: RPT = failed and not passed since; TAKE = core course of that semester (up to that level, after exemptions) not registered.
- **Probation / withdrawal** (confirmed by the department; value `probation_cgpa` in `config/portal.php`): a cumulative GPA below 1.00 at the end of a session (second semester) is probation. If it is still below 1.00 at the end of the immediately following session, the student is to withdraw.
- **TAKE** (confirmed): a compulsory course the student did not take, so the sheet tells them it must still be taken.
- **Dean's space** (confirmed): the broadsheet keeps the Dean's name/sign/date lines for official use, next to the HoD's.
- Scores now have **CA 1 and CA 2** (CA 1 + CA 2 must not be more than 30; exam up to 70). CA 2 is optional. CSV score uploads accept `matric, CA 1, CA 2, exam`, the older `matric, CA, exam`, or a header row in any order.
- The heading uses the university and faculty names in `config/portal.php`: "Faculty of Physical Sciences" (confirmed), not the "Faculty of Science" printed on the old sample sheets.

## Importing students from a CSV

Admin > Students > Import CSV (HoD, EO, or an admin with `manage_students`):
1. Download the template, fill it from your spreadsheet, save as CSV (commas or semicolons both work; header names are forgiving).
2. Upload it and choose a default session for rows that leave the session blank. **Nothing is created yet.** You see how many rows are ready and every problem by line number (missing name or JAMB number, duplicate matric number or email in the file or the database, a session that does not exist, a level that does not fit the admission mode, an unknown area of specialization, and so on).
3. Confirm. Valid rows become student accounts in one step; rows with problems are skipped (download the problem list, fix them, upload again). The file is checked again at confirmation, so a student added in the meantime is skipped rather than duplicated.
4. Download the **temporary passwords CSV once** and give each student their password privately. Students must change it at first sign-in. A lost file is replaced by "Reset password" on the student's page.

Details: up to 2000 students and 2 MB per file; regular students enter at 100L and Direct Entry at 200L (transfer students give 100 or 200); `current_level` lets you onboard existing students at 200, 300 or 400L (and `specialization` for 400L); spreadsheet formulas in exported files are neutralised; uploaded CSVs are deleted after confirmation and unused credential files after a day (`imports:purge`, scheduled). Results of past years are not imported by this tool.

## Departmental transcript (PDF)

For **departmental use only. It is not an official transcript** and says so in a banner on every page, in the footer, and in a warning box. There is no reference number, signature or verification code, and students have no PDF download (they keep the on-screen result page and the printable result slip).
- Only the HoD and EO: a "Departmental transcript (PDF)" link on a student's page and on a graduate's page.
- A graduate's copy comes from the frozen graduate record (so later changes never alter it). Anyone else gets an interim copy from their published results to date.
- Shows the student's details, any previous names, every semester with grades, GPA and CGPA, waived and exempted courses (with no grade points), and the cumulative totals.
- Each PDF is logged (who generated it, for which student) and carries the generator's name and the time in the footer.
- Built with Dompdf (`dompdf/dompdf`, no external service). Its font cache is `storage/app/dompdf`.

## Transfer credit exemptions

Admin > Transfers (EO evaluates, HoD approves):
- Each transfer student has one evaluation: previous institution, private transfer documents (PDF/JPG/PNG), and the list of **courses exempted** (our course, their course and grade, and a "counts as credit" tick).
- The EO submits it; the HoD approves or returns it with a note. **Approval locks it for good.**
- Until it is approved the student cannot register (and cannot graduate).
- An exempted course is not offered again and counts as satisfied for graduation (core and area-linked courses). A transfer student owes the whole programme except what is exempted, so un-exempted core courses from earlier levels are offered as **required** at registration.
- Exempted credit units count toward the credits registered and passed **only if** "counts as credit" was ticked and approved. Exempted courses appear on the permanent graduate record as "Exempted (transfer credit)".
- Documents are stored in `storage/app/private/transfer-docs` and served only to the EO and HoD. Back them up.

## Staff directory order

Staff are in three categories: Academic, Technical and Administrative (shown in that order). Academic staff carry a cadre and are listed Emeritus Professor, Distinguished Professor, **Head of Department**, Professor, Associate Professor, Senior Lecturer, Lecturer I, Lecturer II, Assistant Lecturer, Graduate Assistant. The HoD always follows the Distinguished Professor(s) whatever their own cadre, and the directory follows the HoD automatically after a handover. This needs the HoD's directory profile to be linked to their account (Admin > Site content > Staff directory). Ties use the manual order number, then the name.

## Staff accounts

Admin > Staff accounts (HoD and EO):
- Create a lecturer account (name, email, staff ID). A temporary password is shown once and must be changed at the first sign-in. Optionally adds the person to the public staff directory, linked so they can edit their own profile.
- The EO manages lecturers. The HoD also manages appointed admins and the EO. Nobody can manage their own account or the HoD's here.
- Edit, reset password, deactivate and reactivate. Deactivating signs the person out at once. A Level Adviser must be removed from the post first.
- HoD only: **Appoint the Examination Officer** (one at a time; the previous EO becomes a lecturer) and **Hand over the office of HoD** (password and confirmation required; there is always exactly one HoD, and the new HoD sets up two-factor login).
- Forgot password (staff only): `/forgot-password` emails a 60-minute link. The reply never reveals whether an email exists. Students and inactive accounts get no link. Two-factor login still applies after a reset. **Set the `MAIL_*` values in `.env` (SMTP) and `MAIL_FROM_ADDRESS`** or no email is sent (the default mailer only writes to the log).

The first HoD account must be created by hand because nobody can create the first one (see the production checklist).

## Security

- **Passwords:** at least 10 characters with upper and lower case and a number. Temporary passwords (new students, admin resets, `user:reset-password`) must be changed at the next sign-in. Changing a password signs out the user's other sessions.
- **Lockout:** 5 failed sign-ins for the same account and address block it for 15 minutes. Failures are in the audit log.
- **Two-factor login (TOTP):** compulsory for the HoD, EO and appointed admins, optional for everyone else. Works with any authenticator app. Eight one-time recovery codes are shown once. A used code cannot be replayed. The HoD can reset the EO or an appointed admin (Admin > Admins). A locked-out HoD uses a recovery code, or the server command below.
- **Server commands (run on the server):** `php artisan user:reset-2fa <email>` and `php artisan user:reset-password <email>`.
- **Headers:** Content Security Policy (scripts only from this site, no inline scripts), `X-Frame-Options: DENY`, `nosniff`, referrer and permissions policies, HSTS over HTTPS, and `no-store` on every signed-in page. Page scripts live in `public/js/app.js`; do not add inline `onclick` or `<script>` blocks (a test fails if you do).
- **Cloudflare Tunnel:** set `BEHIND_CLOUDFLARE=true` so the visitor's real address (from `CF-Connecting-IP`, trusted only when the request comes from cloudflared on this machine) is used for lockouts and the audit log.

### Production checklist

1. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, a freshly generated `APP_KEY`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `BEHIND_CLOUDFLARE=true`, and **no** `REQUIRE_ADMIN_2FA=false`.
2. Switch to MariaDB/MySQL with a dedicated database user that has no `DROP` rights. Deny `UPDATE`/`DELETE` on `graduates` and `graduate_results`.
3. Do not run the demo seeders (`DatabaseSeeder` creates accounts with a shared password). Create the real HoD with `php artisan tinker` or a one-off seeder, then change the password at first sign-in.
4. Point the web server at `portal/public` only. Keep `storage/` and `.env` out of the web root.
5. Run `php artisan config:cache route:cache view:cache` after each deployment, and add the scheduler cron entry.
6. Back up the database **and** `storage/app/private` (uploads), off the server, and test a restore.
7. Keep PHP, Composer packages (`composer update`) and the operating system patched.

## Tests

```
php artisan test
```

## Built so far

**Phase 1 (foundation):** public home page, login (matric / staff ID / email), role-based dashboards, HoD-only admin appointment, HoD-only Level Adviser appointment / renewal / removal with 2-year tenure and the 2-month expiry reminders, audit log.

**Phase 2 (courses and students):**
- Academic sessions and current semester (HoD, EO); specialization-window toggle.
- Areas of specialization (HoD only; seven seeded; deactivate, never delete).
- Courses: code, units, level, semester, Core/Elective, elective-linked-to-area, GST flag (permission `manage_courses`).
- Students: admit (Regular / Direct Entry / Transfer), search, view, edit (permission `manage_students`). Matric numbers come from the university; only the HoD can correct a name.
- Student portal: profile and course registration (core and area-linked electives are compulsory; Direct Entry students also get 100L GST courses); 400L students pick their specialization while the window is open.
- Level Adviser: list of students at their level, student details, approve or reject course registration (an expired adviser keeps access until the HoD removes them).

**Phase 3 (results engine):**
- EO/HoD assign one lecturer per course for the current session (Results page).
- Lecturers enter CA (/30) and exam (/70) per approved registration, or upload a CSV (`matric,ca,exam`). Grades A–F use the confirmed scale; flags: absent (counts as F), incomplete and withheld (not graded).
- Workflow: draft, submitted (locked), vetted (EO), approved (HoD only), published (EO). The EO or HoD can return a sheet with a reason; only the HoD can reopen an approved or published sheet (logged).
- Students see published results only, with semester GPA, CGPA, credits passed, and a printable result slip. Level Advisers and admins see the same on a student's page.
- Score maxima, pass mark and the grading scale live in `config/portal.php`.

**Phase 4 (progression and graduation):**
- Carry-overs: a failed course (not waived) is offered again in its own semester and is compulsory to re-register.
- **Automatic progression (there is no promotion step):** a session has two semesters. When the EO or HoD uses Admin > Sessions > **Start the next session**, the current session ends and every active student moves up one level (100 to 400), with a history row each. Nothing is approved. Failed courses go with the student and are compulsory again at the next registration (carry-overs come first). Deferred, suspended, withdrawn and graduated students do not move, and neither does a student admitted ahead of time for the new session. The page shows what will happen first, and it refuses to start the next session while a graduation batch for the current one is still waiting for the HoD.
- **At most 24 units a semester** (max_units_per_semester). Already-approved courses count first, then failed courses (carry-overs), then the other required courses by code; a required course that no longer fits waits for a later semester. Optional electives cannot push the total over 24.
- **Residency at 400 level:** the student may spend one session plus 2 extra (extra_sessions_at_400) at 400 level without meeting the graduation requirements. After the last one the system marks them withdrawn (reason: residency). They can appeal to the Senate; if granted, the **HoD records the approval and number of sessions** (usually one) on the student's page (Senate reference required), which also reinstates a student withdrawn for residency. The student and the admin page show the sessions left.
- Graduation eligibility checklist per 400L student: all core courses registered and passed, the student's area-linked electives passed, other registered electives passed or HoD-waived, credit minimums per admission mode (editable by HoD/EO; defaults 120 / 90 / 120), no withheld or incomplete results, CGPA at least 1.00.
- HoD-only "Waive" button on unresolved plain electives (reason required, revocable before graduation, never for core or area-linked courses). Waived courses do not count as passed.
- Batches: EO proposes eligible students, HoD approves. Approval re-checks everyone, then writes the permanent graduate record (NUC class, CGPA, credits, specialization, verification code) plus a frozen copy of every result, sets status to graduated and moves the account to alumni.
- Graduate records are append-only in the application; also revoke UPDATE/DELETE on `graduates` and `graduate_results` for the production database user.
- Alumni get a read-only statement of result; anyone can confirm a graduate at `/verify` with the printed code.
- Optional demo data: `php artisan db:seed --class=DemoGraduationSeeder`.

**Phase 5 (deferment, name change, enquiries):**
- Deferment: only the HoD can start one (Admin > Deferments). The student must be fully registered (at least one Level-Adviser-approved registration), active, not already deferred (maximum `max_deferred_sessions` = 1 in total), and the chosen session must be current or later with no approved registrations. The student becomes "deferred" (registration closed, read-only notice, left out of promotion). The EO or HoD reactivates them at the same level. A daily job (`deferments:flag-lapsed`) flags a deferment as lapsed when the session has ended without a return and notifies the HoD, who can return or withdraw the student.
- Name change (female students only): the student uploads a marriage certificate or affidavit plus the newspaper advert; the EO verifies, the HoD approves. The name updates, the old name goes into the permanent name history, the matric number is unchanged. Files are stored privately in `storage/app/private/name-changes`, served only to the EO and HoD, and must be included in backups.
- Enquiries: students send enquiries (deferment, name change, results, other); the EO or HoD replies in Admin > Enquiries.

**Phase 6 (public site and CMS):**
- Public pages: home (latest news, upcoming events, HoD message), About / Admission menus from CMS pages, news and announcements (pinned, scheduled), events calendar, staff directory with profiles, course catalogue (generated from live courses), gallery, downloads, search, contact form (honeypot + rate limit), graduate verification.
- Admin > Site content (HoD, EO, or an appointed admin with `manage_content`): pages (Markdown), news, events, staff profiles, downloads, gallery, contact messages, and site settings (home text, address, phone, email, office hours).
- Lecturers linked to a staff profile edit their own bio, research interests, publications and photo at Staff dashboard > Edit profile.
- Uploads go to private storage (`storage/app/private/cms`) and are served through `/media/{id}`, so no `storage:link` is needed. Allowed: JPG, PNG, WebP (images) and PDF, Word, Excel, PowerPoint (downloads). SVG is refused. Include `storage/app/private` in backups.
- Markdown is rendered with raw HTML stripped and unsafe links removed.
- Starter content is seeded by `SiteContentSeeder`. Pages marked "waiting for the department's approved text" must be replaced.

The seeded course list is a SAMPLE. Replace it with the approved curriculum under Admin > Courses.

Also built: security hardening (above).

Not built yet: official transcripts (none planned: the PDF is departmental only), import of past results, a Postgraduate programme.
