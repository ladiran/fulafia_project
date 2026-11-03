<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Result;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\CourseSheetService;
use App\Services\PdfService;
use App\Services\ResultService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Result sheets. Lecturers work on the courses assigned to them; the EO / HoD (and appointed admins
 * with the manage_results permission) can open any sheet. Approving and reopening are HoD only.
 */
class ResultController extends Controller
{
    public function __construct(private ResultService $results, private CalendarService $calendar) {}

    public function index()
    {
        $user = auth()->user();
        $session = AcademicSession::current();
        $manager = $user->hasPermission('manage_results');

        $courses = collect();
        $offerings = collect();
        $lecturers = collect();

        if ($session) {
            if ($manager) {
                $courses = Course::where('is_active', true)->where('semester', $session->current_semester)
                    ->orderBy('level')->orderBy('code')->get();
                $offerings = CourseOffering::where('academic_session_id', $session->id)->with('lecturer')->get()->keyBy('course_id');
                $lecturers = User::where('role', User::ROLE_LECTURER)->where('is_active', true)->orderBy('name')->get();
            } else {
                $offerings = CourseOffering::where('academic_session_id', $session->id)
                    ->where('lecturer_id', $user->id)->with('course')->get();
            }
        }

        return view('results.index', compact('session', 'manager', 'courses', 'offerings', 'lecturers'));
    }

    public function assign(Request $request, Course $course): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('manage_results'), 403);
        $session = AcademicSession::current() ?? abort(422, 'No current session.');

        $data = $request->validate(['lecturer_id' => ['nullable', 'exists:users,id']]);
        $offering = CourseOffering::firstOrCreate(['course_id' => $course->id, 'academic_session_id' => $session->id]);
        $offering->update(['lecturer_id' => $data['lecturer_id'] ?? null]);
        AuditLog::record('results.lecturer_assigned', ['course' => $course->code, 'lecturer_id' => $data['lecturer_id'] ?? null]);

        return back()->with('status', "Lecturer updated for {$course->code}.");
    }

    /** Opens (creating if needed) the sheet for a course in the current session. */
    public function open(Request $request, Course $course): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('manage_results'), 403);
        $session = AcademicSession::current() ?? abort(422, 'No current session.');
        $offering = CourseOffering::firstOrCreate(['course_id' => $course->id, 'academic_session_id' => $session->id]);

        return redirect()->route('results.show', $offering);
    }

    public function show(CourseOffering $offering)
    {
        $this->authorizeSheet($offering);
        $offering->load(['course', 'session', 'lecturer']);

        $registrations = $offering->approvedRegistrations()->with('student.user')->get()
            ->sortBy(fn ($r) => $r->student->user->identifier)->values();
        $scores = Result::where('course_offering_id', $offering->id)->get()->keyBy('course_registration_id');

        return view('results.show', [
            'offering' => $offering,
            'registrations' => $registrations,
            'scores' => $scores,
            'closedReason' => $this->closedReason($offering),
            'canEdit' => $offering->isDraft() && ! $this->closedReason($offering),
            'manager' => auth()->user()->hasPermission('manage_results'),
            'isHod' => auth()->user()->isPrincipalAdmin(),
        ]);
    }

    /** The department's "Examination result" sheet for one course, as a PDF. */
    public function sheet(CourseOffering $offering, CourseSheetService $sheets, PdfService $pdf)
    {
        $this->authorizeSheet($offering);
        AuditLog::record('results.sheet_generated', ['offering_id' => $offering->id]);

        return response($pdf->render('pdf.course-sheet', $sheets->build($offering)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.\Illuminate\Support\Str::slug($offering->course->code.'-'.$offering->session->name).'-result-sheet.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function save(Request $request, CourseOffering $offering): RedirectResponse
    {
        $this->authorizeSheet($offering);
        $this->requireWindow($offering);
        $request->validate(['scores' => ['nullable', 'array']]);

        $saved = $this->results->saveScores($offering, $request->input('scores', []));

        return back()->with('status', "Draft saved ({$saved} score row(s)).");
    }

    public function upload(Request $request, CourseOffering $offering): RedirectResponse
    {
        $this->authorizeSheet($offering);
        $this->requireWindow($offering);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:1024']]);

        [$applied, $problems] = $this->results->importCsv($offering, $request->file('file')->getRealPath());
        AuditLog::record('results.csv_imported', ['offering_id' => $offering->id, 'rows' => $applied]);

        $back = back()->with('status', "Imported {$applied} row(s).");

        return $problems ? $back->with('problems', $problems) : $back;
    }

    public function submit(CourseOffering $offering): RedirectResponse
    {
        $this->authorizeSheet($offering);
        $this->requireWindow($offering);
        $this->results->submit($offering);

        return back()->with('status', 'Submitted for vetting. The sheet is now locked.');
    }

    public function vet(Request $request, CourseOffering $offering): RedirectResponse
    {
        $this->requireManager();
        $this->results->vet($offering, $request->user());

        return back()->with('status', 'Sheet vetted. It now waits for HoD approval.');
    }

    public function returnSheet(Request $request, CourseOffering $offering): RedirectResponse
    {
        $this->requireManager();
        $data = $request->validate(['note' => ['required', 'string', 'max:200']]);
        $this->results->returnToDraft($offering, $data['note']);

        return back()->with('status', 'Sheet returned to the lecturer.');
    }

    public function approve(Request $request, CourseOffering $offering): RedirectResponse
    {
        abort_unless($request->user()->isPrincipalAdmin(), 403);
        $this->results->approve($offering, $request->user());

        return back()->with('status', 'Sheet approved. The EO can now publish it.');
    }

    public function publish(CourseOffering $offering): RedirectResponse
    {
        $this->requireManager();
        $this->results->publish($offering);

        return back()->with('status', 'Results published. Students have been notified.');
    }

    public function reopen(Request $request, CourseOffering $offering): RedirectResponse
    {
        abort_unless($request->user()->isPrincipalAdmin(), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        $this->results->reopen($offering, $data['reason']);

        return back()->with('status', 'Sheet reopened as a draft.');
    }

    private function authorizeSheet(CourseOffering $offering): void
    {
        $user = auth()->user();
        abort_unless($user->hasPermission('manage_results') || $offering->lecturer_id === $user->id, 403);
    }

    /** Lecturers work on their sheets only while the calendar's result-upload window is open; the EO and HoD are not held to it. */
    private function closedReason(CourseOffering $offering): ?string
    {
        return auth()->user()->hasPermission('manage_results') ? null : $this->calendar->resultsBlocked($offering);
    }

    private function requireWindow(CourseOffering $offering): void
    {
        if ($reason = $this->closedReason($offering)) {
            throw ValidationException::withMessages(['scores' => $reason]);
        }
    }

    private function requireManager(): void
    {
        abort_unless(auth()->user()->hasPermission('manage_results'), 403);
    }
}
