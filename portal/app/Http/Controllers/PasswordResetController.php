<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Forgotten password by email, for staff only. Students have no reliable email address, so a student's
 * password is reset by the department (Admin > Students). The answer never reveals whether an email exists.
 */
class PasswordResetController extends Controller
{
    public function request()
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:150']]);

        $user = User::where('email', $data['email'])->whereIn('role', User::STAFF_ROLES)->where('is_active', true)->first();
        if ($user) {
            Password::sendResetLink(['email' => $user->email]);
            AuditLog::create(['action' => 'password.reset_requested', 'details' => ['user_id' => $user->id], 'ip_address' => $request->ip()]);
        }

        return back()->with('status', 'If that email belongs to a staff account, a reset link has been sent. It works for 60 minutes.');
    }

    public function form(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', SecurityController::passwordRule()],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            abort_unless($user->isStaff() && $user->is_active, 403);
            $user->forceFill([
                'password' => $password, 'must_change_password' => false,
                'password_changed_at' => now(), 'remember_token' => Str::random(60),
            ])->save();
            $user->endSessions();
            AuditLog::create(['action' => 'password.reset_by_email', 'details' => ['user_id' => $user->id]]);
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))->withErrors(['email' => 'This reset link is invalid or has expired. Ask for a new one.']);
        }

        return redirect()->route('login')->with('status', 'Your password has been reset. Sign in with the new password.');
    }
}
