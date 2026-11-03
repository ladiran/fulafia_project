@extends('layouts.portal')
@section('title', 'Graduation checklist')

@section('content')
<h1>{{ $student->user->name }}</h1>
<p class="lead">{{ $student->user->identifier }} · {{ \App\Models\StudentProfile::ENTRY_MODES[$student->entry_mode] }} · <a href="{{ route('admin.graduation.index') }}">Back to graduation</a>
    · <a href="{{ route('admin.students.show', $student) }}">Student record</a></p>

<div class="alert {{ $check['eligible'] ? 'ok' : 'bad' }}"><strong>{{ $check['eligible'] ? 'Eligible to graduate.' : 'Not yet eligible.' }}</strong>
    Credits registered {{ $check['credits_registered'] }}/{{ $check['required']['registered'] }} · passed {{ $check['credits_passed'] }}/{{ $check['required']['passed'] }}
    @if($check['cgpa'] !== null) · CGPA {{ number_format($check['cgpa'], 2) }}@endif</div>
@error('reason')<div class="alert bad">{{ $message }}</div>@enderror

<div class="table-wrap"><table>
    <tr><th></th><th>Requirement</th><th>Detail</th></tr>
    @foreach($check['checks'] as $c)
        <tr><td>{!! $c['ok'] ? '<span class="badge ACTIVE">OK</span>' : '<span class="badge EXPIRED">NO</span>' !!}</td><td>{{ $c['label'] }}</td><td>{{ $c['detail'] }}</td></tr>
    @endforeach
</table></div>

<h2>Unresolved electives</h2>
@if($check['waivable']->isEmpty())
    <div class="card" style="color:var(--muted)">None. Every registered elective is passed or waived.</div>
@else
    <div class="table-wrap"><table>
        <tr><th>Course</th><th>Title</th><th>Units</th><th></th></tr>
        @foreach($check['waivable'] as $course)
            <tr>
                <td>{{ $course->label() }}</td><td>{{ $course->title }}</td><td>{{ $course->units }}</td>
                <td>
                    @if($isHod)
                        <form method="POST" action="{{ route('admin.graduation.waive', [$student, $course]) }}" style="display:flex;gap:6px" data-confirm="Waive {{ $course->code }} for this student?">
                            @csrf<input type="text" name="reason" placeholder="Reason for waiver" required maxlength="200"><button class="btn small gold">Waive</button>
                        </form>
                    @else <span style="color:var(--muted)">Re-register and pass, or ask the HoD to waive</span> @endif
                </td>
            </tr>
        @endforeach
    </table></div>
@endif

@if($activeWaivers->isNotEmpty())
    <h2>Waived electives</h2>
    <div class="table-wrap"><table>
        <tr><th>Course</th><th>Reason</th><th>Waived</th><th></th></tr>
        @foreach($activeWaivers as $w)
            <tr><td>{{ $w->course->label() }}</td><td>{{ $w->reason }}</td><td>{{ $w->created_at->format('j M Y') }}</td>
                <td>@if($isHod)<form class="inline" method="POST" action="{{ route('admin.graduation.waiver.revoke', $w) }}">@csrf<button class="btn small danger">Revoke</button></form>@endif</td></tr>
        @endforeach
    </table></div>
@endif
@endsection
