@extends('layouts.portal')
@section('title', $graduate->name)

@section('content')
<h1>{{ $graduate->name }}</h1>
<p class="lead"><a href="{{ route('admin.graduates.index') }}">All graduates</a>
    · <a href="{{ route('admin.transcripts.graduate', $graduate) }}" target="_blank">Departmental transcript (PDF)</a>
    <span class="meta">for departmental use only, not an official transcript</span></p>
@error('field')<div class="alert bad">{{ $message }}</div>@enderror
@error('value')<div class="alert bad">{{ $message }}</div>@enderror
@error('exam')<div class="alert bad">{{ $message }}</div>@enderror
@error('reason')<div class="alert bad">{{ $message }}</div>@enderror
@error('graduate_result_id')<div class="alert bad">{{ $message }}</div>@enderror

@include('partials.graduate-statement')

<h2>Corrections</h2>
<p class="lead">This record is permanent and is never edited. A correction is asked for here and takes effect only when the HoD approves it. The original values stay in the history below.</p>

@php($pending = $history->where('status', 'requested'))
@if($history->isEmpty())
    <div class="card" style="color:var(--muted)">No corrections have been requested for this record.</div>
@else
    <div class="table-wrap"><table>
        <tr><th>Asked</th><th>Change</th><th>Reason</th><th>By</th><th>Status</th><th></th></tr>
        @foreach($history as $h)
            <tr>
                <td>{{ $h->created_at->format('j M Y') }}</td>
                <td>{{ $h->summary() }}</td>
                <td>{{ $h->reason }}@if($h->decision_note)<br><span class="meta">HoD: {{ $h->decision_note }}</span>@endif</td>
                <td>{{ $h->requester->name }}@if($h->decider)<br><span class="meta">decided by {{ $h->decider->name }}, {{ $h->decided_at->format('j M Y') }}</span>@endif</td>
                <td><span class="badge {{ ['requested' => 'EXPIRING', 'approved' => 'ACTIVE', 'rejected' => 'EXPIRED'][$h->status] }}">{{ strtoupper($h->status) }}</span></td>
                <td style="white-space:nowrap">
                    @if($h->status === 'requested' && $isHod)
                        <form class="inline" method="POST" action="{{ route('admin.corrections.approve', $h) }}" data-confirm="Approve this correction to the permanent record?">@csrf<button class="btn small gold">Approve</button></form>
                    @endif
                </td>
            </tr>
        @endforeach
    </table></div>
@endif

<div class="grid" style="margin-top:16px">
    <div class="card">
        <strong>Correct a detail</strong>
        <form method="POST" action="{{ route('admin.corrections.store', $graduate) }}">
            @csrf
            <input type="hidden" name="kind" value="field">
            <label for="field">Which detail</label>
            <select id="field" name="field" required>@foreach($fields as $k => $label)<option value="{{ $k }}">{{ $label }} (now: {{ $graduate->getAttribute($k) ?: 'not set' }})</option>@endforeach</select>
            <label for="value">Correct value</label>
            <input id="value" name="value" type="text" required maxlength="150">
            <label for="reason1">Reason (what proves it)</label>
            <input id="reason1" name="reason" type="text" required maxlength="300">
            <p><button class="btn small">Request correction</button></p>
        </form>
    </div>
    <div class="card">
        <strong>Correct a score</strong>
        <form method="POST" action="{{ route('admin.corrections.store', $graduate) }}">
            @csrf
            <input type="hidden" name="kind" value="result">
            <label for="graduate_result_id">Course</label>
            <select id="graduate_result_id" name="graduate_result_id" required>
                @foreach($graduate->results->reject(fn ($r) => $r->waived || $r->exempted)->sortBy(['session', 'course_code']) as $r)
                    <option value="{{ $r->id }}">{{ $r->session }} · {{ $r->course_code }} (now: {{ $r->grade ? $r->total.' '.$r->grade : ($r->flag ?: 'none') }})</option>
                @endforeach
            </select>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px">
                <div><label for="ca">CA 1</label><input id="ca" name="ca" type="text" inputmode="decimal"></div>
                <div><label for="ca2">CA 2</label><input id="ca2" name="ca2" type="text" inputmode="decimal"></div>
                <div><label for="exam">Exam</label><input id="exam" name="exam" type="text" inputmode="decimal"></div>
            </div>
            <label for="flag">Or a flag instead</label>
            <select id="flag" name="flag"><option value="">None</option><option value="absent">Absent</option><option value="incomplete">Incomplete</option><option value="withheld">Withheld</option></select>
            <label for="reason2">Reason (what proves it)</label>
            <input id="reason2" name="reason" type="text" required maxlength="300">
            <p><button class="btn small">Request correction</button></p>
        </form>
        <p class="meta">The grade, CGPA, credits passed and class of degree are worked out again from the corrected score.</p>
    </div>
</div>
@endsection
