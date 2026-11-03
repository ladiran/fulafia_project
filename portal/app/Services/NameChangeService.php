<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\NameChangeRequest;
use App\Models\StudentNameHistory;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\PortalMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Change of name on marriage. Female students only.
 * submitted -> verified (EO checks the documents) -> approved (HoD). Either may reject.
 */
class NameChangeService
{
    public function blockedReason(StudentProfile $student): ?string
    {
        return match (true) {
            $student->gender !== 'female' => 'Only female students can change their name on marriage.',
            $student->status === 'graduated' => 'Graduate records can only be corrected through the department.',
            NameChangeRequest::where('student_profile_id', $student->id)->whereIn('status', ['submitted', 'verified'])->exists()
                => 'You already have a request waiting to be decided.',
            default => null,
        };
    }

    public function submit(StudentProfile $student, string $newName, string $documentType, UploadedFile $marriageDoc, UploadedFile $newspaper): NameChangeRequest
    {
        if ($message = $this->blockedReason($student)) {
            throw ValidationException::withMessages(['new_name' => $message]);
        }
        $newName = trim(preg_replace('/\s+/', ' ', $newName));
        if (mb_strtolower($newName) === mb_strtolower($student->user->name)) {
            throw ValidationException::withMessages(['new_name' => 'The new name is the same as your current name.']);
        }

        // Private storage: never reachable by URL, only through the authorised download route.
        $request = NameChangeRequest::create([
            'student_profile_id' => $student->id,
            'current_name' => $student->user->name,
            'new_name' => $newName,
            'document_type' => $documentType,
            'marriage_document_path' => $marriageDoc->store('name-changes', 'local'),
            'newspaper_path' => $newspaper->store('name-changes', 'local'),
        ]);
        AuditLog::record('name_change.submitted', ['request_id' => $request->id, 'student_profile_id' => $student->id]);

        User::whereIn('role', [User::ROLE_PRINCIPAL_ADMIN, User::ROLE_SECONDARY_ADMIN])->where('is_active', true)->get()
            ->each(fn ($u) => $u->notify(new PortalMessage('Name change request', "{$student->user->name} ({$student->user->identifier}) has asked to change their name.")));

        return $request;
    }

    /** EO (or HoD) confirms the documents are in order, or rejects the request. */
    public function verify(NameChangeRequest $request, User $by, bool $ok, ?string $note): void
    {
        $this->require($request, ['submitted']);

        if ($ok) {
            $request->update(['status' => 'verified', 'verified_by' => $by->id, 'verified_at' => now(), 'note' => null]);
            AuditLog::record('name_change.verified', ['request_id' => $request->id]);

            return;
        }
        $this->finishRejected($request, $by, $note);
    }

    /** HoD approves: the name changes, the old name stays in the permanent history. */
    public function approve(NameChangeRequest $request, User $hod): void
    {
        $this->require($request, ['verified']);

        DB::transaction(function () use ($request, $hod) {
            $student = $request->student()->with('user')->first();
            StudentNameHistory::create([
                'student_profile_id' => $student->id, 'name_change_request_id' => $request->id,
                'previous_name' => $student->user->name, 'new_name' => $request->new_name,
                'reason' => 'marriage', 'approved_by' => $hod->id, 'effective_date' => today(),
            ]);
            $student->user->update(['name' => $request->new_name]);
            $request->update(['status' => 'approved', 'decided_by' => $hod->id, 'decided_at' => now()]);
            AuditLog::record('name_change.approved', ['request_id' => $request->id, 'student_profile_id' => $student->id]);
        });
        $request->student->user->notify(new PortalMessage('Name change approved', "Your name on the portal is now {$request->new_name}. Your matric number is unchanged."));
    }

    public function reject(NameChangeRequest $request, User $hod, ?string $note): void
    {
        $this->require($request, ['submitted', 'verified']);
        $this->finishRejected($request, $hod, $note);
    }

    private function finishRejected(NameChangeRequest $request, User $by, ?string $note): void
    {
        if (blank($note)) {
            throw ValidationException::withMessages(['note' => 'Give a reason for rejecting the request.']);
        }
        $request->update(['status' => 'rejected', 'decided_by' => $by->id, 'decided_at' => now(), 'note' => $note]);
        AuditLog::record('name_change.rejected', ['request_id' => $request->id, 'note' => $note]);
        $request->student->user->notify(new PortalMessage('Name change not approved', "Your request was not approved: {$note}"));
    }

    private function require(NameChangeRequest $request, array $statuses): void
    {
        if (! in_array($request->status, $statuses, true)) {
            throw ValidationException::withMessages(['note' => 'This request is no longer at the right stage for that action.']);
        }
    }
}
