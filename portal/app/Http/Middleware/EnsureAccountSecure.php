<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every signed-in request:
 *  - a deactivated account is signed out at once;
 *  - a temporary password must be changed before anything else;
 *  - admins must have two-factor login switched on.
 */
class EnsureAccountSecure
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['login' => 'This account is not active.']);
        }

        if ($request->routeIs('security.*', 'logout')) {
            return $next($request);
        }

        if ($user->must_change_password) {
            return redirect()->route('security.password')->with('status', 'Please choose a new password before you continue.');
        }

        if ($user->mustUseTwoFactor() && ! $user->hasTwoFactor()) {
            return redirect()->route('security.two-factor')->with('status', 'Two-factor login is compulsory for admin accounts. Set it up to continue.');
        }

        return $next($request);
    }
}
