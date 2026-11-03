@extends('layouts.portal')
@section('title', 'Record corrections')

@section('content')
<h1>Corrections to graduate records</h1>
<p class="lead">Graduate records are permanent. A correction is requested by the EO or HoD and applied only when the HoD approves it. The original value is always kept in the history.</p>
@error('correction')<div class="alert bad">{{ $message }}</div>@enderror
@error('exam')<div class="alert bad">{{ $message }}</div>@enderror

<h2>Waiting for the HoD ({{ $pending->count() }})</h2>
@forelse($pending as $p)
    <div class="card" style="margin-bottom:10px">
        <strong>{{ $p->graduate->matric }}</strong> · <a href="{{ route('admin.graduates.show', $p->graduate) }}">{{ $p->graduate->name }}</a><br>
        {{ $p->summary() }}<br>
        <span class="meta">Reason: {{ $p->reason }} · asked by {{ $p->requester->name }} on {{ $p->created_at->format('j M Y') }}</span>
        @if($isHod)
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px">
                <form method="POST" action="{{ route('admin.corrections.approve', $p) }}" data-confirm="Approve this correction to the permanent record?">@csrf<button class="btn gold">Approve</button></form>
                <form method="POST" action="{{ route('admin.corrections.reject', $p) }}" style="display:flex;gap:6px">@csrf<input type="text" name="note" placeholder="Reason for rejecting" required maxlength="200"><button class="btn small danger">Reject</button></form>
            </div>
        @else
            <div class="meta" style="margin-top:6px">Waiting for the HoD.</div>
        @endif
    </div>
@empty
    <div class="card" style="color:var(--muted)">Nothing is waiting.</div>
@endforelse

<h2>Recently decided</h2>
<div class="table-wrap"><table>
    <tr><th>Decided</th><th>Graduate</th><th>Change</th><th>Reason</th><th>Result</th></tr>
    @forelse($decided as $d)
        <tr>
            <td>{{ $d->decided_at?->format('j M Y') }}</td>
            <td><a href="{{ route('admin.graduates.show', $d->graduate) }}">{{ $d->graduate->matric }}</a></td>
            <td>{{ $d->summary() }}</td>
            <td>{{ $d->reason }}</td>
            <td><span class="badge {{ $d->status === 'approved' ? 'ACTIVE' : 'EXPIRED' }}">{{ strtoupper($d->status) }}</span>@if($d->decision_note)<br><span class="meta">{{ $d->decision_note }}</span>@endif</td>
        </tr>
    @empty
        <tr><td colspan="5" style="color:var(--muted)">No corrections have been decided yet.</td></tr>
    @endforelse
</table></div>
@endsection
