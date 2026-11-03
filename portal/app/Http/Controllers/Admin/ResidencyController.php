<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ResidencyExtension;
use App\Models\StudentProfile;
use App\Notifications\PortalMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A student who has used the sessions allowed at 400 level can appeal to the Senate. When the Senate grants
 * extra sessions, the HoD records them here (usually one) and the student may continue.
 */
class ResidencyController extends Controller
{
    public function store(Request $request, StudentProfile $student): RedirectResponse
    {
        abort_unless($request->user()->isPrincipalAdmin(), 403);
        $data = $request->validate([
            'sessions_granted' => ['required', 'integer', 'between:1,3'],
            'senate_reference' => ['required', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:300'],
        ], ['senate_reference.required' => 'Enter the Senate approval reference (letter or minute number).']);

        $withdrawnForResidency = $student->status === 'withdrawn' && $student->withdrawal_reason === 'residency_exceeded';
        if ($student->current_level !== 400 || ! ($student->isActive() || $withdrawnForResidency)) {
            throw ValidationException::withMessages(['sessions_granted' => 'An extension applies to an active 400 level student, or one withdrawn because the allowed sessions ran out.']);
        }

        ResidencyExtension::create($data + ['student_profile_id' => $student->id, 'granted_by' => $request->user()->id]);

        // A student withdrawn only because residency ran out is let back in; any other withdrawal stays as it is.
        if ($withdrawnForResidency) {
            $student->update(['status' => 'active', 'withdrawal_reason' => null]);
        }
        AuditLog::record('residency.extension_granted', [
            'student_profile_id' => $student->id, 'sessions' => $data['sessions_granted'], 'reference' => $data['senate_reference'], 'reinstated' => $withdrawnForResidency,
        ]);
        $student->user->notify(new PortalMessage('Residency extended', "The Senate has approved {$data['sessions_granted']} extra session(s) at 400 level for you."));

        return back()->with('status', "Extension recorded: {$data['sessions_granted']} extra session(s)".($withdrawnForResidency ? '. The student is active again.' : '.'));
    }
}
