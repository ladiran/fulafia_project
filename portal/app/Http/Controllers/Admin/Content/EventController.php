<?php

namespace App\Http\Controllers\Admin\Content;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.content.events', [
            'items' => Event::orderByDesc('starts_at')->get(),
            'item' => $request->filled('edit') ? Event::findOrFail($request->integer('edit')) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $event = Event::create($this->data($request));
        AuditLog::record('event.created', ['title' => $event->title]);

        return redirect()->route('admin.events.index')->with('status', 'Event added.');
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        $event->update($this->data($request));
        AuditLog::record('event.updated', ['title' => $event->title]);

        return redirect()->route('admin.events.index')->with('status', 'Event saved.');
    }

    public function destroy(Event $event): RedirectResponse
    {
        AuditLog::record('event.deleted', ['title' => $event->title]);
        $event->delete();

        return redirect()->route('admin.events.index')->with('status', 'Event deleted.');
    }

    private function data(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:150'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        return $data + ['is_published' => $request->boolean('is_published')];
    }
}
