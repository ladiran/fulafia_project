@extends('layouts.portal')
@section('title', 'Graduation')

@section('content')
<h1>Graduation</h1>
<p class="lead">Active 400 level students and whether they meet every requirement. The EO proposes a batch of eligible students; the HoD approves it. Approval creates the permanent graduate records.</p>
@error('students')<div class="alert bad">{{ $message }}</div>@enderror
@error('batch')<div class="alert bad">{{ $message }}</div>@enderror

<form method="POST" action="{{ route('admin.graduation.propose') }}">
    @csrf
    <div class="table-wrap"><table>
        <tr><th></th><th>Matric no.</th><th>Name</th><th>CGPA</th><th>Credits passed</th><th>Verdict</th><th></th></tr>
        @forelse($rows as $row)
            @php($s = $row['student'])
            @php($c = $row['check'])
            <tr>
                <td>@if($c['eligible'])<input type="checkbox" name="students[]" value="{{ $s->id }}">@endif</td>
                <td>{{ $s->user->identifier }}</td>
                <td>{{ $s->user->name }}</td>
                <td>{{ $c['cgpa'] !== null ? number_format($c['cgpa'], 2) : '–' }}</td>
                <td>{{ $c['credits_passed'] }} / {{ $c['required']['passed'] }}</td>
                <td><span class="badge {{ $c['eligible'] ? 'ACTIVE' : 'EXPIRED' }}">{{ $c['eligible'] ? 'ELIGIBLE' : 'NOT ELIGIBLE' }}</span></td>
                <td><a class="btn small" href="{{ route('admin.graduation.student', $s) }}">Checklist</a></td>
            </tr>
        @empty
            <tr><td colspan="7" style="color:var(--muted)">No active 400 level students.</td></tr>
        @endforelse
    </table></div>
    @if($rows->isNotEmpty())<p style="margin-top:12px"><button class="btn gold">Propose graduation for selected students</button></p>@endif
</form>

<h2>Batches</h2>
@forelse($batches as $b)
    <div class="card" style="margin-bottom:10px">
        <strong>{{ $b->session->name }}</strong> · {{ $b->students->count() }} student(s)
        <span class="badge {{ ['proposed' => 'EXPIRING', 'approved' => 'ACTIVE', 'rejected' => 'EXPIRED'][$b->status] }}">{{ strtoupper($b->status) }}</span>
        <div style="color:var(--muted);font-size:.9rem">{{ $b->students->map(fn ($s) => $s->user->identifier)->implode(', ') }}@if($b->note) · {{ $b->note }}@endif</div>
        @if($b->status === 'proposed' && $isHod)
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px">
                <form method="POST" action="{{ route('admin.graduation.approve', $b) }}" data-confirm="Approve graduation? Permanent records are created and cannot be edited.">@csrf<button class="btn gold">Approve graduation</button></form>
                <form method="POST" action="{{ route('admin.graduation.reject', $b) }}" style="display:flex;gap:6px">@csrf<input type="text" name="note" placeholder="Reason" required maxlength="200"><button class="btn small danger">Reject</button></form>
            </div>
        @elseif($b->status === 'proposed')<div style="color:var(--muted);margin-top:6px">Waiting for the HoD's approval.</div>@endif
    </div>
@empty
    <div class="card" style="color:var(--muted)">No batches yet.</div>
@endforelse

<h2>Credit requirements</h2>
<p class="lead">Minimum credits a student must have registered and passed to graduate. Waived courses never count as passed. The HoD or EO can change these.</p>
<form method="POST" action="{{ route('admin.graduation.requirements') }}" class="card" style="max-width:560px">
    @csrf
    <table style="border:0">
        <tr><th>Admission mode</th><th>Registered (min)</th><th>Passed (min)</th></tr>
        @foreach(\App\Models\StudentProfile::ENTRY_MODES as $mode => $label)
            <tr>
                <td>{{ $label }}</td>
                <td><input type="text" name="req[{{ $mode }}][registered]" value="{{ $requirements[$mode]['registered'] }}" style="width:90px"></td>
                <td><input type="text" name="req[{{ $mode }}][passed]" value="{{ $requirements[$mode]['passed'] }}" style="width:90px"></td>
            </tr>
        @endforeach
    </table>
    @error('req.*')<div class="error">Enter whole numbers.</div>@enderror
    <p><button class="btn">Save requirements</button></p>
</form>
@endsection
