<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Services\SessionRolloverService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(private SessionRolloverService $rollover) {}

    public function index()
    {
        $current = AcademicSession::current();

        return view('admin.sessions', [
            'sessions' => AcademicSession::orderByDesc('name')->get(),
            'current' => $current,
            'preview' => $current ? $this->rollover->preview($current) : null,
            'suggested' => $current ? $this->nextName($current->name) : null,
        ]);
    }

    /** Add a session. The very first one becomes current; later ones start through "Start the next session". */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'regex:/^\d{4}\/\d{4}$/', 'unique:academic_sessions,name'],
        ], ['name.regex' => 'Use the format 2025/2026.']);

        $session = AcademicSession::create($data);
        if (AcademicSession::count() === 1) {
            $session->makeCurrent();
        }
        AuditLog::record('session.created', ['name' => $session->name]);

        return back()->with('status', "Session {$session->name} added.");
    }

    /** Switch the current session between its two semesters, or open the specialization window. */
    public function update(Request $request, AcademicSession $session): RedirectResponse
    {
        $data = $request->validate([
            'current_semester' => ['required', 'in:1,2'],
            'specialization_window_open' => ['sometimes', 'boolean'],
        ]);

        // Only a session with nothing current can be made current here: afterwards it is done by starting the next session.
        if ($request->boolean('make_current') && AcademicSession::current() === null) {
            $session->makeCurrent();
        }
        $session->update([
            'current_semester' => $data['current_semester'],
            'specialization_window_open' => $request->boolean('specialization_window_open'),
        ]);
        AuditLog::record('session.updated', ['name' => $session->name, 'semester' => $data['current_semester']]);

        return back()->with('status', "Session {$session->name} updated.");
    }

    /** End the current session: students move up automatically and the next session begins. */
    public function startNext(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'regex:/^\d{4}\/\d{4}$/'],
        ], ['name.regex' => 'Use the format 2026/2027.']);

        $summary = $this->rollover->startNext($data['name'], $request->user());

        return back()->with('status', "Session {$data['name']} has started. {$summary['promoted']} student(s) moved up a level, {$summary['stayed']} stayed at 400 level, {$summary['withdrawn']} marked withdrawn (residency used up).");
    }

    private function nextName(string $name): string
    {
        [$a, $b] = array_map('intval', explode('/', $name));

        return ($a + 1).'/'.($b + 1);
    }
}
