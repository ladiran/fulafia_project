<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CourseRegistration;
use App\Models\LevelAdviserAppointment;
use App\Models\StudentProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A Level Adviser sees every student at their level and reviews their course registration.
 * Expiry does not remove the post, so an expired adviser keeps access until the HoD removes them.
 */
class AdviserController extends Controller
{
    public function index()
    {
        $appointment = $this->appointment();

        $students = StudentProfile::with('user')
            ->where('current_level', $appointment->level)
            ->join('users', 'users.id', '=', 'student_profiles.user_id')
            ->orderBy('users.name')
            ->select('student_profiles.*')
            ->withCount(['registrations as pending_count' => fn ($q) => $q->where('status', CourseRegistration::PENDING)])
            ->get();

        return view('adviser.students', compact('appointment', 'students'));
    }

    public function show(StudentProfile $student)
    {
        $appointment = $this->appointment();
        $this->authorizeStudent($appointment, $student);

        $student->load(['user', 'specialization', 'entrySession', 'registrations.course', 'registrations.session']);

        return view('adviser.student', compact('appointment', 'student'));
    }

    public function review(Request $request, CourseRegistration $registration): RedirectResponse
    {
        $appointment = $this->appointment();
        $this->authorizeStudent($appointment, $registration->student);

        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $registration->update([
            'status' => $data['decision'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'note' => $data['note'] ?? null,
        ]);
        AuditLog::record('registration.'.$data['decision'], ['registration_id' => $registration->id]);

        return back()->with('status', 'Course '.$registration->course->code.' '.$data['decision'].'.');
    }

    public function approveAll(Request $request, StudentProfile $student): RedirectResponse
    {
        $appointment = $this->appointment();
        $this->authorizeStudent($appointment, $student);

        $count = $student->registrations()->where('status', CourseRegistration::PENDING)->update([
            'status' => CourseRegistration::APPROVED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);
        AuditLog::record('registration.approved_all', ['student_profile_id' => $student->id, 'count' => $count]);

        return back()->with('status', "{$count} course(s) approved.");
    }

    private function appointment(): LevelAdviserAppointment
    {
        return auth()->user()->currentAdviserAppointment() ?? abort(403, 'You are not a Level Adviser.');
    }

    private function authorizeStudent(LevelAdviserAppointment $appointment, StudentProfile $student): void
    {
        abort_unless($student->current_level === $appointment->level, 403, 'This student is not at your level.');
    }
}
