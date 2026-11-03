<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LevelAdviserAppointment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Only the HoD appoints, renews and removes Level Advisers (routes are principal_admin only). */
class LevelAdviserController extends Controller
{
    public function index()
    {
        return view('admin.level-advisers', [
            'holding' => LevelAdviserAppointment::holding()->with('user')->orderBy('level')->get()->keyBy('level'),
            'candidates' => User::where('role', User::ROLE_LECTURER)->where('is_active', true)->orderBy('name')->get(),
            'history' => LevelAdviserAppointment::whereNotNull('removed_at')->with('user')->latest('removed_at')->limit(20)->get(),
            'levels' => LevelAdviserAppointment::LEVELS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->where('role', User::ROLE_LECTURER)],
            'level' => ['required', Rule::in(LevelAdviserAppointment::LEVELS)],
        ]);

        // An expired adviser keeps the post until the HoD removes them, so the level may still be held.
        if (LevelAdviserAppointment::holding()->where('level', $data['level'])->exists()) {
            throw ValidationException::withMessages([
                'level' => "{$data['level']}L already has a Level Adviser. Remove them first.",
            ]);
        }

        $start = today();
        $appointment = LevelAdviserAppointment::create([
            'user_id' => $data['user_id'],
            'level' => $data['level'],
            'starts_on' => $start,
            'ends_on' => LevelAdviserAppointment::tenureEndFrom($start),
            'appointed_by' => $request->user()->id,
        ]);
        AuditLog::record('level_adviser.appointed', ['appointment_id' => $appointment->id, 'level' => $data['level']]);

        return back()->with('status', 'Level Adviser appointed for '.$data['level'].'L.');
    }

    public function renew(LevelAdviserAppointment $appointment): RedirectResponse
    {
        abort_if($appointment->isRemoved(), 404);

        // Extend from the current end date if still running, otherwise from today.
        $from = $appointment->isExpired() ? today() : $appointment->ends_on;
        $appointment->update([
            'ends_on' => LevelAdviserAppointment::tenureEndFrom($from),
            'reminder_sent_at' => null,
        ]);
        AuditLog::record('level_adviser.renewed', ['appointment_id' => $appointment->id]);

        return back()->with('status', 'Tenure renewed to '.$appointment->ends_on->format('j M Y').'.');
    }

    public function destroy(Request $request, LevelAdviserAppointment $appointment): RedirectResponse
    {
        abort_if($appointment->isRemoved(), 404);

        $appointment->update(['removed_at' => now(), 'removed_by' => $request->user()->id]);
        AuditLog::record('level_adviser.removed', ['appointment_id' => $appointment->id, 'level' => $appointment->level]);

        return back()->with('status', 'Level Adviser removed.');
    }
}
