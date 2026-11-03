<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Deferment;
use App\Models\StudentProfile;
use App\Services\DefermentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DefermentController extends Controller
{
    public function __construct(private DefermentService $deferments) {}

    public function index(Request $request)
    {
        $student = null;
        $blocked = null;
        if ($request->filled('matric')) {
            $student = StudentProfile::with('user')->whereHas('user', fn ($q) => $q->where('identifier', trim($request->string('matric'))))->first();
            $blocked = $student ? $this->deferments->blockedReason($student) : 'No student has that matric number.';
        }

        return view('admin.deferments.index', [
            'deferments' => Deferment::with(['student.user', 'session'])->latest()->get(),
            'sessions' => AcademicSession::orderByDesc('name')->get(),
            'student' => $student,
            'blocked' => $blocked,
            'isHod' => $request->user()->isPrincipalAdmin(),
        ]);
    }

    /** HoD only. */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isPrincipalAdmin(), 403);
        $data = $request->validate([
            'matric' => ['required', 'string'],
            'academic_session_id' => ['required', 'exists:academic_sessions,id'],
            'reason' => ['required', 'string', 'max:200'],
        ]);

        $student = StudentProfile::with('user')->whereHas('user', fn ($q) => $q->where('identifier', trim($data['matric'])))->first()
            ?? throw ValidationException::withMessages(['matric' => 'No student has that matric number.']);

        $this->deferments->defer($student, AcademicSession::findOrFail($data['academic_session_id']), $data['reason'], $request->user());

        return redirect()->route('admin.deferments.index')->with('status', "{$student->user->name} has been deferred.");
    }

    /** EO or HoD. */
    public function returnStudent(Request $request, Deferment $deferment): RedirectResponse
    {
        $this->deferments->returnStudent($deferment, $request->user());

        return back()->with('status', 'Student reactivated at the same level.');
    }

    /** HoD only, after a lapse. */
    public function withdraw(Request $request, Deferment $deferment): RedirectResponse
    {
        abort_unless($request->user()->isPrincipalAdmin(), 403);
        $this->deferments->withdraw($deferment, $request->user());

        return back()->with('status', 'Student marked as withdrawn.');
    }
}
