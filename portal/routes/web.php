<?php

use App\Http\Controllers\Admin\AdminAppointmentController;
use App\Http\Controllers\Admin\Content\DownloadController;
use App\Http\Controllers\Admin\Content\EventController;
use App\Http\Controllers\Admin\Content\GalleryController;
use App\Http\Controllers\Admin\Content\PageController;
use App\Http\Controllers\Admin\Content\PostController;
use App\Http\Controllers\Admin\Content\SiteSettingController;
use App\Http\Controllers\Admin\Content\StaffMemberController;
use App\Http\Controllers\StaffProfileController;
use App\Http\Controllers\Admin\BroadsheetController;
use App\Http\Controllers\Admin\CourseController;
use App\Http\Controllers\Admin\DefermentController;
use App\Http\Controllers\Admin\EnquiryController;
use App\Http\Controllers\Admin\NameChangeController;
use App\Http\Controllers\Admin\GraduateController;
use App\Http\Controllers\Admin\GraduateCorrectionController;
use App\Http\Controllers\Admin\GraduationController;
use App\Http\Controllers\Admin\LevelAdviserController;
use App\Http\Controllers\Admin\CalendarController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\StaffAccountController;
use App\Http\Controllers\Admin\StudentImportController;
use App\Http\Controllers\Admin\TransferController;
use App\Http\Controllers\Admin\TranscriptController;
use App\Http\Controllers\Admin\SpecializationController;
use App\Http\Controllers\Admin\StudentController;
use App\Http\Controllers\AdviserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Admin\AccountSecurityController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\StudentPortalController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ResultController;
use App\Http\Controllers\PortalController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// Public site.
Route::get('/', [SiteController::class, 'home'])->name('home');
Route::get('/p/{slug}', [SiteController::class, 'page'])->name('page');
Route::get('/news', [SiteController::class, 'news'])->name('news');
Route::get('/news/{slug}', [SiteController::class, 'post'])->name('post');
Route::get('/events', [SiteController::class, 'events'])->name('events');
Route::get('/academic-calendar', [SiteController::class, 'calendar'])->name('calendar');
Route::get('/staff-directory', [SiteController::class, 'staff'])->name('staff.directory');
Route::get('/staff-directory/{member}', [SiteController::class, 'staffMember'])->name('staff.member');
Route::get('/courses', [SiteController::class, 'courses'])->name('courses');
Route::get('/gallery', [SiteController::class, 'gallery'])->name('gallery');
Route::get('/downloads', [SiteController::class, 'downloads'])->name('downloads');
Route::get('/downloads/{download}/get', [SiteController::class, 'download'])->name('downloads.get');
Route::get('/media/{media}', [SiteController::class, 'media'])->name('media');
Route::get('/search', [SiteController::class, 'search'])->middleware('throttle:30,1')->name('search');
Route::get('/contact', [SiteController::class, 'contact'])->name('contact');
Route::post('/contact', [SiteController::class, 'sendContact'])->middleware('throttle:5,1')->name('contact.send');
Route::get('/verify', [\App\Http\Controllers\VerificationController::class, 'form'])->middleware('throttle:20,1')->name('verify');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'form'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1')->name('password.update');
    Route::get('/two-factor-challenge', [AuthController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [AuthController::class, 'verifyChallenge'])->middleware('throttle:20,1');
});

Route::middleware(['auth', 'secure.account'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Own account security (always reachable, even with a temporary password or before two-factor is set up).
    Route::prefix('security')->name('security.')->group(function () {
        Route::get('/password', [SecurityController::class, 'password'])->name('password');
        Route::post('/password', [SecurityController::class, 'updatePassword'])->name('password.update');
        Route::get('/two-factor', [SecurityController::class, 'twoFactor'])->name('two-factor');
        Route::post('/two-factor/confirm', [SecurityController::class, 'confirmTwoFactor'])->middleware('throttle:10,1')->name('two-factor.confirm');
        Route::post('/two-factor/recovery-codes', [SecurityController::class, 'regenerateRecoveryCodes'])->name('two-factor.codes');
        Route::post('/two-factor/disable', [SecurityController::class, 'disableTwoFactor'])->name('two-factor.disable');
    });
    Route::get('/dashboard', [PortalController::class, 'dashboard'])->name('dashboard');
    Route::post('/notifications/read', [NotificationController::class, 'markAllRead'])->name('notifications.read');

    Route::middleware('role:'.User::ROLE_STUDENT.','.User::ROLE_ALUMNI)->group(function () {
        Route::get('/student', [StudentPortalController::class, 'dashboard'])->name('student.dashboard');
        Route::get('/student/statement', [StudentPortalController::class, 'statement'])->name('student.statement');
    });
    Route::middleware('role:'.User::ROLE_STUDENT)->prefix('student')->name('student.')->group(function () {
        Route::get('/registration', [StudentPortalController::class, 'registration'])->name('registration');
        Route::post('/registration', [StudentPortalController::class, 'submitRegistration'])->name('registration.submit');
        Route::post('/specialization', [StudentPortalController::class, 'chooseSpecialization'])->name('specialization');
        Route::get('/name-change', [StudentPortalController::class, 'nameChange'])->name('name-change');
        Route::post('/name-change', [StudentPortalController::class, 'submitNameChange'])->name('name-change.submit');
        Route::get('/enquiries', [StudentPortalController::class, 'enquiries'])->name('enquiries');
        Route::post('/enquiries', [StudentPortalController::class, 'submitEnquiry'])->name('enquiries.submit');
        Route::get('/results', [StudentPortalController::class, 'results'])->name('results');
        Route::get('/results/print', [StudentPortalController::class, 'printResults'])->name('results.print');
    });

    // Result sheets: assigned lecturers, EO, HoD and admins with manage_results (checked in the controller).
    Route::middleware('role:'.User::ROLE_LECTURER.','.implode(',', User::ADMIN_ROLES))
        ->prefix('results')->name('results.')->group(function () {
            Route::get('/', [ResultController::class, 'index'])->name('index');
            Route::post('/courses/{course}/assign', [ResultController::class, 'assign'])->name('assign');
            Route::post('/courses/{course}/open', [ResultController::class, 'open'])->name('open');
            Route::get('/{offering}', [ResultController::class, 'show'])->name('show');
            Route::get('/{offering}/sheet', [ResultController::class, 'sheet'])->name('sheet');
            Route::post('/{offering}/scores', [ResultController::class, 'save'])->name('save');
            Route::post('/{offering}/upload', [ResultController::class, 'upload'])->name('upload');
            Route::post('/{offering}/submit', [ResultController::class, 'submit'])->name('submit');
            Route::post('/{offering}/vet', [ResultController::class, 'vet'])->name('vet');
            Route::post('/{offering}/return', [ResultController::class, 'returnSheet'])->name('return');
            Route::post('/{offering}/approve', [ResultController::class, 'approve'])->name('approve');
            Route::post('/{offering}/publish', [ResultController::class, 'publish'])->name('publish');
            Route::post('/{offering}/reopen', [ResultController::class, 'reopen'])->name('reopen');
        });

    Route::middleware('role:'.User::ROLE_LECTURER)->group(function () {
        Route::get('/staff', [PortalController::class, 'staff'])->name('staff.dashboard');
        Route::get('/staff/profile', [StaffProfileController::class, 'edit'])->name('staff.profile');
        Route::post('/staff/profile', [StaffProfileController::class, 'update'])->name('staff.profile.update');

        // Level Adviser tools (the controller also checks the person holds an appointment).
        Route::prefix('adviser')->name('adviser.')->group(function () {
            Route::get('/students', [AdviserController::class, 'index'])->name('students');
            Route::get('/students/{student}', [AdviserController::class, 'show'])->name('students.show');
            Route::post('/students/{student}/approve-all', [AdviserController::class, 'approveAll'])->name('students.approve-all');
            Route::post('/registrations/{registration}', [AdviserController::class, 'review'])->name('registrations.review');
        });
    });

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::middleware('role:'.implode(',', User::ADMIN_ROLES))->get('/', [PortalController::class, 'admin'])->name('dashboard');

        Route::middleware('permission:manage_results')->group(function () {
            Route::get('/broadsheets', [BroadsheetController::class, 'index'])->name('broadsheets');
            Route::get('/broadsheets/pdf', [BroadsheetController::class, 'pdf'])->name('broadsheets.pdf');
        });
        Route::middleware('permission:manage_courses')->group(function () {
            Route::resource('courses', CourseController::class)->except(['show', 'destroy']);
        });
        Route::middleware('permission:manage_students')->group(function () {
            // Bulk import (these paths must come before the students/{student} resource routes).
            Route::get('/students/import', [StudentImportController::class, 'index'])->name('students.import');
            Route::get('/students/import/template', [StudentImportController::class, 'template'])->name('students.import.template');
            Route::post('/students/import', [StudentImportController::class, 'upload'])->middleware('throttle:20,1')->name('students.import.upload');
            Route::get('/students/import/{import}', [StudentImportController::class, 'show'])->name('students.import.show');
            Route::post('/students/import/{import}/confirm', [StudentImportController::class, 'confirm'])->name('students.import.confirm');
            Route::get('/students/import/{import}/credentials', [StudentImportController::class, 'credentials'])->name('students.import.credentials');
            Route::get('/students/import/{import}/errors', [StudentImportController::class, 'errors'])->name('students.import.errors');
            Route::post('/students/{student}/reset-password', [AccountSecurityController::class, 'resetStudentPassword'])->name('students.reset-password');
            Route::resource('students', StudentController::class)->except(['destroy']);
        });
        // Public-site content: HoD, EO, and appointed admins with manage_content.
        Route::middleware('permission:manage_content')->group(function () {
            Route::get('/content', [SiteSettingController::class, 'index'])->name('content');
            Route::post('/content/settings', [SiteSettingController::class, 'update'])->name('content.settings');
            Route::get('/content/messages', [SiteSettingController::class, 'messages'])->name('messages');
            Route::post('/content/messages/{message}/read', [SiteSettingController::class, 'readMessage'])->name('messages.read');
            Route::delete('/content/messages/{message}', [SiteSettingController::class, 'deleteMessage'])->name('messages.delete');

            Route::get('/content/pages', [PageController::class, 'index'])->name('pages.index');
            Route::post('/content/pages', [PageController::class, 'store'])->name('pages.store');
            Route::put('/content/pages/{page}', [PageController::class, 'update'])->name('pages.update');
            Route::delete('/content/pages/{page}', [PageController::class, 'destroy'])->name('pages.destroy');

            Route::get('/content/posts', [PostController::class, 'index'])->name('posts.index');
            Route::post('/content/posts', [PostController::class, 'store'])->name('posts.store');
            Route::put('/content/posts/{post}', [PostController::class, 'update'])->name('posts.update');
            Route::delete('/content/posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');

            Route::get('/content/events', [EventController::class, 'index'])->name('events.index');
            Route::post('/content/events', [EventController::class, 'store'])->name('events.store');
            Route::put('/content/events/{event}', [EventController::class, 'update'])->name('events.update');
            Route::delete('/content/events/{event}', [EventController::class, 'destroy'])->name('events.destroy');

            Route::get('/content/staff', [StaffMemberController::class, 'index'])->name('staff.index');
            Route::post('/content/staff', [StaffMemberController::class, 'store'])->name('staff.store');
            Route::put('/content/staff/{member}', [StaffMemberController::class, 'update'])->name('staff.update');
            Route::delete('/content/staff/{member}', [StaffMemberController::class, 'destroy'])->name('staff.destroy');

            Route::get('/content/downloads', [DownloadController::class, 'index'])->name('downloads.index');
            Route::post('/content/downloads', [DownloadController::class, 'store'])->name('downloads.store');
            Route::put('/content/downloads/{download}', [DownloadController::class, 'update'])->name('downloads.update');
            Route::delete('/content/downloads/{download}', [DownloadController::class, 'destroy'])->name('downloads.destroy');

            Route::get('/content/gallery', [GalleryController::class, 'index'])->name('gallery.index');
            Route::post('/content/gallery', [GalleryController::class, 'store'])->name('gallery.store');
            Route::put('/content/gallery/{item}', [GalleryController::class, 'update'])->name('gallery.update');
            Route::delete('/content/gallery/{item}', [GalleryController::class, 'destroy'])->name('gallery.destroy');
        });

        // Sessions: HoD and EO.
        Route::middleware('role:'.User::ROLE_PRINCIPAL_ADMIN.','.User::ROLE_SECONDARY_ADMIN)->group(function () {
            Route::get('/sessions', [SessionController::class, 'index'])->name('sessions');
            Route::post('/sessions', [SessionController::class, 'store'])->name('sessions.store');
            Route::post('/sessions/start-next', [SessionController::class, 'startNext'])->name('sessions.start-next');
            Route::post('/sessions/{session}', [SessionController::class, 'update'])->name('sessions.update');

            // The academic calendar: both can read it, only the HoD changes it (checked in the controller).
            Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');
            Route::post('/calendar/{session}/load-official', [CalendarController::class, 'loadOfficial'])->name('calendar.load');
            Route::post('/calendar/{session}/copy', [CalendarController::class, 'copy'])->name('calendar.copy');
            Route::post('/calendar/{session}/events', [CalendarController::class, 'store'])->name('calendar.store');
            Route::put('/calendar/events/{event}', [CalendarController::class, 'update'])->name('calendar.update');
            Route::post('/calendar/events/{event}/override', [CalendarController::class, 'override'])->name('calendar.override');
            Route::delete('/calendar/events/{event}', [CalendarController::class, 'destroy'])->name('calendar.destroy');
        });

        // Graduation, corrections, deferments, transfers, staff accounts and more: HoD and EO (approvals are HoD-only inside the controllers).
        // The class-progression step needs no screen: students move up automatically when the next session starts.
        Route::middleware('role:'.User::ROLE_PRINCIPAL_ADMIN.','.User::ROLE_SECONDARY_ADMIN)->group(function () {

            Route::get('/graduation', [GraduationController::class, 'index'])->name('graduation.index');
            Route::get('/graduation/students/{student}', [GraduationController::class, 'student'])->name('graduation.student');
            Route::post('/graduation/students/{student}/waive/{course}', [GraduationController::class, 'waive'])->name('graduation.waive');
            Route::post('/graduation/waivers/{waiver}/revoke', [GraduationController::class, 'revokeWaiver'])->name('graduation.waiver.revoke');
            Route::post('/graduation/propose', [GraduationController::class, 'propose'])->name('graduation.propose');
            Route::post('/graduation/batches/{batch}/approve', [GraduationController::class, 'approve'])->name('graduation.approve');
            Route::post('/graduation/batches/{batch}/reject', [GraduationController::class, 'reject'])->name('graduation.reject');
            Route::post('/graduation/requirements', [GraduationController::class, 'updateRequirements'])->name('graduation.requirements');

            Route::get('/transcripts/students/{student}', [TranscriptController::class, 'student'])->name('transcripts.student');
            Route::get('/transcripts/graduates/{graduate}', [TranscriptController::class, 'graduate'])->name('transcripts.graduate');

            Route::get('/transfers', [TransferController::class, 'index'])->name('transfers.index');
            Route::get('/transfers/{student}', [TransferController::class, 'show'])->name('transfers.show');
            Route::put('/transfers/{student}', [TransferController::class, 'update'])->name('transfers.update');
            Route::post('/transfers/{student}/exemptions', [TransferController::class, 'addExemption'])->name('transfers.exemptions.add');
            Route::delete('/transfers/{student}/exemptions/{exemption}', [TransferController::class, 'removeExemption'])->name('transfers.exemptions.remove');
            Route::post('/transfers/{student}/documents', [TransferController::class, 'upload'])->name('transfers.documents.upload');
            Route::delete('/transfers/{student}/documents/{document}', [TransferController::class, 'deleteDocument'])->name('transfers.documents.delete');
            Route::get('/transfers/{student}/documents/{document}', [TransferController::class, 'document'])->name('transfers.documents.show');
            Route::post('/transfers/{student}/submit', [TransferController::class, 'submit'])->name('transfers.submit');
            Route::post('/transfers/{student}/approve', [TransferController::class, 'approve'])->name('transfers.approve');
            Route::post('/transfers/{student}/return', [TransferController::class, 'returnToEo'])->name('transfers.return');

            Route::get('/accounts', [StaffAccountController::class, 'index'])->name('accounts.index');
            Route::post('/accounts', [StaffAccountController::class, 'store'])->name('accounts.store');
            Route::post('/accounts/appoint-eo', [StaffAccountController::class, 'appointEo'])->name('accounts.appoint-eo');
            Route::post('/accounts/handover', [StaffAccountController::class, 'handover'])->name('accounts.handover');
            Route::put('/accounts/{user}', [StaffAccountController::class, 'update'])->name('accounts.update');
            Route::post('/accounts/{user}/deactivate', [StaffAccountController::class, 'deactivate'])->name('accounts.deactivate');
            Route::post('/accounts/{user}/reactivate', [StaffAccountController::class, 'reactivate'])->name('accounts.reactivate');
            Route::post('/accounts/{user}/reset-password', [StaffAccountController::class, 'resetPassword'])->name('accounts.reset-password');

            Route::get('/deferments', [DefermentController::class, 'index'])->name('deferments.index');
            Route::post('/deferments', [DefermentController::class, 'store'])->name('deferments.store');
            Route::post('/deferments/{deferment}/return', [DefermentController::class, 'returnStudent'])->name('deferments.return');
            Route::post('/deferments/{deferment}/withdraw', [DefermentController::class, 'withdraw'])->name('deferments.withdraw');

            Route::get('/name-changes', [NameChangeController::class, 'index'])->name('name-changes.index');
            Route::get('/name-changes/{nameChange}', [NameChangeController::class, 'show'])->name('name-changes.show');
            Route::get('/name-changes/{nameChange}/document/{type}', [NameChangeController::class, 'document'])->name('name-changes.document');
            Route::post('/name-changes/{nameChange}/verify', [NameChangeController::class, 'verify'])->name('name-changes.verify');
            Route::post('/name-changes/{nameChange}/approve', [NameChangeController::class, 'approve'])->name('name-changes.approve');
            Route::post('/name-changes/{nameChange}/reject', [NameChangeController::class, 'reject'])->name('name-changes.reject');

            Route::get('/enquiries', [EnquiryController::class, 'index'])->name('enquiries.index');
            Route::post('/enquiries/{enquiry}/respond', [EnquiryController::class, 'respond'])->name('enquiries.respond');

            Route::get('/corrections', [GraduateCorrectionController::class, 'index'])->name('corrections.index');
            Route::post('/graduates/{graduate}/corrections', [GraduateCorrectionController::class, 'store'])->name('corrections.store');
            Route::post('/corrections/{amendment}/approve', [GraduateCorrectionController::class, 'approve'])->name('corrections.approve');
            Route::post('/corrections/{amendment}/reject', [GraduateCorrectionController::class, 'reject'])->name('corrections.reject');

            Route::get('/graduates', [GraduateController::class, 'index'])->name('graduates.index');
            Route::get('/graduates/{graduate}', [GraduateController::class, 'show'])->name('graduates.show');
        });

        // HoD only.
        Route::middleware('role:'.User::ROLE_PRINCIPAL_ADMIN)->group(function () {
            Route::get('/specializations', [SpecializationController::class, 'index'])->name('specializations');
            Route::post('/specializations', [SpecializationController::class, 'store'])->name('specializations.store');
            Route::post('/specializations/{specialization}', [SpecializationController::class, 'update'])->name('specializations.update');

            Route::get('/admins', [AdminAppointmentController::class, 'index'])->name('admins');
            Route::post('/admins', [AdminAppointmentController::class, 'store'])->name('admins.store');
            Route::delete('/admins/{user}', [AdminAppointmentController::class, 'destroy'])->name('admins.destroy');
            Route::post('/students/{student}/residency-extension', [\App\Http\Controllers\Admin\ResidencyController::class, 'store'])->name('students.residency');
            Route::post('/users/{user}/reset-two-factor', [AccountSecurityController::class, 'resetTwoFactor'])->name('users.reset-two-factor');

            Route::get('/level-advisers', [LevelAdviserController::class, 'index'])->name('advisers');
            Route::post('/level-advisers', [LevelAdviserController::class, 'store'])->name('advisers.store');
            Route::post('/level-advisers/{appointment}/renew', [LevelAdviserController::class, 'renew'])->name('advisers.renew');
            Route::delete('/level-advisers/{appointment}', [LevelAdviserController::class, 'destroy'])->name('advisers.destroy');
        });
    });
});
