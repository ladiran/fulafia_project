@extends('layouts.portal')
@section('title', 'Deferments')

@section('content')
<h1>Deferment of admission</h1>
<p class="lead">Only the HoD can start a deferment. The student must already be fully registered, and can defer at most {{ config('portal.max_deferred_sessions') }} session(s). Students ask through the enquiries form. The EO or HoD reactivates a student who returns.</p>
@error('deferment')<div class="alert bad">{{ $message }}</div>@enderror

@if($isHod)
<div class="card" style="max-width:520px;margin-bottom:18px">
    <strong>Start a deferment</strong>
    <form method="GET" action="{{ route('admin.deferments.index') }}" style="display:flex;gap:8px;align-items:end;margin-top:6px">
        <div style="flex:1"><label for="matric_lookup">Matric number</label><input id="matric_lookup" name="matric" type="text" value="{{ request('matric') }}"></div>
        <button class="btn small light" style="border:1px solid var(--line)">Check</button>
    </form>

    @if(request()->filled('matric'))
        @if($blocked)
            <div class="alert bad" style="margin-top:10px">{{ $blocked }}</div>
        @elseif($student)
            <div class="alert ok" style="margin-top:10px">{{ $student->user->name }}, {{ $student->current_level }}L, is eligible for deferment.</div>
            <form method="POST" action="{{ route('admin.deferments.store') }}">
                @csrf
                <input type="hidden" name="matric" value="{{ $student->user->identifier }}">
                <label for="academic_session_id">Session to defer</label>
                <select id="academic_session_id" name="academic_session_id">@foreach($sessions as $s)<option value="{{ $s->id }}" @selected($s->is_current)>{{ $s->name }}{{ $s->is_current ? ' (current)' : '' }}</option>@endforeach</select>
                @error('academic_session_id')<div class="error">{{ $message }}</div>@enderror
                <label for="reason">Reason</label>
                <input id="reason" name="reason" type="text" maxlength="200" required>
                @error('reason')<div class="error">{{ $message }}</div>@enderror
                @error('matric')<div class="error">{{ $message }}</div>@enderror
                <p><button class="btn gold">Defer this student</button></p>
            </form>
        @endif
    @endif
</div>
@endif

<div class="table-wrap"><table>
    <tr><th>Matric no.</th><th>Name</th><th>Session deferred</th><th>Reason</th><th>Status</th><th></th></tr>
    @forelse($deferments as $d)
        <tr>
            <td>{{ $d->student->user->identifier }}</td>
            <td>{{ $d->student->user->name }}</td>
            <td>{{ $d->session->name }}</td>
            <td>{{ $d->reason }}</td>
            <td><span class="badge {{ ['active' => 'EXPIRING', 'lapsed' => 'EXPIRED', 'returned' => 'ACTIVE', 'withdrawn' => 'REMOVED'][$d->status] }}">{{ strtoupper($d->status) }}</span></td>
            <td>
                @if($d->isOpen())
                    <form class="inline" method="POST" action="{{ route('admin.deferments.return', $d) }}">@csrf<button class="btn small">Student returns</button></form>
                    @if($d->status === 'lapsed' && $isHod)
                        <form class="inline" method="POST" action="{{ route('admin.deferments.withdraw', $d) }}" data-confirm="Mark this student as withdrawn?">@csrf<button class="btn small danger">Withdraw</button></form>
                    @endif
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="6" style="color:var(--muted)">No deferments yet.</td></tr>
    @endforelse
</table></div>
@endsection
