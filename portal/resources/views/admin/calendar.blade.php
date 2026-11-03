@extends('layouts.portal')
@section('title', 'Academic calendar')

@php($stateBadge = ['open' => ['ACTIVE', 'OPEN'], 'upcoming' => ['EXPIRING', 'NOT YET OPEN'], 'closed' => ['REMOVED', 'CLOSED']])

@section('content')
<h1>Academic calendar</h1>
<p class="lead">
    Registration, result upload and the switch to the second semester follow the dates marked <strong>controls the portal</strong>.
    @if($isHod) You can change any date, add events, or force a window open or closed. @else Only the HoD can change the calendar. @endif
    A session with no such dates is not restricted.
</p>
@foreach(['title', 'starts_on', 'ends_on', 'key', 'category'] as $f)@error($f)<div class="alert bad">{{ $message }}</div>@enderror @endforeach

@if($session)
<form method="GET" action="{{ route('admin.calendar') }}" style="margin-bottom:14px">
    <label for="session">Session</label>
    <select id="session" name="session" style="width:auto">
        @foreach($sessions as $s)<option value="{{ $s->id }}" @selected($s->id === $session->id)>{{ $s->name }}@if($s->is_current) (current)@endif</option>@endforeach
    </select>
    <button class="btn small">Show</button>
</form>

@if($events->isEmpty())
    <div class="card" style="max-width:700px">
        <strong>No calendar for {{ $session->name }} yet.</strong>
        @if($isHod)
            @if($hasOfficial)
                <form method="POST" action="{{ route('admin.calendar.load', $session) }}" data-confirm="Load the official {{ $session->name }} calendar?">@csrf
                    <p><button class="btn gold">Load the official {{ $session->name }} calendar</button></p></form>
            @endif
            @if($others->isNotEmpty())
                <form method="POST" action="{{ route('admin.calendar.copy', $session) }}">@csrf
                    <label for="from">Or start from an earlier session's calendar (dates move forward by whole weeks; check each one)</label>
                    <select id="from" name="from" style="width:auto">@foreach($others as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select>
                    <p><button class="btn">Copy</button></p></form>
            @endif
            <p class="meta">Or add the events one by one below.</p>
        @endif
    </div>
@else
    <div class="table-wrap"><table>
        <tr><th>Event</th><th>Dates</th><th>Portal</th><th></th></tr>
        @foreach($events as $e)
            @php($st = $e->key ? $stateBadge[$e->stateOn($today)] : null)
            <tr>
                <td>
                    {{ $e->title }}
                    <br><small>{{ \App\Models\CalendarEvent::CATEGORIES[$e->category] ?? $e->category }}</small>
                    @if($e->notes)<br><small style="color:#8a6200">{{ $e->notes }}</small>@endif
                </td>
                <td style="white-space:nowrap">{{ $e->dateLabel() }}</td>
                <td>
                    @if($e->key)
                        <small>Controls the portal:<br>{{ \App\Models\CalendarEvent::WINDOWS[$e->key] }}</small><br>
                        <span class="badge {{ $st[0] }}">{{ $st[1] }}</span>@if($e->override) <span class="badge EXPIRED">FORCED {{ strtoupper($e->override) }}</span>@endif
                    @endif
                </td>
                <td style="white-space:nowrap">
                    @if($isHod)
                        <a class="btn small" href="{{ route('admin.calendar', ['session' => $session->id, 'edit' => $e->id]) }}">Edit</a>
                        @if($e->key)
                            <form class="inline" method="POST" action="{{ route('admin.calendar.override', $e) }}">@csrf
                                <select name="value" style="width:auto">
                                    <option value="dates" @selected(! $e->override)>Follow the dates</option>
                                    <option value="open" @selected($e->override === 'open')>Force open</option>
                                    <option value="closed" @selected($e->override === 'closed')>Force closed</option>
                                </select>
                                <button class="btn small">Set</button>
                            </form>
                        @endif
                    @endif
                </td>
            </tr>
        @endforeach
    </table></div>
@endif

@if($isHod)
    <h2>{{ $editing ? 'Edit event' : 'Add an event' }}</h2>
    <div class="card" style="max-width:640px">
        <form method="POST" action="{{ $editing ? route('admin.calendar.update', $editing) : route('admin.calendar.store', $session) }}">
            @csrf @if($editing) @method('PUT') @endif
            <label for="title">Event</label><input id="title" name="title" type="text" value="{{ old('title', $editing?->title) }}" required maxlength="200">
            <label for="category">Type</label>
            <select id="category" name="category">@foreach(\App\Models\CalendarEvent::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected(old('category', $editing?->category ?? 'other') == $k)>{{ $l }}</option>@endforeach</select>
            <label for="starts_on">Starts</label><input id="starts_on" name="starts_on" type="date" value="{{ old('starts_on', $editing?->starts_on?->format('Y-m-d')) }}" required>
            <label for="ends_on">Ends (leave empty for a one-day event)</label><input id="ends_on" name="ends_on" type="date" value="{{ old('ends_on', $editing?->ends_on?->format('Y-m-d')) }}">
            <label for="key">Controls the portal</label>
            <select id="key" name="key"><option value="">Nothing, it is only on the calendar</option>@foreach(\App\Models\CalendarEvent::WINDOWS as $k => $l)<option value="{{ $k }}" @selected(old('key', $editing?->key) == $k)>{{ $l }}</option>@endforeach</select>
            <label for="notes">Note (optional)</label><input id="notes" name="notes" type="text" value="{{ old('notes', $editing?->notes) }}" maxlength="300">
            <p><button class="btn">{{ $editing ? 'Save event' : 'Add event' }}</button> @if($editing)<a href="{{ route('admin.calendar', ['session' => $session->id]) }}">Cancel</a>@endif</p>
        </form>
        @if($editing)
            <form method="POST" action="{{ route('admin.calendar.destroy', $editing) }}" data-confirm="Delete this event?">@csrf @method('DELETE')<button class="btn small danger">Delete event</button></form>
        @endif
    </div>
@endif
@else
    <div class="alert warn">Add an academic session first (Sessions).</div>
@endif
@endsection
