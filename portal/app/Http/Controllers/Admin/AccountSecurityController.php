<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Admin-assisted recovery: lost two-factor device (HoD) and forgotten student password (student managers). */
class AccountSecurityController extends Controller
{
    /** HoD only: lets the EO or an appointed admin set up two-factor again. */
    public function resetTwoFactor(Request $request, User $user, TwoFactorService $twoFactor): RedirectResponse
    {
        abort_unless($request->user()->isPrincipalAdmin(), 403);
        abort_unless(in_array($user->role, [User::ROLE_SECONDARY_ADMIN, User::ROLE_ADMIN], true), 403, 'Only the EO and appointed admins can be reset here.');

        $twoFactor->disable($user);
        AuditLog::record('two_factor.reset_by_hod', ['user_id' => $user->id]);

        return back()->with('status', "Two-factor login reset for {$user->name}. They set it up again at their next sign-in.");
    }

    /** Student managers: give a student a new temporary password they must change. */
    public function resetStudentPassword(Request $request, StudentProfile $student): RedirectResponse
    {
        $password = Str::password(10, symbols: false);
        $student->user->forceFill(['password' => $password, 'must_change_password' => true])->save();
        AuditLog::record('password.reset_by_admin', ['student_profile_id' => $student->id]);

        // Shown once, like the temporary password given at admission.
        return back()->with('status', "New temporary password for {$student->user->name} (shown once): {$password}");
    }
}
