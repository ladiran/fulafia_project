<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private TwoFactorService $twoFactor) {}

    public function show()
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        // Lock the account/IP pair after repeated failures (slows guessing without locking real owners out for long).
        $key = 'login:'.Str::lower($data['login']).'|'.$request->ip();
        $max = config('portal.login_max_attempts');
        if (RateLimiter::tooManyAttempts($key, $max)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages(['login' => "Too many failed attempts. Try again in {$minutes} minute(s)."]);
        }

        // Matric number / staff ID, or email.
        $user = User::where('email', $data['login'])->orWhere('identifier', $data['login'])->first();

        if (! $user || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, config('portal.login_lockout_seconds'));
            AuditLog::record('login.failed', ['login' => Str::limit($data['login'], 60)]);

            throw ValidationException::withMessages(['login' => 'These credentials do not match our records.']);
        }
        RateLimiter::clear($key);

        // Second step: the password alone is not enough for accounts with two-factor on.
        if ($user->hasTwoFactor()) {
            $request->session()->regenerate();
            $request->session()->put('two_factor.user_id', $user->id);
            $request->session()->put('two_factor.started', now()->timestamp);
            $request->session()->put('two_factor.intended', $request->session()->pull('url.intended'));

            return redirect()->route('two-factor.challenge');
        }

        return $this->completeLogin($request, $user);
    }

    public function challenge(Request $request)
    {
        $this->pendingUser($request) ?? abort(redirect()->route('login'));

        return view('auth.two-factor-challenge');
    }

    public function verifyChallenge(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request) ?? abort(redirect()->route('login')->withErrors(['login' => 'Your sign-in expired. Please start again.']));
        $data = $request->validate(['code' => ['nullable', 'string', 'max:20'], 'recovery_code' => ['nullable', 'string', 'max:30']]);

        $key = 'two-factor:'.$user->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Wait a few minutes and sign in again.']);
        }

        $ok = filled($data['code'] ?? null)
            ? $this->twoFactor->verify($user, $data['code'])
            : (filled($data['recovery_code'] ?? null) && $this->twoFactor->useRecoveryCode($user, $data['recovery_code']));

        if (! $ok) {
            RateLimiter::hit($key, 300);
            AuditLog::record('login.two_factor_failed', ['user_id' => $user->id]);

            throw ValidationException::withMessages(['code' => 'That code is not valid.']);
        }
        RateLimiter::clear($key);
        if (filled($data['recovery_code'] ?? null)) {
            AuditLog::record('login.recovery_code_used', ['user_id' => $user->id]);
        }

        $intended = $request->session()->pull('two_factor.intended');
        $request->session()->forget(['two_factor.user_id', 'two_factor.started']);
        if ($intended) {
            $request->session()->put('url.intended', $intended);
        }

        return $this->completeLogin($request, $user);
    }

    public function logout(Request $request): RedirectResponse
    {
        AuditLog::record('logout');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    private function completeLogin(Request $request, User $user): RedirectResponse
    {
        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        AuditLog::record('login');

        return redirect()->intended(route($user->homeRoute()));
    }

    /** The user who passed the password step in this session, if it is still fresh (5 minutes). */
    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('two_factor.user_id');
        $started = (int) $request->session()->get('two_factor.started');
        if (! $id || now()->timestamp - $started > 300) {
            return null;
        }

        return User::where('id', $id)->where('is_active', true)->first();
    }
}
