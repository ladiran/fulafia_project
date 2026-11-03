@extends('layouts.portal')
@section('title', 'Academic sessions')

@section('content')
<h1>Academic sessions</h1>
<p class="lead">A session has two semesters. The current session and semester decide which courses students can register. Switch the semester here, and open the specialization window at the start of 400 level.</p>
@error('name')<div class="alert bad">{{ $message }}</div>@enderror

<div class="table-wrap"><table>
    <tr><th>Session</th><th>Semester</th><th>Spec. window</th><th>Status</th><th></th></tr>
    @forelse($sessions as $s)
        <form method="POST" action="{{ route('admin.sessions.update', $s) }}">
            @csrf
            <tr>
                <td><strong>{{ $s->name }}</strong></td>
                <td><select name="current_semester" style="width:auto"><option value="1" @selected($s->current_semester == 1)>First</option><option value="2" @selected($s->current_semester == 2)>Second</option></select></td>
                <td><label class="check"><input type="checkbox" name="specialization_window_open" value="1" @checked($s->specialization_window_open)> Open</label></td>
                <td>
                    @if($s->is_current)<span class="badge ACTIVE">CURRENT</span>
                    @elseif($s->rolled_over_at)<span class="badge REMOVED">CLOSED</span>
                    @elseif(! $current)<label class="check"><input type="checkbox" name="make_current" value="1"> Make current</label>
                    @else<span class="badge EXPIRING">UPCOMING</span>@endif
                </td>
                <td><button class="btn small">Save</button></td>
            </tr>
        </form>
    @empty
        <tr><td colspan="5" style="color:var(--muted)">No sessions yet.</td></tr>
    @endforelse
</table></div>

@if($current)
    <h2>Start the next session</h2>
    <div class="card" style="max-width:700px">
        <p>When you start the next session, <strong>{{ $current->name }} ends and students move up automatically</strong>. There is nothing to approve. Failed courses go with each student and are registered again, first, at their next registration (up to {{ config('portal.max_units_per_semester') }} units a semester).</p>
        <table style="margin:8px 0">
            <tr><td>Moving up a level now</td><td><strong>{{ $preview['moving'] }}</strong>@if($preview['by_level']) <span class="meta">({{ collect($preview['by_level'])->map(fn ($n, $l) => "{$l}L: {$n}")->implode(', ') }})</span>@endif</td></tr>
            <tr><td>At 400 level, staying for another session</td><td><strong>{{ $preview['staying_at_400'] }}</strong></td></tr>
            <tr><td>At 400 level, marked withdrawn (all allowed sessions used)</td><td><strong>{{ $preview['to_withdraw'] }}</strong>@if($preview['withdraw_list']) <span class="meta">({{ implode(', ', $preview['withdraw_list']) }})</span>@endif</td></tr>
        </table>
        <p class="meta">A 400 level student may stay {{ config('portal.extra_sessions_at_400') }} more session(s) after the first. The HoD can record a Senate-approved extension on the student's page.</p>
        @if($preview['pending_graduation'])
            <div class="alert warn">A graduation batch for {{ $current->name }} is still waiting for the HoD. Decide it first; you cannot start the next session until then.</div>
        @endif
        <form method="POST" action="{{ route('admin.sessions.start-next') }}" data-confirm="End {{ $current->name }} and start the next session? Students will be moved up. This cannot be undone.">
            @csrf
            <label for="next_name">Next session</label>
            <input id="next_name" name="name" type="text" value="{{ old('name', $suggested) }}" required style="max-width:200px">
            <p><button class="btn gold" @disabled($preview['pending_graduation'])>End {{ $current->name }} and start the next session</button></p>
        </form>
    </div>
@endif

<h2>Add a session ahead of time</h2>
<div class="card" style="max-width:360px">
    <form method="POST" action="{{ route('admin.sessions.store') }}">
        @csrf
        <label for="name">Session (e.g. 2026/2027)</label>
        <input id="name" name="name" type="text" required value="{{ old('name') }}">
        <p><button class="btn">Add</button></p>
    </form>
</div>
@endsection
