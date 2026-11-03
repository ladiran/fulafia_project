<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** HoD (principal admin) appoints and revokes admins. */
class AdminAppointmentController extends Controller
{
    public function index()
    {
        return view('admin.admins', [
            'admins' => User::where('role', User::ROLE_ADMIN)->orderBy('name')->get(),
            'candidates' => User::where('role', User::ROLE_LECTURER)->orderBy('name')->get(),
            'permissions' => config('portal.admin_permissions'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->where('role', User::ROLE_LECTURER)],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => [Rule::in(array_keys(config('portal.admin_permissions')))],
        ]);

        $user = User::findOrFail($data['user_id']);
        $user->update(['role' => User::ROLE_ADMIN, 'permissions' => $data['permissions']]);
        AuditLog::record('admin.appointed', ['user_id' => $user->id, 'permissions' => $data['permissions']]);

        return back()->with('status', "{$user->name} is now an admin.");
    }

    public function destroy(User $user): RedirectResponse
    {
        abort_unless($user->role === User::ROLE_ADMIN, 404);

        $user->update(['role' => User::ROLE_LECTURER, 'permissions' => null]);
        AuditLog::record('admin.revoked', ['user_id' => $user->id]);

        return back()->with('status', "{$user->name} is no longer an admin.");
    }
}
