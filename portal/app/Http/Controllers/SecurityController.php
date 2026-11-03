<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** A signed-in user's own security: password and two-factor login. */
class SecurityController extends Controller
{
    public function __construct(private TwoFactorService $twoFactor) {}

    public static function passwordRule(): Password
    {
        return Password::min(10)->letters()->mixedCase()->numbers();
    }

    public function password()
    {
        return view('security.password', ['forced' => auth()->user()->must_change_password]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', self::passwordRule(), 'different:current_password'],
        ], ['password.different' => 'Choose a password you have not used just now.', 'current_password.current_password' => 'Your current password is not correct.']);

        $user->forceFill(['password' => $data['password'], 'must_change_password' => false, 'password_changed_at' => now()])->save();
        $this->signOutOtherDevices($request);
        AuditLog::record('password.changed');

        return redirect()->route($user->homeRoute())->with('status', 'Your password has been changed.');
    }

    public function twoFactor()
    {
        $user = auth()->user();
        $pending = null;

        // A secret that was generated but not yet confirmed: keep it until the user confirms or restarts.
        if (! $user->hasTwoFactor()) {
            if (! $user->two_factor_secret) {
                $user->forceFill(['two_factor_secret' => $this->twoFactor->newSecret()])->save();
            }
            $pending = ['secret' => $user->two_factor_secret, 'uri' => $this->twoFactor->uri($user, $user->two_factor_secret)];
        }

        return view('security.two-factor', [
            'enabled' => $user->hasTwoFactor(),
            'pending' => $pending,
            'codesLeft' => $user->hasTwoFactor() ? $this->twoFactor->recoveryCodesLeft($user) : 0,
            'required' => $user->mustUseTwoFactor(),
            'recoveryCodes' => session('recovery_codes'),
        ]);
    }

    public function confirmTwoFactor(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->hasTwoFactor() || ! $user->two_factor_secret, 404);
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        if (! $this->twoFactor->verify($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check the time on your phone and try the next code.']);
        }
        $codes = $this->twoFactor->enable($user);
        AuditLog::record('two_factor.enabled');

        return redirect()->route('security.two-factor')->with('recovery_codes', $codes)->with('status', 'Two-factor login is on.');
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasTwoFactor(), 404);
        $request->validate(['current_password' => ['required', 'current_password']], ['current_password.current_password' => 'Your password is not correct.']);

        AuditLog::record('two_factor.recovery_codes_regenerated');

        return redirect()->route('security.two-factor')->with('recovery_codes', $this->twoFactor->newRecoveryCodes($user));
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->mustUseTwoFactor(), 403, 'Two-factor login is compulsory for admin accounts.');
        $request->validate(['current_password' => ['required', 'current_password']], ['current_password.current_password' => 'Your password is not correct.']);

        $this->twoFactor->disable($user);
        AuditLog::record('two_factor.disabled');

        return redirect()->route('security.two-factor')->with('status', 'Two-factor login is off.');
    }

    /** After a password change, end every other session of this user (database session driver). */
    private function signOutOtherDevices(Request $request): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $request->user()->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }
    }
}
