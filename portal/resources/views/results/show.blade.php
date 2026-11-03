@extends('layouts.portal')
@section('title', $offering->course->code.' results')

@php($badge = ['draft' => 'REMOVED', 'submitted' => 'EXPIRING', 'vetted' => 'EXPIRING', 'approved' => 'ACTIVE', 'published' => 'ACTIVE'])
@php($caMax = config('portal.ca_max'))
@php($examMax = config('portal.exam_max'))

@section('content')
<h1>{{ $offering->course->code }}: {{ $offering->course->title }}</h1>
<p class="lead">
    {{ $offering->session->name }} · {{ $offering->course->level }}L · {{ $offering->course->units }} unit(s) · Lecturer: {{ $offering->lecturer?->name ?? 'unassigned' }}
    · <span class="badge {{ $badge[$offering->status] }}">{{ strtoupper($offering->status) }}</span>
    · <a href="{{ route('results.index') }}">All courses</a>
    · <a href="{{ route('results.sheet', $offering) }}" target="_blank">Course result sheet (PDF)</a>
</p>

@error('scores')<div class="alert bad">{{ $message }}</div>@enderror
@if($offering->return_note)<div class="alert warn"><strong>Note:</strong> {{ $offering->return_note }}</div>@endif
@if(session('problems'))<div class="alert warn"><strong>Some rows were skipped:</strong><ul>@foreach(session('problems') as $p)<li>{{ $p }}</li>@endforeach</ul></div>@endif

<form method="POST" action="{{ route('results.save', $offering) }}">
    @csrf
    <div class="table-wrap"><table>
        <tr><th>Matric no.</th><th>Name</th><th>CA 1</th><th>CA 2</th><th>Exam (/{{ $examMax }})</th><th>Total</th><th>Grade</th><th>Flag</th></tr>
        @forelse($registrations as $r)
            @php($s = $scores->get($r->id))
            <tr>
                <td>{{ $r->student->user->identifier }}</td>
                <td>{{ $r->student->user->name }}</td>
                @if($canEdit)
                    <td><input type="text" inputmode="decimal" name="scores[{{ $r->id }}][ca]" value="{{ $s?->ca }}" style="width:70px"></td>
                    <td><input type="text" inputmode="decimal" name="scores[{{ $r->id }}][ca2]" value="{{ $s?->ca2 }}" style="width:70px"></td>
                    <td><input type="text" inputmode="decimal" name="scores[{{ $r->id }}][exam]" value="{{ $s?->exam }}" style="width:80px"></td>
                @else
                    <td>{{ $s?->ca }}</td><td>{{ $s?->ca2 }}</td><td>{{ $s?->exam }}</td>
                @endif
                <td>{{ $s?->total }}</td>
                <td><strong>{{ $s?->grade }}</strong></td>
                <td>
                    @if($canEdit)
                        <select name="scores[{{ $r->id }}][flag]" style="width:auto">
                            <option value="">–</option>
                            @foreach(\App\Models\Result::FLAGS as $f)<option value="{{ $f }}" @selected($s?->flag === $f)>{{ ucfirst($f) }}</option>@endforeach
                        </select>
                    @else {{ $s?->flag ? ucfirst($s->flag) : '–' }} @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="8" style="color:var(--muted)">No approved registrations for this course yet. Students appear here once their Level Adviser approves their registration.</td></tr>
        @endforelse
    </table></div>

    @if($canEdit && $registrations->isNotEmpty())
        <p style="margin-top:12px"><button class="btn">Save draft</button></p>
    @endif
</form>

@if($canEdit && $registrations->isNotEmpty())
    <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:18px">
        <div class="card">
            <strong>Upload CSV</strong>
            <p style="color:var(--muted);font-size:.9rem;margin:4px 0">Columns: matric number, CA 1, CA 2, exam. (Three columns, matric, CA and exam, also work.) A header row is optional. CA 1 + CA 2 must not be more than {{ $caMax }}.</p>
            <form method="POST" action="{{ route('results.upload', $offering) }}" enctype="multipart/form-data">
                @csrf
                <input type="file" name="file" accept=".csv,.txt" required>
                @error('file')<div class="error">{{ $message }}</div>@enderror
                <p><button class="btn small gold">Import</button></p>
            </form>
        </div>
        <div class="card">
            <strong>Submit for vetting</strong>
            <p style="color:var(--muted);font-size:.9rem;margin:4px 0">Every student needs scores or a flag. Save your draft first. The sheet locks once submitted.</p>
            <form method="POST" action="{{ route('results.submit', $offering) }}" data-confirm="Submit this sheet? You will not be able to edit it afterwards.">@csrf<button class="btn">Submit</button></form>
        </div>
    </div>
@endif

@if($manager || $isHod)
<h2>Workflow</h2>
<div style="display:flex;gap:14px;flex-wrap:wrap">
    @if($manager && $offering->status === 'submitted')
        <div class="card"><strong>Vet</strong><p style="color:var(--muted);font-size:.9rem">Confirm the scores are correct.</p>
            <form method="POST" action="{{ route('results.vet', $offering) }}">@csrf<button class="btn">Mark as vetted</button></form></div>
    @endif
    @if($manager && in_array($offering->status, ['submitted', 'vetted']))
        <div class="card"><strong>Return to lecturer</strong>
            <form method="POST" action="{{ route('results.return', $offering) }}">@csrf
                <label for="note">Reason</label><input id="note" type="text" name="note" required maxlength="200">
                <p><button class="btn small danger">Return</button></p></form></div>
    @endif
    @if($isHod && $offering->status === 'vetted')
        <div class="card"><strong>HoD approval</strong><p style="color:var(--muted);font-size:.9rem">Final approval before publishing.</p>
            <form method="POST" action="{{ route('results.approve', $offering) }}">@csrf<button class="btn gold">Approve</button></form></div>
    @endif
    @if($manager && $offering->status === 'approved')
        <div class="card"><strong>Publish</strong><p style="color:var(--muted);font-size:.9rem">Students see their results and are notified.</p>
            <form method="POST" action="{{ route('results.publish', $offering) }}" data-confirm="Publish these results to students?">@csrf<button class="btn">Publish</button></form></div>
    @endif
    @if($isHod && $offering->status !== 'draft')
        <div class="card"><strong>Reopen (HoD)</strong><p style="color:var(--muted);font-size:.9rem">Back to draft for a correction. The reason is recorded.</p>
            <form method="POST" action="{{ route('results.reopen', $offering) }}">@csrf
                <input type="text" name="reason" placeholder="Reason" required maxlength="200">
                <p><button class="btn small danger">Reopen</button></p></form></div>
    @endif
</div>
@endif
@endsection
