<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\GraduationBatch;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\PortalMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Class progression is automatic. When the next session starts, every active student moves up one level
 * (failed courses go with them and are registered again at the next registration). A student at 400 level
 * who has not graduated stays, up to the residency limit; after that they are marked withdrawn.
 */
class SessionRolloverService
{
    /** What starting the next session would do, for the confirmation on the Sessions page. */
    public function preview(AcademicSession $current): array
    {
        $students = $this->activeStudents($current);
        $moving = $students->filter(fn (StudentProfile $s) => $s->current_level < 400);
        $at400 = $students->filter(fn (StudentProfile $s) => $s->current_level === 400);
        $leaving = $at400->filter(fn (StudentProfile $s) => $s->sessions_at_400 + 1 >= $s->allowedSessionsAt400());

        return [
            'moving' => $moving->count(),
            'by_level' => $moving->countBy('current_level')->sortKeys()->all(),
            'staying_at_400' => $at400->count() - $leaving->count(),
            'to_withdraw' => $leaving->count(),
            'withdraw_list' => $leaving->map(fn ($s) => $s->user->identifier)->values()->all(),
            'pending_graduation' => GraduationBatch::where('academic_session_id', $current->id)->where('status', 'proposed')->count(),
        ];
    }

    /**
     * Close the current session and start the next one. Returns the summary of the progression.
     *
     * @return array{promoted: int, stayed: int, withdrawn: int}
     */
    public function startNext(string $name, User $by): array
    {
        $current = AcademicSession::current();
        if ($current && strcmp($name, $current->name) <= 0) {
            throw ValidationException::withMessages(['name' => "The next session must come after {$current->name}."]);
        }
        if ($current && GraduationBatch::where('academic_session_id', $current->id)->where('status', 'proposed')->exists()) {
            throw ValidationException::withMessages(['name' => 'A graduation batch for this session is still waiting for the HoD. Decide it first, so nobody who is graduating is moved on or counted as staying.']);
        }

        return DB::transaction(function () use ($name, $current, $by) {
            $summary = ['promoted' => 0, 'stayed' => 0, 'withdrawn' => 0];
            if ($current) {
                $summary = $this->rollover($current, $by);
            }
            $next = AcademicSession::firstOrCreate(['name' => $name]);
            $next->makeCurrent();
            $next->update(['current_semester' => 1, 'specialization_window_open' => false]);
            AuditLog::record('session.started', ['name' => $name] + $summary);

            return $summary;
        });
    }

    /**
     * Move the students of a finished session on. Safe to run once only per session.
     *
     * @return array{promoted: int, stayed: int, withdrawn: int}
     */
    public function rollover(AcademicSession $ended, User $by): array
    {
        if ($ended->rolled_over_at) {
            throw ValidationException::withMessages(['name' => "Students have already been moved on at the end of {$ended->name}."]);
        }

        $summary = ['promoted' => 0, 'stayed' => 0, 'withdrawn' => 0];
        $now = now();

        foreach ($this->activeStudents($ended) as $student) {
            $from = $student->current_level;

            if ($from < 400) {
                $student->update(['current_level' => $from + 100]);
                $this->history($student, $ended, $from, $from + 100, 'promoted', $by);
                $summary['promoted']++;

                continue;
            }

            // Still at 400 level at the end of the session: count it, then see whether residency is used up.
            $student->sessions_at_400++;
            if ($student->sessions_at_400 >= $student->allowedSessionsAt400()) {
                $student->update(['sessions_at_400' => $student->sessions_at_400, 'status' => 'withdrawn', 'withdrawal_reason' => 'residency_exceeded']);
                $this->history($student, $ended, 400, 400, 'withdrawn', $by);
                $student->user->notify(new PortalMessage('Residency ended', 'You have used all the sessions allowed at 400 level without meeting the graduation requirements, so you have been marked withdrawn. You may appeal to the Senate through the department.'));
                User::where('role', User::ROLE_PRINCIPAL_ADMIN)->where('is_active', true)->get()->each(fn ($hod) => $hod->notify(new PortalMessage(
                    'Student withdrawn: residency', "{$student->user->name} ({$student->user->identifier}) used all allowed sessions at 400 level."
                )));
                $summary['withdrawn']++;
            } else {
                $student->update(['sessions_at_400' => $student->sessions_at_400]);
                $this->history($student, $ended, 400, 400, 'stayed', $by);
                $summary['stayed']++;
            }
        }

        $ended->update(['rolled_over_at' => $now]);
        AuditLog::record('session.rolled_over', ['name' => $ended->name] + $summary);

        return $summary;
    }

    /**
     * Active students who were part of the session. Someone admitted for a later session (added ahead of time)
     * is not moved on at the end of this one.
     *
     * @return \Illuminate\Support\Collection<int, StudentProfile>
     */
    private function activeStudents(AcademicSession $session)
    {
        return StudentProfile::with(['user', 'entrySession'])->where('status', 'active')->get()
            ->reject(fn (StudentProfile $s) => $s->entrySession && strcmp($s->entrySession->name, $session->name) > 0);
    }

    private function history(StudentProfile $student, AcademicSession $session, int $from, int $to, string $outcome, User $by): void
    {
        DB::table('student_level_history')->insert([
            'student_profile_id' => $student->id, 'academic_session_id' => $session->id, 'from_level' => $from, 'to_level' => $to,
            'outcome' => $outcome, 'decided_by' => $by->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
