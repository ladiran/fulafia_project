<?php

return [
    'department' => 'Department of Physics',
    'faculty' => 'Faculty of Physical Sciences',
    'university' => 'Federal University of Lafia',
    'address' => 'PMB 146, Makurdi Road, Gandu, Lafia, Nasarawa State, Nigeria',
    'phone' => '+234 811 299 0109',

    // Public-site defaults, overridable in Admin > Site content > Settings.
    'site' => [
        'hero_title' => 'Department of Physics',
        'hero_text' => 'Faculty of Physical Sciences, Federal University of Lafia. Committed to excellence in teaching, research and service, producing graduates with strong theoretical knowledge and practical skills.',
        'address' => 'PMB 146, Makurdi Road, Gandu, Lafia, Nasarawa State, Nigeria',
        'phone' => '+234 811 299 0109',
        'email' => '',
        'office_hours' => 'Monday to Friday, 9:00 AM to 4:00 PM',
    ],

    // Permissions the HoD can grant to an appointed admin.
    'admin_permissions' => [
        'manage_courses' => 'Manage courses',
        'manage_students' => 'Manage students',
        'manage_results' => 'Upload / vet results',
        'manage_content' => 'Publish news and pages',
        'manage_admissions' => 'Manage admissions',
    ],

    // Score composition (continuous assessment + exam = 100) and pass mark.
    'ca_max' => 30,
    'exam_max' => 70,
    'pass_mark' => 40,

    // Minimum total score => [grade, grade point], highest first.
    'grading' => [
        [70, 'A', 5],
        [60, 'B', 4],
        [50, 'C', 3],
        [45, 'D', 2],
        [40, 'E', 1],
        [0, 'F', 0],
    ],

    // Most credit units a student may register in one semester (carry-over courses come first).
    'max_units_per_semester' => 24,

    // Residency at 400 level: one normal session plus this many extra sessions. Senate-approved extensions
    // recorded by the HoD are added to it. After that the student is marked withdrawn.
    'extra_sessions_at_400' => 2,

    // Senate broadsheet remarks. At the end of a session (second semester) a cumulative GPA below this is
    // "probation"; below it again after a probation session the student is shown as "to withdraw".
    'probation_cgpa' => 1.00,

    // A student may defer at most this many sessions in total.
    'max_deferred_sessions' => 1,

    // Degree awarded and NUC classes of degree (minimum CGPA => class), highest first.
    'degree' => 'B.Sc. Physics',
    'degree_classes' => [
        [4.50, 'First Class'],
        [3.50, 'Second Class Upper'],
        [2.40, 'Second Class Lower'],
        [1.50, 'Third Class'],
        [1.00, 'Pass'],
    ],

    // Security. Keep both true in production.
    'require_admin_2fa' => env('REQUIRE_ADMIN_2FA', true),
    // Only when the site is published through a Cloudflare Tunnel (cloudflared runs on this server).
    'behind_cloudflare' => env('BEHIND_CLOUDFLARE', false),
    'login_max_attempts' => 5,
    'login_lockout_seconds' => 900,

    // Password given to seeded demo accounts. Override in .env for anything shared.
    'seed_password' => env('SEED_PASSWORD', 'ChangeMe-Now-123'),
];
