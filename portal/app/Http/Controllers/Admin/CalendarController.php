<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\CalendarEvent;
use App\Services\CalendarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The academic calendar. The HoD and the EO can read it; only the HoD changes it. */
class CalendarController extends Controller
{
    public function __construct(private CalendarService $calendar) {}

    public function index(Request $request)
    {
        $sessions = AcademicSession::orderByDesc('name')->get();
        $session = $sessions->firstWhere('id', (int) $request->query('session')) ?? AcademicSession::current() ?? $sessions->first();
        $editing = $session ? CalendarEvent::where('academic_session_id', $session->id)->find((int) $request->query('edit')) : null;

        return view('admin.calendar', [
            'sessions' => $sessions,
            'session' => $session,
            'events' => $session ? CalendarEvent::where('academic_session_id', $session->id)->orderBy('starts_on')->orderBy('id')->get() : collect(),
            'editing' => $editing,
            'today' => $this->calendar->today(),
            'isHod' => $request->user()->isPrincipalAdmin(),
            'hasOfficial' => $session && is_file(database_path('data/calendar_'.str_replace('/', '_', $session->name).'.php')),
            'others' => $session ? $sessions->where('id', '!=', $session->id)->filter(fn ($s) => $s->name < $session->name && CalendarEvent::where('academic_session_id', $s->id)->exists()) : collect(),
        ]);
    }

    public function loadOfficial(Request $request, AcademicSession $session): RedirectResponse
    {
        $this->hodOnly($request);
        abort_if(CalendarEvent::where('academic_session_id', $session->id)->exists(), 422, 'This session already has a calendar.');
        $count = $this->calendar->loadOfficial($session);
        AuditLog::record('calendar.loaded', ['session' => $session->name, 'events' => $count]);

        return back()->with('status', "{$count} events loaded into the {$session->name} calendar.");
    }

    public function copy(Request $request, AcademicSession $session): RedirectResponse
    {
        $this->hodOnly($request);
        abort_if(CalendarEvent::where('academic_session_id', $session->id)->exists(), 422, 'This session already has a calendar.');
        $from = AcademicSession::findOrFail((int) $request->validate(['from' => ['required', 'exists:academic_sessions,id']])['from']);
        $count = $this->calendar->copy($from, $session);
        AuditLog::record('calendar.copied', ['from' => $from->name, 'to' => $session->name, 'events' => $count]);

        return back()->with('status', "{$count} events copied from {$from->name}, moved forward by whole weeks. Check every date against the new calendar.");
    }

    public function store(Request $request, AcademicSession $session): RedirectResponse
    {
        $this->hodOnly($request);
        $event = CalendarEvent::create($this->validated($request, $session) + ['academic_session_id' => $session->id]);
        AuditLog::record('calendar.event_added', ['session' => $session->name, 'title' => $event->title]);

        return redirect()->route('admin.calendar', ['session' => $session->id])->with('status', 'Event added.');
    }

    public function update(Request $request, CalendarEvent $event): RedirectResponse
    {
        $this->hodOnly($request);
        $event->update($this->validated($request, $event->session, $event));
        // Moving a window's dates means a new closing reminder is due.
        $event->forceFill(['reminded_at' => null])->save();
        AuditLog::record('calendar.event_updated', ['session' => $event->session->name, 'title' => $event->title, 'key' => $event->key]);

        return redirect()->route('admin.calendar', ['session' => $event->academic_session_id])->with('status', 'Event updated.');
    }

    /** Force a window open or closed whatever its dates say, or go back to following the dates. */
    public function override(Request $request, CalendarEvent $event): RedirectResponse
    {
        $this->hodOnly($request);
        abort_unless($event->key, 422, 'Only registration, result and semester events can be forced.');
        $value = $request->validate(['value' => ['required', 'in:open,closed,dates']])['value'];
        $event->update(['override' => $value === 'dates' ? null : $value]);
        AuditLog::record('calendar.window_overridden', ['session' => $event->session->name, 'key' => $event->key, 'override' => $value]);

        return back()->with('status', $value === 'dates' ? 'The window follows its dates again.' : "The window is now forced {$value}.");
    }

    public function destroy(Request $request, CalendarEvent $event): RedirectResponse
    {
        $this->hodOnly($request);
        $event->delete();
        AuditLog::record('calendar.event_deleted', ['session' => $event->session->name, 'title' => $event->title]);

        return redirect()->route('admin.calendar', ['session' => $event->academic_session_id])->with('status', 'Event deleted.');
    }

    private function validated(Request $request, AcademicSession $session, ?CalendarEvent $event = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::in(array_keys(CalendarEvent::CATEGORIES))],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'key' => ['nullable', Rule::in(array_keys(CalendarEvent::WINDOWS)),
                Rule::unique('calendar_events', 'key')->where('academic_session_id', $session->id)->ignore($event?->id)],
            'notes' => ['nullable', 'string', 'max:300'],
        ], ['key.unique' => 'Another event of this session already controls that part of the portal.']);

        return [
            'title' => $data['title'], 'category' => $data['category'], 'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null, 'key' => $data['key'] ?? null, 'notes' => $data['notes'] ?? null,
        ];
    }

    private function hodOnly(Request $request): void
    {
        abort_unless($request->user()->isPrincipalAdmin(), 403);
    }
}
