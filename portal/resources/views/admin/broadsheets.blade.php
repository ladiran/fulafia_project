@extends('layouts.portal')
@section('title', 'Broadsheets')

@section('content')
<h1>Level broadsheets (Senate format)</h1>
<p class="lead">The full results of one level for one semester, in the format sent to Senate: every student's score and grade point per course, semester and cumulative totals, remarks (PASS, courses to repeat, courses to take), the summary, and signature lines for the HoD and Dean. Only <strong>published</strong> results are used. If some courses are not published yet, the sheet says so in red.</p>
@error('session_id')<div class="alert bad">{{ $message }}</div>@enderror

<div class="card" style="max-width:520px">
    <form method="GET" action="{{ route('admin.broadsheets.pdf') }}" target="_blank">
        <label for="session_id">Session</label>
        <select id="session_id" name="session_id" required>
            @foreach($sessions as $s)<option value="{{ $s->id }}" @selected($current?->id === $s->id)>{{ $s->name }}{{ $s->is_current ? ' (current)' : '' }}</option>@endforeach
        </select>
        <label for="semester">Semester</label>
        <select id="semester" name="semester"><option value="1" @selected(($current?->current_semester ?? 1) == 1)>First</option><option value="2" @selected(($current?->current_semester ?? 1) == 2)>Second</option></select>
        <label for="level">Level</label>
        <select id="level" name="level">@foreach([100, 200, 300, 400] as $l)<option value="{{ $l }}">{{ $l }} level</option>@endforeach</select>
        <p><button class="btn">Open the broadsheet (PDF)</button></p>
    </form>
</div>

<h2>How it reads</h2>
<ul>
    <li><strong>SCR / GP</strong>: score and grade point per course; a dash means the student did not register that course.</li>
    <li><strong>TCR, TCE, TGP, GPA</strong>: credits registered, credits earned, grade points and GPA for the semester; the cumulative blocks appear when the student has earlier results.</li>
    <li><strong>RPT</strong>: courses failed and not yet passed. <strong>TAKE</strong>: required core courses of the semester the student did not register.</li>
    <li>At the end of a session (second semester) a cumulative GPA below {{ number_format(config('portal.probation_cgpa'), 2) }} shows <strong>PROBATION</strong>, and <strong>TO WITHDRAW</strong> if the cumulative GPA was already below it a session earlier. Change the value in <code>config/portal.php</code> (<code>probation_cgpa</code>).</li>
    <li>For 400 level, second semester, students who graduated that session show their class of degree and the class-of-degree table is added.</li>
</ul>
@endsection
