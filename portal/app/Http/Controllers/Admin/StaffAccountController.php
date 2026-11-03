<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LevelAdviserAppointment;
use App\Models\StaffMember;
use App\Models\User;
use App\Notifications\PortalMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff sign-in accounts. The HoD manages lecturers, appointed admins and the EO; the EO manages lecturers.
 * Only the HoD appoints the Examination Officer or hands the office of HoD to someone else.
 */
class StaffAccountController extends Controller
{
    public function index(Request $request)
    {
        $users = User::whereIn('role', User::STAFF_ROLES)
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$request->string('q').'%')
                ->orWhere('email', 'like', '%'.$request->string('q').'%')->orWhere('identifier', 'like', '%'.$request->string('q').'%')))
            ->orderByRaw("case role when 'principal_admin' then 1 when 'secondary_admin' then 2 when 'admin' then 3 else 4 end")
            ->orderBy('name')->get();

        return view('admin.accounts.index', [
            'users' => $users,
            'item' => $request->filled('edit') ? $this->manageable($request->user(), User::findOrFail($request->integer('edit'))) : null,
            'isHod' => $request->user()->isPrincipalAdmin(),
            'canManage' => fn (User $u) => $this->canManage($request->user(), $u),
            'advisers' => LevelAdviserAppointment::holding()->pluck('level', 'user_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'identifier' => ['required', 'string', 'max:40', 'unique:users,identifier'],
            'title' => ['nullable', 'string', 'max:30'],
            'position' => ['nullable', 'string', 'max:150'],
            'cadre' => ['nullable', Rule::in(StaffMember::CADRES)],
        ]);

        $password = Str::password(12, symbols: false);
        $user = DB::transaction(function () use ($data, $request, $password) {
            $user = User::create([
                'name' => $data['name'], 'email' => $data['email'], 'identifier' => $data['identifier'],
                'role' => User::ROLE_LECTURER, 'password' => $password, 'must_change_password' => true,
            ]);
            // Optional public directory entry the lecturer can then keep up to date.
            if ($request->boolean('add_to_directory')) {
                StaffMember::create([
                    'user_id' => $user->id, 'category' => 'academic', 'cadre' => $data['cadre'] ?? null,
                    'title' => $data['title'] ?? null, 'name' => $data['name'],
                    'position' => ($data['position'] ?? null) ?: 'Lecturer', 'email' => $data['email'], 'is_published' => true,
                ]);
            }

            return $user;
        });
        AuditLog::record('staff.created', ['user_id' => $user->id]);

        // Shown once. Hand it over privately; it must be changed at the first sign-in.
        return redirect()->route('admin.accounts.index')->with('status', "Account created for {$user->name}. Temporary password (shown once): {$password}");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->manageable($request->user(), $user);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'identifier' => ['required', 'string', 'max:40', Rule::unique('users', 'identifier')->ignore($user->id)],
        ]);

        $user->update($data);
        AuditLog::record('staff.updated', ['user_id' => $user->id]);

        return redirect()->route('admin.accounts.index')->with('status', 'Account updated.');
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        $this->manageable($request->user(), $user);

        if (LevelAdviserAppointment::holding()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['account' => "{$user->name} is a Level Adviser. Remove them from the post first (Level Advisers page)."]);
        }

        $user->update(['is_active' => false]);
        $user->endSessions();
        AuditLog::record('staff.deactivated', ['user_id' => $user->id]);

        return back()->with('status', "{$user->name} can no longer sign in.");
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        $this->manageable($request->user(), $user);
        $user->update(['is_active' => true]);
        AuditLog::record('staff.reactivated', ['user_id' => $user->id]);

        return back()->with('status', "{$user->name} can sign in again.");
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->manageable($request->user(), $user);

        $password = Str::password(12, symbols: false);
        $user->forceFill(['password' => $password, 'must_change_password' => true])->save();
        $user->endSessions();
        AuditLog::record('password.reset_by_admin', ['user_id' => $user->id]);

        return back()->with('status', "New temporary password for {$user->name} (shown once): {$password}");
    }

    /** HoD only. One EO at a time: the previous EO goes back to being a lecturer. */
    public function appointEo(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isPrincipalAdmin(), 403);
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->whereIn('role', [User::ROLE_LECTURER, User::ROLE_ADMIN])->where('is_active', true)],
        ]);

        $new = User::findOrFail($data['user_id']);
        DB::transaction(function () use ($new) {
            User::where('role', User::ROLE_SECONDARY_ADMIN)->get()->each(function (User $old) {
                $old->update(['role' => User::ROLE_LECTURER, 'permissions' => null]);
                $old->notify(new PortalMessage('Role changed', 'You are no longer the Examination Officer.'));
            });
            $new->update(['role' => User::ROLE_SECONDARY_ADMIN, 'permissions' => null]);
        });
        $new->notify(new PortalMessage('You are the Examination Officer', 'The HoD has appointed you Examination Officer. You will be asked to set up two-factor login.'));
        AuditLog::record('staff.eo_appointed', ['user_id' => $new->id]);

        return back()->with('status', "{$new->name} is now the Examination Officer.");
    }

    /** HoD only. Transfers the office to a new HoD; there is always exactly one. */
    public function handover(Request $request): RedirectResponse
    {
        $hod = $request->user();
        abort_unless($hod->isPrincipalAdmin(), 403);
        $data = $request->validate([
            'new_hod_id' => ['required', Rule::exists('users', 'id')->whereIn('role', [User::ROLE_LECTURER, User::ROLE_ADMIN, User::ROLE_SECONDARY_ADMIN])->where('is_active', true)],
            'current_password' => ['required', 'current_password'],
            'confirm' => ['accepted'],
        ], ['current_password.current_password' => 'Your password is not correct.', 'confirm.accepted' => 'Tick the box to confirm the handover.']);

        $new = User::findOrFail($data['new_hod_id']);
        DB::transaction(function () use ($hod, $new) {
            $hod->update(['role' => User::ROLE_LECTURER, 'permissions' => null]);
            $new->update(['role' => User::ROLE_PRINCIPAL_ADMIN, 'permissions' => null]);
            if (User::where('role', User::ROLE_PRINCIPAL_ADMIN)->count() !== 1) {
                throw ValidationException::withMessages(['new_hod_id' => 'There must be exactly one Head of Department.']);
            }
        });
        AuditLog::record('office.hod_handover', ['from' => $hod->id, 'to' => $new->id]);
        $new->notify(new PortalMessage('You are the Head of Department', 'The office of HoD has been handed over to you. You will be asked to set up two-factor login.'));

        return redirect()->route('home')->with('status', "The office of Head of Department now belongs to {$new->name}. You are now a lecturer.");
    }

    private function canManage(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return false;
        }

        return match (true) {
            $actor->isPrincipalAdmin() => in_array($target->role, [User::ROLE_LECTURER, User::ROLE_ADMIN, User::ROLE_SECONDARY_ADMIN], true),
            $actor->role === User::ROLE_SECONDARY_ADMIN => $target->role === User::ROLE_LECTURER,
            default => false,
        };
    }

    private function manageable(User $actor, User $target): User
    {
        abort_unless($this->canManage($actor, $target), 403, 'You cannot manage this account.');

        return $target;
    }
}
