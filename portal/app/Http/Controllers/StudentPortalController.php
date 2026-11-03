<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\Specialization;
use App\Models\Enquiry;
use App\Models\NameChangeRequest;
use App\Services\NameChangeService;
use App\Services\GpaService;
use App\Services\GraduationService;
use App\Services\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentPortalController extends Controller
{
    public function __construct(private RegistrationService $registration) {}

    public function dashboard(GraduationService $graduation)
    {
        $student = auth()->user()->studentProfile?->load(['specialization', 'entrySession', 'graduate']);

        // Graduation progress for active 300/400 level students.
        $progress = $student && $student->isActive() && $student->current_level >= 300 ? $graduation->evaluate($student) : null;

        $evaluation = $student?->isTransfer() ? $student->transferEvaluation?->load('exemptions.course') : null;

        return view('portal.student', [
            'student' => $student, 'progress' => $progress, 'evaluation' => $evaluation,
            'graduate' => $student?->graduate?->effective(), // the record as corrected
        ]);
    }

    /** Alumni: permanent statement of result from the graduate record, with any approved corrections applied. */
    public function statement()
    {
        $graduate = auth()->user()->studentProfile?->graduate?->effective() ?? abort(404);

        return view('portal.statement', ['graduate' => $graduate]);
    }

    public function registration()
    {
        $student = $this->student();
        $session = AcademicSession::current();
        $blocked = $this->registration->blockedReason($student, $session);

        $available = collect();
        $mine = collect();
        if (! $blocked) {
            $available = $this->registration->availableCourses($student, $session);
            $mine = $student->registrations()
                ->where('academic_session_id', $session->id)
                ->where('semester', $session->current_semester)
                ->get()->keyBy('course_id');
        }

        return view('portal.registration', compact('student', 'session', 'blocked', 'available', 'mine'));
    }

    public function submitRegistration(Request $request): RedirectResponse
    {
        $student = $this->student();
        $session = AcademicSession::current();
        abort_if($this->registration->blockedReason($student, $session), 403);

        $request->validate(['courses' => ['nullable', 'array'], 'courses.*' => ['integer']]);
        $this->registration->submit($student, $session, $request->input('courses', []));
        AuditLog::record('registration.submitted', ['student_profile_id' => $student->id, 'session' => $session->name, 'semester' => $session->current_semester]);

        return back()->with('status', 'Registration submitted. Your Level Adviser will review it.');
    }

    public function results(GpaService $gpa)
    {
        $student = $this->student()->load(['user', 'specialization']);

        return view('portal.results', ['student' => $student, 'summary' => $gpa->summary($student)]);
    }

    public function printResults(GpaService $gpa)
    {
        $student = $this->student()->load(['user', 'specialization']);

        return view('portal.results-print', ['student' => $student, 'summary' => $gpa->summary($student)]);
    }

    public function nameChange(NameChangeService $names)
    {
        $student = $this->student();

        return view('portal.name-change', [
            'student' => $student,
            'blocked' => $names->blockedReason($student),
            'requests' => NameChangeRequest::where('student_profile_id', $student->id)->latest()->get(),
        ]);
    }

    public function submitNameChange(Request $request, NameChangeService $names): RedirectResponse
    {
        $student = $this->student();
        $data = $request->validate([
            'new_name' => ['required', 'string', 'max:150'],
            'document_type' => ['required', Rule::in(array_keys(NameChangeRequest::DOCUMENT_TYPES))],
            'marriage_document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:4096'],
            'newspaper_advert' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:4096'],
        ]);

        $names->submit($student, $data['new_name'], $data['document_type'], $request->file('marriage_document'), $request->file('newspaper_advert'));

        return back()->with('status', 'Request submitted. The EO will verify your documents and the HoD will decide.');
    }

    public function enquiries()
    {
        return view('portal.enquiries', ['enquiries' => Enquiry::where('student_profile_id', $this->student()->id)->latest()->get()]);
    }

    public function submitEnquiry(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(Enquiry::CATEGORIES))],
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:2000'],
        ]);
        Enquiry::create($data + ['student_profile_id' => $this->student()->id]);

        return back()->with('status', 'Your enquiry has been sent to the department.');
    }

    public function chooseSpecialization(Request $request): RedirectResponse
    {
        $student = $this->student();
        $session = AcademicSession::current();

        abort_unless(
            $student->current_level === 400 && $student->specialization_id === null && $session?->specialization_window_open,
            403,
            'The specialization selection window is not open for you.'
        );

        $data = $request->validate([
            'specialization_id' => ['required', Rule::exists('specializations', 'id')->where('is_active', true)],
        ]);
        $student->update(['specialization_id' => $data['specialization_id']]);
        AuditLog::record('specialization.chosen', ['student_profile_id' => $student->id, 'specialization_id' => $data['specialization_id']]);

        return redirect()->route('student.registration')->with('status', 'Area of specialization saved.');
    }

    private function student()
    {
        return auth()->user()->studentProfile ?? abort(403, 'No student record is linked to this account.');
    }
}
