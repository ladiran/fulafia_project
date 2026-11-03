# Department of Physics, Federal University of Lafia: Website & Portal Design Framework

Status: **DESIGN ONLY (v0.1). Nothing is built.** Building starts when the owner says so.
This is a living document. New conditions get appended to Section 15 and folded into the relevant sections.

---

## 1. Goals

1. A public departmental website (information, news, staff, programmes, downloads).
2. Secure portals: **Student**, **Staff**, **Admin**.
3. Full academic records: courses, registration, results, GPA/CGPA, promotion, graduation.
4. Permanent graduate records (kept for life).
5. **Upgradeable to a university-wide site**: the design is multi-department and multi-faculty from day one, even though only Physics is live at first.
6. **Owned by the department**: this is the Department of Physics' own system. It does **not** replace or depend on the university's portal. The university-issued matric number is stored, not generated, and any link to university systems is optional and later (e.g. CSV import).

## 2. Recommended Tech Stack

| Layer | Choice | Reason |
|---|---|---|
| Language | **PHP 8.2+** | Requested; runs on any cPanel/shared host or VPS |
| Framework | **Laravel** (built on 13.x, needs PHP 8.3+; code lives in `portal/`) | Built-in auth, roles/policies, migrations, queues, mail, validation, CSRF. This saves months and is far safer than hand-rolled PHP for a results system. |
| Database | **MySQL / MariaDB** | Ubiquitous on Nigerian hosting; relational integrity matters for results |
| Front end | Blade templates + **Tailwind CSS** + **Alpine.js** | Light, fast on low bandwidth, no heavy SPA build needed |
| Charts | Chart.js (free) | Result/GPA trends, department stats |
| PDF | DomPDF or mPDF (free) | Result slips, transcripts, statements of result |
| QR codes | endroid/qr-code (free) | Verifiable transcripts/certificates |
| Excel/CSV | Laravel-Excel (free) | Bulk upload of scores, students, courses |
| Email | SMTP (Gmail/Zoho/host SMTP) via Laravel Mail | Password reset, notifications |
| Bot protection | Cloudflare Turnstile or reCAPTCHA (free) | Login/contact forms |
| SMS (optional, later) | Termii / Africa's Talking | Paid per SMS; optional |
| Search | MySQL full-text first; Meilisearch later at university scale | |
| Hosting | **Departmental Linux server** (Ubuntu/Debian LTS): Nginx + PHP-FPM + MariaDB, queue worker, cron | Confirmed. See Section 10.1 |
| Public access | **Cloudflare Tunnel** (`cloudflared`) + Cloudflare free tier (DNS, WAF, rate limiting, Turnstile) | No inbound ports opened on the server |

Plain PHP without a framework is possible, but it is not recommended for this scope. Laravel is still PHP.

## 3. Architecture

**Modular monolith**: one codebase, one database, cleanly separated modules. Easy to host now, and splittable later.

```
                    ┌──────────────────────────────────────┐
   Public visitor → │  PUBLIC SITE (CMS-driven pages)      │
                    ├──────────────────────────────────────┤
   Student      → │  STUDENT PORTAL   /student           │
   Lecturer     → │  STAFF PORTAL     /staff             │
   EO / HoD     → │  ADMIN PANEL      /admin             │
                    ├──────────────────────────────────────┤
                    │  Core modules: Auth · RBAC · Academic│
                    │  · Results · Graduation · CMS ·      │
                    │  Notifications · Audit · Reports     │
                    ├──────────────────────────────────────┤
                    │  MySQL  ·  File storage  ·  Queue    │
                    └──────────────────────────────────────┘
```

### 3.1 Upgrade path to a university website (key design decision)

Every academic record hangs off an **organisation hierarchy** instead of being hard-wired to Physics:

```
University (Federal University of Lafia)
 └── Faculty (Physical Sciences)
      └── Department (Physics)   ← the only one active at launch
           └── Programme (B.Sc. Physics, later others)
```

- Tables carry `department_id` (and `programme_id` where relevant).
- Every query is **scoped** to the user's department(s) through a global scope.
- Upgrade to the university site = add Faculty and Department rows, add admins, and add a university-level layer (a "University Admin" role above all departments plus a landing site). **No rewrite and no data migration.**
- Public site themes are layered: University theme → Faculty theme → Department theme.
- Optional later: sub-domains per department (`physics.fulafia.edu.ng`) resolved to a department context.

## 4. Roles & Permissions (RBAC)

Role-based access with fine-grained permissions. Roles are bundles of permissions, and permissions can also be granted individually.

| Role | Who | Notes |
|---|---|---|
| **Principal Admin (PA)** | HoD | One at a time. Full control, including appointing and removing admins. |
| **Secondary Admin (SA)** | Examination Officer | Academic management: courses, results, promotion, graduation (proposal) |
| **Appointed Admin** | Anyone the PA appoints | Scoped permission sets, optionally time-limited (see 4.2) |
| **Lecturer / Staff** | Academic staff | Upload scores for assigned courses, view own classes |
| **Level Adviser (LA)** | A lecturer appointed by the HoD, one per level (100–400), for a 2-year tenure | See all students at their level and approve their course registration (Section 4.3) |
| **Student** | Levels 100–400 | Own data only |
| **Graduate / Alumni** | Former students | Read-only access to permanent records |
| **Public** | Anyone | Public site only |

### 4.1 Permission matrix (initial draft)

| Capability | PA (HoD) | SA (EO) | Appointed Admin | Lecturer | Student |
|---|:-:|:-:|:-:|:-:|:-:|
| Appoint / revoke admins | ✔ | ✘ | ✘ | ✘ | ✘ |
| Appoint / renew / remove Level Advisers | ✔ (only) | ✘ | ✘ | ✘ | ✘ |
| See all students and details at a level; approve their course registration | ✘ | ✘ | ✘ | ✔ (LA of that level only) | ✘ |
| Manage site settings, sessions, grading scale | ✔ | partial | per grant | ✘ | ✘ |
| Create / edit courses and course catalogue | ✔ | ✔ | per grant | ✘ | ✘ |
| Assign lecturers to courses | ✔ | ✔ | per grant | ✘ | ✘ |
| Register / admit students, bulk import | ✔ | ✔ | per grant | ✘ | ✘ |
| Upload scores (assigned courses) | ✘ | ✔ (all) | per grant | ✔ (own) | ✘ |
| Vet / approve results | ✔ (final) | ✔ (vet) | per grant | ✘ | ✘ |
| Start the next session (students move up automatically) | ✔ | ✔ | ✘ | ✘ | ✘ |
| Record a Senate residency extension at 400 level | ✔ (only) | ✘ | ✘ | ✘ | ✘ |
| Graduate students | ✔ approve | ✔ propose | ✘ | ✘ | ✘ |
| **Waive an elective course for a student** | ✔ (only) | ✘ | ✘ | ✘ | ✘ |
| Set graduation credit minimums | ✔ | ✔ | ✘ | ✘ | ✘ |
| Add / rename / deactivate areas of specialization | ✔ (only) | ✘ | ✘ | ✘ | ✘ |
| Tag a course as required for a specialization | ✔ | ✔ | per grant | ✘ | ✘ |
| Initiate deferment | ✔ (only) | ✘ | ✘ | ✘ | ✘ |
| Approve a marriage-related name change | ✔ (only) | verify documents | ✘ | ✘ | request (female students only) |
| Admit students (Regular / Direct Entry / Transfer) | ✔ | ✔ | per grant | ✘ | ✘ |
| Approve transfer credit evaluation | ✔ | propose | ✘ | ✘ | ✘ |
| Choose area of specialization (at 400 level) | change with reason | change with reason | ✘ | ✘ | ✔ (once, within window) |
| Amend an approved result | ✔ | request only | ✘ | ✘ | ✘ |
| Publish news / events / pages | ✔ | ✔ | per grant | ✘ | ✘ |
| View audit log | ✔ | own actions | ✘ | ✘ | ✘ |
| View own results / transcript | n/a | n/a | n/a | n/a | ✔ |

### 4.2 Admin appointment (HoD powers)

- The PA picks a staff or user account, chooses a **role template** or ticks individual permissions, and optionally sets an **expiry date**.
- Every appointment, change and revocation is written to the audit log.
- The EO is created as the **default Secondary Admin** template.
- **Office handover**: when a new HoD takes over, the PA role is transferred with a confirmation step (old HoD is demoted, new HoD promoted). This avoids being locked out.
- The system never allows zero Principal Admins.

### 4.3 Level Advisers
- **Appointed only by the HoD**, from academic staff, **one per level** (100, 200, 300, 400). The HoD can renew or reassign.
- **Tenure is two years.** Each appointment stores start date, end date and status (`ACTIVE`, `EXPIRING`, `EXPIRED`, `REMOVED`).
- **Expiry reminder:** a daily scheduled job finds appointments ending within **two months** and sends an in-portal notification (banner plus notification list, with optional email) to **both the LA and the HoD**. It repeats until the HoD renews or removes the appointment.
- **Expiry does not remove the LA.** When the tenure date passes the status becomes `EXPIRED` with a visible badge, and the reminders continue. **Only the HoD can remove** an LA, even after expiry.
- **Scope:** an LA sees **all students at their level** with their full details and registered courses, and is the one who **approves course registration** for those students. The scope follows the *level*, so as students are promoted the incoming cohort falls under the next LA.
- All appointments, renewals, removals and approvals are audit-logged.

## 5. Modules & Features

### 5.1 Public website (CMS-driven)
Home · About the Department · Vision & Mission · Message from HoD · Programmes (B.Sc. Physics) · Staff directory (profiles, research interests, publications) · Courses catalogue · Research groups & labs · News & announcements · Events/calendar · Gallery · Downloads (handbook, forms, timetables, past questions) · Admission info · Alumni page · Contact (+ map) · Search · Result/transcript verification (QR/code).

### 5.2 Authentication
- Students log in with **matric number**. Staff log in with email or staff ID. Admins use email.
- First-login forced password change, password reset by email, and login throttling.
- **2FA (TOTP) mandatory for PA/SA/Admins**, optional for others.
- Session timeout, device/session list, and logout of all devices.

### 5.3 Student portal
- **Profile**: bio-data, photo, matric no., entry mode (UTME / Direct Entry), session admitted, current level, status, contact, next of kin.
- **Course registration** per semester (with credit-unit limits and carry-over handling), approval by level adviser.
- **Results**: per course (CA, exam, total, grade, grade point), per semester GPA, running CGPA, per-level view, full academic history across all levels, downloadable result slip PDF.
- **Academic progress**: level, credit units earned vs. required, outstanding carry-overs, graduation checklist.
- Timetable, announcements, downloadable forms, and a complaints/enquiries form (e.g. missing score).
- Notifications (in-app and email) when results are published.

### 5.4 Staff (lecturer) portal
- Profile and public profile editing.
- My courses (current and past) and class lists.
- **Score upload** via form or Excel/CSV template (CA + exam), save as draft, and submit for vetting.
- View submission status, and respond to EO queries or corrections.
- Post course materials and announcements to the enrolled class.

### 5.5 Admin panel (PA / SA / appointed)
- **Dashboard**: students per level, results pending vetting, registration stats, recent activity.
- **Academic setup**: sessions, semesters, grading scale, credit rules, class-of-degree boundaries (all configurable).
- **Course management** (EO and HoD): catalogue (code, title, units, level, semester, core/elective, prerequisites), activate/deactivate per session, assign lecturers, and set the curriculum per programme and level.
- **Student management**: add or bulk-import students, edit records, change status (suspend, withdraw, defer), and reset passwords.
- **Results management**: see Section 7.
- **Promotion and graduation**: see Section 8.
- **User and admin management**: appoint admins, create staff accounts, and assign roles (PA only for admin appointments).
- **CMS**: pages, news, events, staff profiles, downloads, and menus.
- **Reports/exports**: broadsheets, class lists, result summaries, graduation lists, and carry-over lists (PDF/Excel).
- **Audit log** viewer and system settings.

## 6. Core Data Model (conceptual)

```
universities ─< faculties ─< departments ─< programmes
users (auth) ──1:1── staff_profiles | student_profiles
roles ─< role_user >─ users            permissions ─< role_permission

academic_sessions (2025/2026) ─< semesters (First, Second)
courses (code, title, units, level, semester, type: C=Core | E=Elective,
         specialization_id nullable (elective required for that area), is_gst, department_id)
course_offerings (course × session × semester × lecturer)
course_prerequisites

students (matric_no, user_id, programme_id, entry_session,
          entry_mode: REGULAR | DIRECT_ENTRY | TRANSFER, entry_level,
          jamb_reg_no, gender, current_level, status,
          specialization_id (nullable until chosen at 400L), ...)
student_name_history (student, previous_name, new_name, reason: marriage,
          documents, verified_by, approved_by, effective_date)  ← permanent
transfer_records (student, previous_institution/department, documents,
          credit_evaluation, evaluated_by, approved_by)
course_exemptions (student, course, basis: DIRECT_ENTRY | TRANSFER, approved_by)
deferments (student, session, reason, initiated_by = HoD, start, expected_return,
          status: ACTIVE | RETURNED | LAPSED)
specializations (name, is_active, created_by, ...)  ← HoD-managed
level_adviser_appointments (staff_id, level, start_date, end_date (= start + 2 years),
          status, appointed_by = HoD, removed_by, removed_at, reminder_sent_at)
admission_documents, enquiries
student_level_history (student, session, level, outcome: promoted/repeat/...)
course_registrations (student × offering, status, approved_by)
results (registration_id, ca, exam, total, grade, grade_point,
         status: draft → submitted → vetted → approved → published,
         submitted_by, vetted_by, approved_by, timestamps)
result_amendments (result_id, old/new values, reason, authorised_by)  ← immutable log
semester_summaries (student, session, semester, tcu, tgp, gpa, cgpa)

course_waivers (student, course_registration/course, reason, waived_by = HoD,
                waived_at, revoked_at, revoked_by)   ← electives only, fully audited
graduation_requirements (programme, entry_mode, min_credits_registered, min_credits_passed,
                set_by, effective_from)   ← versioned, editable by HoD or EO
graduation_batches (session, proposed_by, approved_by, status)
graduates  ← PERMANENT, append-only (see Section 8)
graduate_result_snapshot ← frozen copy of every course result

cms_pages, posts, events, downloads, media, menus
notifications, audit_logs, settings
```

Key rules:
- **Records are never hard-deleted.** Soft delete plus status flags.
- GPA/CGPA are computed from results by a single service class. Stored summaries are cached and recomputable.
- Grade scale and class boundaries live in **settings**, not code. **Confirmed grade scale** (X = total score %):

  | Grade | Score | Grade point |
  |---|---|---|
  | A | X ≥ 70 | 5 |
  | B | 60 ≤ X < 70 | 4 |
  | C | 50 ≤ X < 60 | 3 |
  | D | 45 ≤ X < 50 | 2 |
  | E | 40 ≤ X < 45 | 1 |
  | F | X < 40 (fail) | 0 |

  Pass = grade E or above (score ≥ 40). Class-of-degree boundaries default to NUC (First ≥ 4.50, 2:1 ≥ 3.50, 2:2 ≥ 2.40, Third ≥ 1.50, Pass ≥ 1.00) and are **still to be confirmed**.
- **Course type** is stored per course: **C (Core)** or **E (Elective)**, and shown beside the course code everywhere (registration, results, transcripts).
- An elective can additionally be **linked to an area of specialization**. For students in that area it behaves like a core course (must be registered and passed, no waiver). For all other students it is an ordinary elective. Any course with no link is a plain elective (Section 8.5).

## 7. Results Workflow

```
Lecturer uploads (DRAFT) → submits (SUBMITTED)
        → EO vets, queries or returns to lecturer (VETTED)
        → HoD approves (APPROVED) → EO publishes (PUBLISHED, visible to students)
```

- Students see only **PUBLISHED** results.
- After approval, a result is **locked**. Changes need an *amendment request* (EO) that the HoD authorises. The old and new values, reason and approver are logged.
- Per-course and per-session broadsheets are generated automatically.
- Missing or incomplete scores are flagged (absent, incomplete, withheld).

## 8. Student Lifecycle, Progression & Graduation

### 8.1 Status state machine

```
ADMITTED → ACTIVE(100) → ACTIVE(200) → ACTIVE(300) → ACTIVE(400) → GRADUATED
                    ↘ side states: SUSPENDED · DEFERRED · WITHDRAWN · REPEATING
```

- Entry level depends on admission mode (Section 8.4): Regular 100L, Direct Entry 200L, Transfer 100L or 200L.
- `DEFERRED` is entered only by HoD action (Section 8.6).
- **Progression is automatic** (department decision, v1.8). A session has two semesters. When the next session starts, every active student moves up a level with no approval step, carrying failed courses along. Each student's registration is capped at **24 units a semester**, failed courses first. At 400 level a student who has not graduated may stay **two more sessions**, then is marked withdrawn, unless the Senate grants an extension that the HoD records. Movements are recorded in `student_level_history`.
- **The academic calendar drives the portal** (v1.9). The official calendar is held per session. Dates marked as controlling decide when course registration is open, when lecturers can enter and submit results, and when the second semester begins. The HoD alone changes the calendar and can force any window open or closed (every change is audited). The EO and HoD can still work on result sheets outside the window. Faculty-wide activities of other faculties are kept on the calendar for information only.
- Carry-over courses are tracked and must be passed before graduation.

### 8.2 Graduation rule
A student is **eligible to graduate** only when **all** hold:
1. **Every Core (C) course in the student's required curriculum is registered and passed** (a failed core course stays outstanding as a carry-over until passed, across all levels). Courses exempted by admission mode (Section 8.4) are excluded.
2. **Every specialization-linked elective for the student's chosen area is registered and passed.** These cannot be waived.
3. **Every other elective the student registered is either passed or waived by the HoD.** A failed elective that is not waived must be **re-registered and passed**. It can never be replaced by another elective.
4. A student at 400 level has **chosen an area of specialization**.
5. **Minimum credits registered** is met (configurable).
6. **Minimum credits passed** is met, set per entry mode. Waived courses do **not** count toward credits passed.

   | Entry mode | Minimum credits passed (default) |
   |---|---|
   | Regular | **120** |
   | Direct Entry | **90** |
   | Transfer | **120** (follows Regular) |

   The HoD or EO can change these values. The minimum credits registered is set the same way (value to be supplied).
7. No outstanding withheld or incomplete result.

The credit minimums are set in *Admin → Graduation Requirements* by the **HoD or EO**. They are versioned, so changing them never silently alters records already graduated.

**Elective waiver (HoD only)**
- On a student's graduation-eligibility page, every unpassed elective shows a **"Waive"** button, visible to the HoD only.
- Clicking it asks for a **reason** and a confirmation. The system records HoD, date and reason in `course_waivers`.
- A waived elective is marked *WAIVED* on the student's record and ignored by the graduation check (it is neither a pass nor a fail, and does not affect GPA).
- A waiver can be revoked by the HoD before graduation. After graduation it is frozen in the snapshot.
- Core courses and specialization-linked electives show no waive button.
- A failed, non-waived elective shows as "must re-register" and is carried forward until passed.

**Eligibility page (EO/HoD)** shows a checklist per student: outstanding core, outstanding electives (with Waive button), credits registered vs. required, credits passed vs. required, and a green or red verdict.

Process: system lists eligible students → **EO proposes** a graduation batch → **HoD approves** → the system performs one atomic action:
1. Writes the student into `graduates` with **graduation details**: graduation session/year, class of degree, final CGPA, total units, degree title, matric no., date of approval, and approver.
2. Writes a **frozen snapshot** of all course results.
3. Changes status to GRADUATED and moves the user from the Student role to the **Alumni** role.
4. Removes them from active level lists, but their data is never removed.

### 8.3 Permanent ("kept for life") record
- The `graduates` and snapshot tables are **append-only**. There is no delete in the UI, and database permissions block deletes.
- Alumni can log in (read-only) to view their results and download a transcript or statement of result.
- A public **verification page** (QR code or reference number) confirms a graduate's authenticity to employers and institutions.
- Corrections to graduate records need an HoD-authorised amendment with a full audit trail.
- Backups of these tables are retained indefinitely (see Section 10).

### 8.4 Admission modes

| Mode | Route | Entry level | Course handling |
|---|---|---|---|
| **Regular** | JAMB (UTME) | 100L | Full curriculum, 100–400 |
| **Direct Entry** | JAMB | 200L | Exempted from all 100L courses **except GST (general) courses**, which they must still register and pass |
| **Transfer** | From other universities or other departments in the faculty | 100L or 200L (never above 200L) | The **EO evaluates** the previous courses from the transfer documents and the **HoD approves**; equivalents are recorded as exemptions (`course_exemptions`); everything else must be taken |

- The required curriculum is computed **per student** from entry mode and exemptions, and is what the graduation check uses.
- GST courses are flagged (`is_gst`) so the Direct Entry exemption can leave them in.
- Transfer admission stores the previous institution, uploaded transfer documents and the credit evaluation. The EO evaluates the courses from those documents and the HoD approves. The evaluation is locked after approval.
- Admission records the entry mode, entry session and (for JAMB students) the JAMB registration number.
- **Matric numbers are issued by the university.** The EO enters or imports them at admission. The system checks uniqueness and format, never generates them, and never allows a change except by HoD-authorised correction (logged).
- Exempted courses are not counted as passed credits unless the HoD sets otherwise (see open question).

### 8.5 Areas of specialization (400 level)
- Initial areas: **Atmospheric Physics, Quantum Information Theory, Geophysics, Photonics, Material Physics, Nuclear Physics, Radiation & Biophysics.**
- **Only the HoD** can add, rename, deactivate or reactivate an area. An area with students attached is never hard-deleted. It is deactivated, so it disappears from new choices but keeps existing records intact.
- Each area has its own set of linked electives (Section 6). The EO or HoD links courses to areas.
- The student chooses an area in the portal during a defined **selection window at the beginning of 400 level**, as part of 400L registration. Students cannot register 400L specialization courses until an area is chosen. After the window closes, a change needs a reason and EO/HoD action, and is logged.
- Registration for the area-linked electives is enforced for that area's students, and the portal pre-fills them.
- The chosen area appears on the transcript and the permanent graduate record.

### 8.6 Deferment
- **Only the HoD can initiate** deferment. Students ask through the enquiries form, and the HoD acts on it. There is no student "defer" button.
- **Eligibility check:** the student must already be **fully registered** (admission completed and course registration done for at least one session). The system blocks the action otherwise.
- The deferment is recorded for a specific session with a reason and expected return. Status becomes `DEFERRED`, course registration and result upload are blocked for that session, and the portal switches to read-only with a "deferred" notice.
- The deferred session does not count as a failed or repeated session. On return the EO or HoD reactivates the student at the same level.
- **Maximum deferment is one session** for a student, enforced by the system. If the student has not returned when the deferred session ends, the record is flagged `LAPSED` for the HoD to resolve. Every action is audit-logged.

### 8.7 Change of name on marriage
- **Only female students** may change their name (marriage-based) after admission.
- **Flow:** the student submits a request in the portal with the **mandatory documents: marriage certificate or affidavit, plus a copy of the newspaper advertisement of the change of name** → the **EO verifies** the documents → the **HoD approves** or rejects.
- On approval the active name is updated. The previous name is kept forever in `student_name_history`. The matric number never changes.
- Results, transcripts and the graduate record show the current name with a "formerly" line where needed.
- Documents are stored in private storage and visible only to the EO and HoD. Every step is audit-logged.

## 9. UI / UX Principles
- **Mobile-first**: most students use phones. Pages must be light and work on poor networks.
- Clean, academic look with university colours and logo. A shared design system for public site and portals.
- Role-aware navigation, so each user sees only what they can do.
- Accessible: readable contrast, keyboard navigation, alt text.
- Light/dark mode (optional).

## 10. Security, Privacy & Reliability
- Passwords hashed with Argon2/bcrypt, CSRF protection, prepared statements (no SQL injection), output escaping (XSS), and rate limiting.
- HTTPS only. Secure and HttpOnly cookies, plus security headers.
- **2FA for all admins.** Authorization is enforced server-side through policies, never only in the UI.
- File upload validation (type, size, scanning where possible), stored outside the web root.
- Full **audit log**: who did what, when, and from where (logins, result changes, admin appointments, graduation).
- Compliance with the **Nigeria Data Protection Act (NDPA) 2023**: consent notice, data minimisation, access limited to need.
- **Backups**: automated daily database and file backups, off-site copy, periodic restore tests, and permanent retention for graduate records.
- Error monitoring and logging. Maintenance mode for results-processing windows.

### 10.1 Hosting & deployment (departmental Linux server + Cloudflare Tunnel)
- **Stack on the server:** Ubuntu/Debian LTS, Nginx, PHP-FPM, MariaDB, a queue worker (systemd/Supervisor) and cron for scheduled jobs.
- **Cloudflare Tunnel (`cloudflared`)** publishes the site without opening inbound ports or needing a public IP. Nginx listens on localhost only. The firewall allows SSH from trusted sources only.
- **Cloudflare features used (free tier):** DNS, WAF, rate limiting on login routes, Turnstile on forms. Optionally **Cloudflare Access (Zero Trust)** as an extra gate in front of `/admin`.
- **Real client IP:** the app trusts Cloudflare headers (`CF-Connecting-IP`) so rate limiting and the audit log record the correct IP.
- **Backups matter more on a single server:** nightly encrypted DB dumps and file backups to a second disk **and** an off-site location (another machine or cloud bucket), with periodic restore tests. Graduate records are retained permanently.
- **Power and uptime:** a UPS and graceful shutdown, plus an uptime monitor with alerts. Cloudflare can show a friendly "temporarily offline" page when the tunnel is down.
- **Email:** outgoing mail goes through an external SMTP service (not the server's own mail), for reliable delivery.
- **Updates:** a documented, scripted deployment (pull, migrate, cache), OS security patching, and a staging copy for testing before production changes.

## 11. Proposed Project Structure (Laravel)

```
app/
  Models/            (User, Student, Course, Result, Graduate, ...)
  Http/Controllers/  Public/ Student/ Staff/ Admin/
  Policies/ Services/ (GradingService, PromotionService, GraduationService)
  Jobs/ Notifications/ Imports/ Exports/
database/migrations, seeders
resources/views/     public/ student/ staff/ admin/ layouts/ components/
routes/              web.php, student.php, staff.php, admin.php
storage/ (private uploads)  tests/ (grading, promotion, graduation must be tested)
```

## 12. Phased Roadmap (when building starts)

| Phase | Deliverable |
|---|---|
| 0 | Setup: repo, environment, hosting plan, branding, final requirements sign-off |
| 1 | Foundation: org hierarchy, auth, 2FA, RBAC, HoD/EO accounts, admin appointment, audit log |
| 2 | Academic core: sessions, courses, curriculum, students, bulk import, registration |
| 3 | Results engine: upload, vetting/approval workflow, GPA/CGPA, student results view, PDFs |
| 4 | Promotion and graduation, graduates archive, alumni access, verification page |
| 5 | Public site and CMS: pages, news, staff directory, downloads, events |
| 6 | Hardening: notifications, reports, security review, backups, load/UAT testing, training |
| 7 | Go-live and data migration of existing records |
| Later | University layer: add faculties and departments, university landing site, shared SSO |

## 13. Testing & Quality
Automated tests for the highest-risk logic: GPA/CGPA calculation, grade boundaries, promotion rules, graduation eligibility, and permission checks. User acceptance testing with the EO and HoD using real past results before go-live.

## 14. Assumptions Made (please confirm or correct)
1. 4-year B.Sc. programme (100–400), two semesters per session.
2. ✔ Confirmed: grading scale A–F as in Section 6, pass mark 40. Class-of-degree boundaries default to NUC, configurable.
3. ✔ Confirmed: Core (C) must be passed once registered; Electives (E) may be waived by the HoD; minimum credits registered and passed are set by the HoD or EO.
4. Lecturers upload scores themselves (the EO can override or upload for all).
5. One HoD (PA) at a time, and the EO is a fixed Secondary Admin role.
6. Existing student/result data will be imported at go-live (format to be confirmed).
7. ✔ Confirmed: all core courses must be registered and passed. Specialization-linked electives must be registered and passed. Other electives are passed or HoD-waived. A failed non-waived elective is re-registered, never replaced.
8. ✔ Confirmed: waived courses do not count toward credits passed. NUC default classes of degree apply.
9. ✔ Confirmed: three admission modes (Regular, Direct Entry, Transfer), HoD-managed specializations, HoD-only deferment, and HoD-approved marriage name change for female students.
10. ✔ Confirmed: the system is the department's own, separate from the university portal, and hosted on the departmental Linux server behind a Cloudflare Tunnel.
11. ✔ Confirmed: minimum credits passed are 120 (Regular and Transfer) and 90 (Direct Entry). Specialization is chosen at the beginning of 400L. Transfer students can enter at 100L or 200L, evaluated by the EO and approved by the HoD. Deferment is one session maximum. Marriage name change needs a certificate or affidavit plus a newspaper advert. The university issues matric numbers.

## 15. Open Questions & Change Log

Open questions (answers shape the build):
1. Should lecturers upload scores, or does only the EO? Is there a vetting step before the HoD?
2. Are there other programmes (e.g. Postgraduate) to plan for?
3. Does "pass ALL courses in 400 level" also require all 100–300 courses (including carry-overs) to be cleared? (The design assumes yes.)
4. *(Resolved in v0.3: NUC classes, core/specialization/elective rules, and waiver credit treatment.)*
5. Is there an existing student database (Excel or portal) to import?
6. Domain name for the site (a `fulafia.edu.ng` sub-domain needs the university's approval, otherwise a separate domain on Cloudflare)? Who administers the server and Cloudflare account day to day?
7. Do students need fee-payment checks (Remita/Paystack) before viewing results or registering? (Out of scope unless requested.)
8. Will SMS notifications be needed, or is email enough?
9. Branding: logo, colours, and approved text content for the department?
10. How long is the 400L specialization selection window (e.g. the first two weeks of registration)?
11. Do credits transferred from a previous institution (Transfer students) count toward the 120 credits passed? The design assumes exempted Direct Entry credits do not count (hence 90), and that transferred credits count only if the HoD approves them in the evaluation.
12. What is the minimum number of credits a student must have **registered** before graduating (for Regular, Direct Entry and Transfer)? The passed minimums are set (120/120/90).
13. After a name change is approved, does the university need to be notified outside this system (e.g. a printed letter or export from the portal)?
14. In the Transfer evaluation, may the EO map a previous course to a *core* course (full exemption), or only the HoD?

Change log:
- v0.1: Initial framework from owner's first brief.
- v1.8: The manual promotion step was removed. Students move up automatically when the next session starts; 24-unit semester cap with failed courses first; residency limit at 400 level (two extra sessions) with HoD-recorded Senate extensions.
- v1.9: Academic calendar added (loaded from the official 2026/2027 calendar). It controls registration, result upload by lecturers and the semester switch; HoD-controlled, with reminders before a window closes.
- v1.7: Graduate record corrections (EO/HoD request, HoD approves, original kept, CGPA/class recalculated). Department confirmed: probation below 1.00 at session end and withdrawal if still below at the next session end; TAKE = compulsory course not taken; faculty is "Faculty of Physical Sciences"; the Dean's signature space stays on the broadsheet.
- v1.6: Result output formats from the department's templates (`Result_Templates/`): course "Examination result" sheet PDF and the Senate-format level broadsheet PDF (100 to 400 level), with CA 1 + CA 2 scoring. Assumptions to confirm: probation/withdrawal rule, "TAKE" meaning, faculty name in the heading.
- v1.5: Bulk student import from CSV (check first, confirm, skip bad rows, one-time temporary-password download). Past results are not imported.
- v1.4: Departmental transcript PDF (HoD and EO only, clearly marked not official, from the frozen graduate record or published results). No official transcript, no student PDF, no QR code or reference numbers, by decision.
- v1.3: Transfer credit exemptions done (EO evaluates, HoD approves and locks; registration and graduation honour exemptions). Staff directory now has three categories (Academic, Technical, Administrative) and academic cadres in the required order, with the HoD always following the Distinguished Professor.
- v1.2: Staff accounts done: create/edit/deactivate/reset lecturer accounts (HoD and EO), HoD appoints the single EO, HoD office handover with exactly one HoD at all times, staff password reset by email.
- v1.1: Security hardening done: forced change of temporary passwords and a password policy, account lockout, compulsory TOTP two-factor for admins with recovery codes and HoD reset, Content Security Policy and other headers, no inline scripts, Cloudflare Tunnel client-IP handling, server recovery commands, production checklist (portal/README.md).
- v1.0: Build phase 6 done: public-site CMS (pages, news, events, staff directory, downloads, gallery, settings, contact form, search, course catalogue). Page text is placeholder until the department supplies approved content.
- v0.9: Build phase 5 done: HoD-only deferment (one session maximum, fully-registered check, lapse flagging), marriage name change (EO verifies, HoD approves, permanent name history, private document storage), and student enquiries. Assumptions to confirm: "fully registered" means at least one approved course registration; a session in which the student already has approved registrations cannot be deferred; a returned student cannot defer again.
- v0.8: Build phase 4 done: carry-overs, promotion (EO prepares, HoD approves), graduation eligibility, HoD elective waiver, EO-proposed and HoD-approved graduation batches, permanent graduate register with frozen results, alumni statement and public verification. Assumptions to confirm: promotion threshold (CGPA 1.00), minimum credits registered equals the passed minimum, curriculum for transfer students until exemptions are built.
- v0.7: Build phase 3 done: results engine (score entry, CSV upload, vet/approve/publish workflow, GPA/CGPA, printable slip). Assumptions to confirm: CA 30 + exam 70; absent = F; incomplete/withheld not graded; every attempt counts in CGPA while credits passed count each course once; one lecturer per course.
- v0.6: Build phase 2 done: sessions, specializations, courses, students, registration and Level Adviser approvals (see `portal/README.md`). Student status can only be set to active/suspended/withdrawn by admins; deferment and graduation stay with their own HoD flows.
- v0.5: Level Advisers (HoD-appointed, one per level, 2-year tenure, 2-month expiry reminder to LA and HoD, removable only by the HoD even after expiry, approve course registration). Build started. Local WAMP is supported for development.
- v0.4: Minimum credits passed set (Regular 120, Direct Entry 90, Transfer 120). Specialization chosen at the beginning of 400L. Transfer entry at 100L or 200L, EO evaluates and HoD approves. Deferment limited to one session. Name-change documents fixed (certificate/affidavit + newspaper advert). Matric numbers issued by the university.
- v0.3: Specialization-linked electives (must be registered and passed), HoD-managed areas of specialization (7 initial), failed non-waived electives must be re-registered, waived courses excluded from credits passed, NUC classes confirmed. Added admission modes (Regular, Direct Entry, Transfer), HoD-only deferment, marriage name change for female students, departmental ownership, and hosting on the departmental Linux server via Cloudflare Tunnel (Sections 2, 6, 8.4–8.7, 10.1).
- v0.2: Confirmed assumptions. Fixed grade scale A–F. Added Core/Elective course types, HoD-only elective waiver, configurable minimum credits registered/passed (HoD or EO), and the updated graduation rule (Sections 5, 6, 8.2).
