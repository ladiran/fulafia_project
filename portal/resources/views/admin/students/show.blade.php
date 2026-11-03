@extends('layouts.portal')
@section('title', $student->user->name)

@section('content')
<h1>{{ $student->user->name }}</h1>
<p class="lead"><a href="{{ route('admin.students.index') }}">All students</a>
    @unless(in_array($student->status, ['deferred', 'graduated'])) · <a href="{{ route('admin.students.edit', $student) }}">Edit</a>@endunless
    · <form class="inline" method="POST" action="{{ route('admin.students.reset-password', $student) }}" data-confirm="Give this student a new temporary password?">@csrf<button class="btn small light" style="border:1px solid var(--line)">Reset password</button></form>
    @if(auth()->user()->isPrincipalAdmin() || auth()->user()->role === 'secondary_admin') · <a href="{{ route('admin.transcripts.student', $student) }}" target="_blank">Departmental transcript (PDF)</a>@endif
    @if($student->isTransfer() && (auth()->user()->isPrincipalAdmin() || auth()->user()->role === 'secondary_admin')) · <a href="{{ route('admin.transfers.show', $student) }}">Transfer evaluation</a>@endif
    @if(auth()->user()->isPrincipalAdmin() && $student->status === 'active') · <a href="{{ route('admin.deferments.index', ['matric' => $student->user->identifier]) }}">Defer (HoD)</a>@endif</p>

@error('sessions_granted')<div class="alert bad">{{ $message }}</div>@enderror
@error('senate_reference')<div class="alert bad">{{ $message }}</div>@enderror

@php($residencyWithdrawn = $student->status === 'withdrawn' && $student->withdrawal_reason === 'residency_exceeded')
@if($student->current_level === 400 && ($student->status === 'active' || $residencyWithdrawn))
    <div class="card" style="margin-bottom:14px">
        <strong>Residency at 400 level</strong>
        @if($residencyWithdrawn)<span class="badge EXPIRED">WITHDRAWN: SESSIONS USED UP</span>@endif<br>
        Finished {{ $student->sessions_at_400 }} of {{ $student->allowedSessionsAt400() }} allowed session(s)
        ({{ 1 + config('portal.extra_sessions_at_400') }} by the rules@if($student->residencyExtensions->isNotEmpty()), plus {{ $student->residencyExtensions->sum('sessions_granted') }} approved by the Senate@endif).
        @if($student->status === 'active')<span class="meta">{{ $student->sessionsAt400Left() }} session(s) left; at the end of the last one the student is marked withdrawn.</span>@endif
        @foreach($student->residencyExtensions as $x)
            <div class="meta">Extension: {{ $x->sessions_granted }} session(s), Senate ref. {{ $x->senate_reference }}, recorded {{ $x->created_at->format('j M Y') }}@if($x->note) · {{ $x->note }}@endif</div>
        @endforeach
        @if(auth()->user()->isPrincipalAdmin())
            <form method="POST" action="{{ route('admin.students.residency', $student) }}" style="margin-top:10px;max-width:520px" data-confirm="Record this Senate extension?">
                @csrf
                <strong>Record a Senate-approved extension</strong>
                <div style="display:grid;grid-template-columns:110px 1fr;gap:8px">
                    <div><label for="sessions_granted">Sessions</label><select id="sessions_granted" name="sessions_granted"><option>1</option><option>2</option><option>3</option></select></div>
                    <div><label for="senate_reference">Senate approval reference</label><input id="senate_reference" name="senate_reference" type="text" required maxlength="150"></div>
                </div>
                <label for="note">Note (optional)</label><input id="note" name="note" type="text" maxlength="300">
                <p><button class="btn small gold">Record extension{{ $residencyWithdrawn ? ' and reinstate' : '' }}</button></p>
            </form>
        @endif
    </div>
@endif

@include('partials.student-detail')
@endsection
