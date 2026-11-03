@extends('layouts.portal')
@section('title', 'Student dashboard')

@section('content')
<h1>Student dashboard</h1>
<p class="lead">Welcome, {{ auth()->user()->name }}.</p>

@if($student)
    @if($student->status === 'deferred')
        <div class="alert warn"><strong>Your admission is deferred.</strong> Course registration is closed until the department reactivates your account. Use Enquiries to contact the department.</div>
    @endif
    <div class="grid">
        <div class="card">
            Matric no.: <strong>{{ auth()->user()->identifier }}</strong><br>
            Level: <strong>{{ $student->current_level }}L</strong><br>
            Status: {{ ucfirst($student->status) }}<br>
            Admission: {{ \App\Models\StudentProfile::ENTRY_MODES[$student->entry_mode] ?? $student->entry_mode }}<br>
            Specialization: {{ $student->specialization?->name ?? '–' }}
        </div>
        <div class="card">
            <strong>Course registration</strong>
            <p>Register your courses for the current semester. Your Level Adviser approves them.</p>
            <a class="btn" href="{{ route('student.registration') }}">Go to registration</a>
        </div>
    </div>
    @if($student->status === 'active' && $student->current_level === 400)
        <div class="card" style="margin-top:14px">
            <strong>Time left at 400 level:</strong> {{ $student->sessionsAt400Left() }} session(s) including this one.
            <span class="meta">If you have not met the graduation requirements by then, you are marked withdrawn. You may appeal to the Senate through the department.</span>
        </div>
    @endif

    @if($student->status === 'withdrawn' && $student->withdrawal_reason === 'residency_exceeded')
        <div class="alert bad" style="margin-top:14px"><strong>You have been marked withdrawn:</strong> the sessions allowed at 400 level are used up. You may appeal to the Senate through the department; if an extension is granted the HoD will reactivate your account.</div>
    @endif

    @if($student->isTransfer())
        <div class="card" style="margin-top:14px">
            <strong>Transfer credit evaluation:</strong>
            @if($evaluation?->isApproved())
                approved. {{ $evaluation->exemptions->count() }} course(s) exempted
                @if($evaluation->exemptions->isNotEmpty())({{ $evaluation->exemptions->map(fn ($x) => $x->course->code)->implode(', ') }})@endif.
            @else
                waiting for the department. You can register once it is approved.
            @endif
        </div>
    @endif

    @if($graduate)
        <div class="card" style="margin-top:14px">
            <strong>Graduated</strong>: {{ $graduate->degree }}, {{ $graduate->class_of_degree }} (CGPA {{ number_format($graduate->cgpa, 2) }}), {{ $graduate->graduation_session }} session.<br>
            <a class="btn small" href="{{ route('student.statement') }}">View my statement of result</a>
        </div>
    @else
        <p style="margin-top:18px"><a class="btn gold" href="{{ route('student.results') }}">View my results</a></p>
    @endif

    @if($progress)
        <h2>Graduation progress</h2>
        <div class="table-wrap"><table>
            @foreach($progress['checks'] as $c)
                <tr><td>{!! $c['ok'] ? '<span class="badge ACTIVE">OK</span>' : '<span class="badge EXPIRING">TO DO</span>' !!}</td><td>{{ $c['label'] }}</td><td>{{ $c['detail'] }}</td></tr>
            @endforeach
        </table></div>
        <p style="color:var(--muted);font-size:.9rem">The level requirement shows OK only at 400 level. The final decision is made by the department.</p>
    @endif
@else
    <div class="card" style="color:var(--muted)">No student record is linked to this account yet. Please contact the department.</div>
@endif
@endsection
