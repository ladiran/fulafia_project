@extends('layouts.portal')
@section('title', 'Admin dashboard')

@section('content')
<h1>Admin dashboard</h1>
<p class="lead">
    @switch(auth()->user()->role)
        @case('principal_admin') Principal Admin (Head of Department) @break
        @case('secondary_admin') Secondary Admin (Examination Officer) @break
        @default Appointed admin
    @endswitch
</p>

<div class="grid">
    <div class="card"><div class="stat">{{ $staffCount }}</div>Staff accounts</div>
    <div class="card"><div class="stat">{{ $studentCount }}</div>Students</div>
    <div class="card"><div class="stat">{{ $advisers->count() }}/4</div>Levels with a Level Adviser</div>
</div>

<h2>Level Advisers</h2>
<div class="table-wrap"><table>
    <tr><th>Level</th><th>Adviser</th><th>Tenure ends</th><th>Status</th></tr>
    @foreach(\App\Models\LevelAdviserAppointment::LEVELS as $level)
        @php($a = $advisers->get($level))
        <tr>
            <td>{{ $level }}L</td>
            <td>{{ $a?->user->name ?? 'Not appointed' }}</td>
            <td>{{ $a?->ends_on->format('j M Y') ?? '–' }}</td>
            <td>@if($a)<span class="badge {{ $a->status() }}">{{ $a->status() }}</span>@endif</td>
        </tr>
    @endforeach
</table></div>

<p class="lead" style="margin-top:22px">Courses, students, results and graduation modules follow in the next build phases.</p>
@endsection
