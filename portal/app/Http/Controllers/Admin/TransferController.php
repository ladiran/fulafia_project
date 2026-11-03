<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseExemption;
use App\Models\StudentProfile;
use App\Models\TransferDocument;
use App\Services\TransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** The EO evaluates a transfer student's previous work; the HoD approves it. Locked once approved. */
class TransferController extends Controller
{
    public function __construct(private TransferService $transfers) {}

    public function index()
    {
        return view('admin.transfers.index', [
            'students' => StudentProfile::with(['user', 'transferEvaluation'])->where('entry_mode', StudentProfile::ENTRY_TRANSFER)
                ->join('users', 'users.id', '=', 'student_profiles.user_id')->orderBy('users.name')->select('student_profiles.*')->get(),
        ]);
    }

    public function show(StudentProfile $student)
    {
        $evaluation = $this->transfers->evaluationFor($student)->load(['documents', 'exemptions.course']);

        return view('admin.transfers.show', [
            'student' => $student->load('user'),
            'evaluation' => $evaluation,
            'courses' => Course::where('is_active', true)->whereNotIn('id', $evaluation->exemptions->pluck('course_id'))->orderBy('level')->orderBy('code')->get(),
            'isHod' => auth()->user()->isPrincipalAdmin(),
        ]);
    }

    public function update(Request $request, StudentProfile $student): RedirectResponse
    {
        $evaluation = $this->transfers->evaluationFor($student);
        $this->transfers->requireDraft($evaluation);
        $data = $request->validate([
            'previous_institution' => ['required', 'string', 'max:200'],
            'previous_programme' => ['nullable', 'string', 'max:200'],
        ]);
        $evaluation->update($data);

        return back()->with('status', 'Details saved.');
    }

    public function addExemption(Request $request, StudentProfile $student): RedirectResponse
    {
        $evaluation = $this->transfers->evaluationFor($student);
        $this->transfers->requireDraft($evaluation);
        $data = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'previous_code' => ['nullable', 'string', 'max:40'],
            'previous_title' => ['nullable', 'string', 'max:200'],
            'previous_grade' => ['nullable', 'string', 'max:10'],
        ]);

        if ($evaluation->exemptions()->where('course_id', $data['course_id'])->exists()) {
            throw ValidationException::withMessages(['course_id' => 'That course is already exempted.']);
        }
        if ($message = $this->transfers->conflict($student, (int) $data['course_id'])) {
            throw ValidationException::withMessages(['course_id' => $message]);
        }

        CourseExemption::create($data + [
            'transfer_evaluation_id' => $evaluation->id, 'student_profile_id' => $student->id,
            'counts_as_credit' => $request->boolean('counts_as_credit'),
        ]);
        AuditLog::record('transfer.exemption_added', ['student_profile_id' => $student->id, 'course_id' => $data['course_id']]);

        return back()->with('status', 'Exemption added.');
    }

    public function removeExemption(StudentProfile $student, CourseExemption $exemption): RedirectResponse
    {
        $evaluation = $this->transfers->evaluationFor($student);
        $this->transfers->requireDraft($evaluation);
        abort_unless($exemption->transfer_evaluation_id === $evaluation->id, 404);

        $exemption->delete();
        AuditLog::record('transfer.exemption_removed', ['student_profile_id' => $student->id, 'course_id' => $exemption->course_id]);

        return back()->with('status', 'Exemption removed.');
    }

    public function upload(Request $request, StudentProfile $student): RedirectResponse
    {
        $evaluation = $this->transfers->evaluationFor($student);
        $this->transfers->requireDraft($evaluation);
        $request->validate(['document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']]);

        $file = $request->file('document');
        $evaluation->documents()->create(['path' => $file->store('transfer-docs', 'local'), 'original_name' => mb_substr($file->getClientOriginalName(), 0, 200)]);
        AuditLog::record('transfer.document_added', ['student_profile_id' => $student->id]);

        return back()->with('status', 'Document uploaded.');
    }

    public function deleteDocument(StudentProfile $student, TransferDocument $document): RedirectResponse
    {
        $evaluation = $this->transfers->evaluationFor($student);
        $this->transfers->requireDraft($evaluation);
        abort_unless($document->transfer_evaluation_id === $evaluation->id, 404);

        Storage::disk('local')->delete($document->path);
        $document->delete();

        return back()->with('status', 'Document removed.');
    }

    /** Documents are private: only the EO and HoD can open them. */
    public function document(StudentProfile $student, TransferDocument $document)
    {
        abort_unless($document->transfer_evaluation_id === $this->transfers->evaluationFor($student)->id, 404);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->response($document->path, $document->original_name);
    }

    public function submit(Request $request, StudentProfile $student): RedirectResponse
    {
        $this->transfers->submit($this->transfers->evaluationFor($student)->load('student.user'), $request->user());

        return back()->with('status', 'Sent to the HoD for approval. The evaluation is now locked.');
    }

    /** HoD only. */
    public function approve(Request $request, StudentProfile $student): RedirectResponse
    {
        abort_unless($request->user()->isPrincipalAdmin(), 403);
        $this->transfers->approve($this->transfers->evaluationFor($student)->load('student.user'), $request->user());

        return back()->with('status', 'Evaluation approved and locked. The student can now register.');
    }

    /** HoD only. */
    public function returnToEo(Request $request, StudentProfile $student): RedirectResponse
    {
        abort_unless($request->user()->isPrincipalAdmin(), 403);
        $data = $request->validate(['note' => ['required', 'string', 'max:200']]);
        $this->transfers->returnToDraft($this->transfers->evaluationFor($student), $data['note']);

        return back()->with('status', 'Returned to the EO.');
    }
}
