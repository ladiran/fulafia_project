<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CourseRegistration;
use App\Models\StudentProfile;
use App\Models\TransferEvaluation;
use App\Models\User;
use App\Notifications\PortalMessage;
use Illuminate\Validation\ValidationException;

/**
 * Credit evaluation for transfer students: the EO records what the previous institution's work is worth,
 * the HoD approves it, and from then on it is locked. draft -> submitted -> approved (HoD may send it back).
 */
class TransferService
{
    public function evaluationFor(StudentProfile $student): TransferEvaluation
    {
        abort_unless($student->isTransfer(), 404, 'Only transfer students have a credit evaluation.');

        return TransferEvaluation::firstOrCreate(['student_profile_id' => $student->id]);
    }

    public function submit(TransferEvaluation $evaluation, User $by): void
    {
        $this->requireStatus($evaluation, ['draft'], 'Only a draft can be submitted.');
        if (blank($evaluation->previous_institution)) {
            throw ValidationException::withMessages(['evaluation' => 'Enter the previous institution first.']);
        }
        if ($evaluation->documents()->doesntExist()) {
            throw ValidationException::withMessages(['evaluation' => 'Upload the transfer documents (transcript or letter) first.']);
        }

        $evaluation->update(['status' => 'submitted', 'submitted_at' => now(), 'evaluated_by' => $by->id, 'note' => null]);
        AuditLog::record('transfer.submitted', ['evaluation_id' => $evaluation->id]);

        User::where('role', User::ROLE_PRINCIPAL_ADMIN)->where('is_active', true)->get()->each(fn ($hod) => $hod->notify(new PortalMessage(
            'Transfer evaluation to approve',
            "The credit evaluation for {$evaluation->student->user->name} ({$evaluation->student->user->identifier}) is waiting for your approval."
        )));
    }

    public function approve(TransferEvaluation $evaluation, User $hod): void
    {
        $this->requireStatus($evaluation, ['submitted'], 'Only a submitted evaluation can be approved.');

        $evaluation->update(['status' => 'approved', 'approved_by' => $hod->id, 'approved_at' => now()]);
        AuditLog::record('transfer.approved', ['evaluation_id' => $evaluation->id, 'exemptions' => $evaluation->exemptions()->count()]);

        $evaluation->student->user->notify(new PortalMessage('Transfer credits approved', 'Your transfer credit evaluation has been approved. You can now register your courses.'));
    }

    public function returnToDraft(TransferEvaluation $evaluation, string $note): void
    {
        $this->requireStatus($evaluation, ['submitted'], 'Only a submitted evaluation can be returned.');

        $evaluation->update(['status' => 'draft', 'submitted_at' => null, 'note' => $note]);
        AuditLog::record('transfer.returned', ['evaluation_id' => $evaluation->id, 'note' => $note]);
    }

    /** Whether a course may be exempted: it must not already be in the student's registrations. */
    public function conflict(StudentProfile $student, int $courseId): ?string
    {
        if ($student->registrations()->where('course_id', $courseId)->whereIn('status', [CourseRegistration::PENDING, CourseRegistration::APPROVED])->exists()) {
            return 'The student has already registered this course, so it cannot be exempted.';
        }

        return null;
    }

    public function requireDraft(TransferEvaluation $evaluation): void
    {
        $this->requireStatus($evaluation, ['draft'], 'The evaluation is locked. Only a draft can be edited.');
    }

    private function requireStatus(TransferEvaluation $evaluation, array $allowed, string $message): void
    {
        if (! in_array($evaluation->status, $allowed, true)) {
            throw ValidationException::withMessages(['evaluation' => $message]);
        }
    }
}
