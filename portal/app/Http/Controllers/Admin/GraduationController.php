<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseWaiver;
use App\Models\GraduationBatch;
use App\Models\GraduationRequirement;
use App\Models\StudentProfile;
use App\Services\GraduationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** The EO checks eligibility and proposes batches; the HoD waives electives and approves graduation. */
class GraduationController extends Controller
{
    public function __construct(private GraduationService $graduation) {}

    public function index()
    {
        $students = StudentProfile::with('user')->where('status', 'active')->where('current_level', 400)->get()
            ->sortBy(fn ($s) => $s->user->name)
            ->map(fn ($s) => ['student' => $s, 'check' => $this->graduation->evaluate($s)]);

        return view('admin.graduation.index', [
            'rows' => $students,
            'batches' => GraduationBatch::with(['session', 'students.user'])->latest()->limit(10)->get(),
            'requirements' => collect(StudentProfile::ENTRY_MODES)->map(fn ($label, $mode) => GraduationRequirement::forMode($mode)),
            'session' => AcademicSession::current(),
            'isHod' => auth()->user()->isPrincipalAdmin(),
        ]);
    }

    public function student(StudentProfile $student)
    {
        $student->load(['user', 'specialization']);

        return view('admin.graduation.student', [
            'student' => $student,
            'check' => $this->graduation->evaluate($student),
            'isHod' => auth()->user()->isPrincipalAdmin(),
            'activeWaivers' => CourseWaiver::where('student_profile_id', $student->id)->active()->with('course')->get(),
        ]);
    }

    public function waive(Request $request, StudentProfile $student, Course $course): RedirectResponse
    {
        abort_unless($request->user()->isPrincipalAdmin(), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        // Only a registered, unpassed, plain elective can be waived: never core or area-linked courses.
        $check = $this->graduation->evaluate($student);
        abort_unless($check['waivable']->contains('id', $course->id), 422, 'This course cannot be waived.');

        CourseWaiver::create([
            'student_profile_id' => $student->id, 'course_id' => $course->id,
            'reason' => $data['reason'], 'waived_by' => $request->user()->id,
        ]);
        AuditLog::record('elective.waived', ['student_profile_id' => $student->id, 'course' => $course->code, 'reason' => $data['reason']]);

        return back()->with('status', "{$course->code} waived.");
    }

    public function revokeWaiver(Request $request, CourseWaiver $waiver): RedirectResponse
    {
        abort_unless($request->user()->isPrincipalAdmin(), 403);
        abort_if($waiver->revoked_at, 404);
        abort_if($waiver->student_profile_id && StudentProfile::find($waiver->student_profile_id)->status === 'graduated', 422, 'Graduated records are frozen.');

        $waiver->update(['revoked_at' => now(), 'revoked_by' => $request->user()->id]);
        AuditLog::record('elective.waiver_revoked', ['waiver_id' => $waiver->id]);

        return back()->with('status', 'Waiver revoked.');
    }

    public function propose(Request $request): RedirectResponse
    {
        $data = $request->validate(['students' => ['required', 'array', 'min:1'], 'students.*' => ['exists:student_profiles,id']],
            ['students.required' => 'Select at least one student.']);
        $session = AcademicSession::current() ?? throw ValidationException::withMessages(['students' => 'No current session.']);

        $selected = StudentProfile::with('user')->whereIn('id', $data['students'])->get();
        $ineligible = $selected->reject(fn ($s) => $this->graduation->evaluate($s)['eligible']);
        if ($ineligible->isNotEmpty()) {
            throw ValidationException::withMessages(['students' => 'Not eligible: '.$ineligible->map(fn ($s) => $s->user->identifier)->implode(', ').'.']);
        }

        $batch = GraduationBatch::create(['academic_session_id' => $session->id, 'status' => 'proposed', 'proposed_by' => $request->user()->id]);
        $batch->students()->attach($selected->pluck('id'));
        AuditLog::record('graduation.proposed', ['batch_id' => $batch->id, 'students' => $selected->count()]);

        return back()->with('status', 'Batch proposed. The HoD can now approve it.');
    }

    public function approve(Request $request, GraduationBatch $batch): RedirectResponse
    {
        abort_unless($request->user()->isPrincipalAdmin(), 403);
        $this->graduation->graduate($batch, $request->user());

        return back()->with('status', 'Graduation approved. Permanent records created and accounts moved to alumni.');
    }

    public function reject(Request $request, GraduationBatch $batch): RedirectResponse
    {
        abort_unless($request->user()->isPrincipalAdmin(), 403);
        abort_unless($batch->status === 'proposed', 422);
        $data = $request->validate(['note' => ['required', 'string', 'max:200']]);

        $batch->update(['status' => 'rejected', 'note' => $data['note']]);
        AuditLog::record('graduation.rejected', ['batch_id' => $batch->id]);

        return back()->with('status', 'Batch rejected.');
    }

    public function updateRequirements(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'req' => ['required', 'array'],
            'req.*.registered' => ['required', 'integer', 'min:0', 'max:300'],
            'req.*.passed' => ['required', 'integer', 'min:0', 'max:300'],
        ]);

        foreach ($data['req'] as $mode => $values) {
            if (! array_key_exists($mode, StudentProfile::ENTRY_MODES)) {
                continue;
            }
            GraduationRequirement::updateOrCreate(['entry_mode' => $mode], [
                'min_credits_registered' => $values['registered'], 'min_credits_passed' => $values['passed'], 'set_by' => $request->user()->id,
            ]);
        }
        AuditLog::record('graduation.requirements_updated', $data['req']);

        return back()->with('status', 'Graduation credit requirements saved.');
    }
}
