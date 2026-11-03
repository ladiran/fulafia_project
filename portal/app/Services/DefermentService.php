<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\CourseRegistration;
use App\Models\Deferment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\PortalMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Deferment of admission for one session. Only the HoD initiates; the EO or HoD reactivates. */
class DefermentService
{
    /** Why this student cannot be deferred, or null if they can. */
    public function blockedReason(StudentProfile $student, ?AcademicSession $session = null): ?string
    {
        if (! $student->isActive()) {
            return "The student's status is \"{$student->status}\", so a deferment cannot be started.";
        }

        // "Fully registered": admitted and registered for courses (approved by the Level Adviser) at least once.
        if (! $student->registrations()->where('status', CourseRegistration::APPROVED)->exists()) {
            return 'The student must have been fully registered (approved course registration) before they qualify for deferment.';
        }

        $max = config('portal.max_deferred_sessions');
        if ($student->deferments()->whereIn('status', ['active', 'lapsed', 'returned'])->count() >= $max) {
            return "A student can defer at most {$max} session(s) in total, and this limit has been reached.";
        }

        if ($session && $student->registrations()->where('academic_session_id', $session->id)->where('status', CourseRegistration::APPROVED)->exists()) {
            return "The student already has approved registrations in {$session->name}, so that session cannot be deferred.";
        }

        return null;
    }

    public function defer(StudentProfile $student, AcademicSession $session, string $reason, User $hod): Deferment
    {
        $current = AcademicSession::current();
        if ($current && $session->name < $current->name) {
            throw ValidationException::withMessages(['academic_session_id' => 'Choose the current session or a later one.']);
        }
        if ($message = $this->blockedReason($student, $session)) {
            throw ValidationException::withMessages(['matric' => $message]);
        }

        return DB::transaction(function () use ($student, $session, $reason, $hod) {
            $deferment = Deferment::create([
                'student_profile_id' => $student->id, 'academic_session_id' => $session->id,
                'reason' => $reason, 'initiated_by' => $hod->id, 'status' => 'active',
            ]);
            $student->update(['status' => 'deferred']);

            // Cancel anything half-done for the deferred session.
            $student->registrations()->where('academic_session_id', $session->id)
                ->whereIn('status', [CourseRegistration::PENDING])
                ->update(['status' => CourseRegistration::REJECTED, 'note' => 'Deferred', 'reviewed_by' => $hod->id, 'reviewed_at' => now()]);

            AuditLog::record('deferment.started', ['student_profile_id' => $student->id, 'session' => $session->name, 'reason' => $reason]);
            $student->user->notify(new PortalMessage('Deferment approved', "Your admission has been deferred for the {$session->name} session. Contact the department when you are ready to resume."));

            return $deferment;
        });
    }

    /** Student resumes at the same level. EO or HoD. */
    public function returnStudent(Deferment $deferment, User $by): void
    {
        if (! $deferment->isOpen()) {
            throw ValidationException::withMessages(['deferment' => 'This deferment is already closed.']);
        }

        DB::transaction(function () use ($deferment, $by) {
            $deferment->update(['status' => 'returned', 'resolved_at' => now(), 'resolved_by' => $by->id]);
            $deferment->student->update(['status' => 'active']);
            AuditLog::record('deferment.returned', ['deferment_id' => $deferment->id]);
        });
        $deferment->student->user->notify(new PortalMessage('Welcome back', 'Your account is active again. You can register your courses.'));
    }

    /** HoD only, and only after the deferment has lapsed without the student returning. */
    public function withdraw(Deferment $deferment, User $hod): void
    {
        if ($deferment->status !== 'lapsed') {
            throw ValidationException::withMessages(['deferment' => 'Only a lapsed deferment can be closed by withdrawal.']);
        }

        DB::transaction(function () use ($deferment, $hod) {
            $deferment->update(['status' => 'withdrawn', 'resolved_at' => now(), 'resolved_by' => $hod->id]);
            $deferment->student->update(['status' => 'withdrawn']);
            AuditLog::record('deferment.withdrawn', ['deferment_id' => $deferment->id]);
        });
    }

    /** Flag deferments whose session has ended without the student returning. Returns how many were flagged. */
    public function flagLapsed(): int
    {
        $current = AcademicSession::current();
        if (! $current) {
            return 0;
        }

        $flagged = 0;
        Deferment::where('status', 'active')->with(['session', 'student.user'])->get()
            ->filter(fn (Deferment $d) => $d->session->name < $current->name)
            ->each(function (Deferment $d) use (&$flagged) {
                $d->update(['status' => 'lapsed']);
                User::where('role', User::ROLE_PRINCIPAL_ADMIN)->where('is_active', true)->get()->each(fn ($hod) => $hod->notify(new PortalMessage(
                    'Deferment lapsed',
                    "{$d->student->user->name} ({$d->student->user->identifier}) has not resumed after deferring {$d->session->name}. Return or withdraw the student."
                )));
                $flagged++;
            });

        return $flagged;
    }
}
