@extends('layouts.portal')
@section('title', 'Transfer evaluation')

@section('content')
<h1>Transfer evaluation: {{ $student->user->name }}</h1>
<p class="lead">{{ $student->user->identifier }} · enters {{ $student->entry_level }}L ·
    <span class="badge {{ ['draft' => 'REMOVED', 'submitted' => 'EXPIRING', 'approved' => 'ACTIVE'][$evaluation->status] }}">{{ strtoupper($evaluation->status) }}</span>
    · <a href="{{ route('admin.transfers.index') }}">All transfers</a> · <a href="{{ route('admin.students.show', $student) }}">Student record</a></p>
@error('evaluation')<div class="alert bad">{{ $message }}</div>@enderror
@if($evaluation->note)<div class="alert warn"><strong>HoD note:</strong> {{ $evaluation->note }}</div>@endif
@php($editable = $evaluation->isDraft())

<h2>Previous institution</h2>
<div class="card" style="max-width:560px">
    @if($editable)
        <form method="POST" action="{{ route('admin.transfers.update', $student) }}">
            @csrf @method('PUT')
            <label for="previous_institution">Institution</label><input id="previous_institution" name="previous_institution" type="text" value="{{ old('previous_institution', $evaluation->previous_institution) }}" required maxlength="200">
            @error('previous_institution')<div class="error">{{ $message }}</div>@enderror
            <label for="previous_programme">Programme / department</label><input id="previous_programme" name="previous_programme" type="text" value="{{ old('previous_programme', $evaluation->previous_programme) }}" maxlength="200">
            <p><button class="btn">Save</button></p>
        </form>
    @else
        {{ $evaluation->previous_institution }}@if($evaluation->previous_programme), {{ $evaluation->previous_programme }}@endif
    @endif
</div>

<h2>Transfer documents</h2>
<div class="table-wrap"><table>
    @forelse($evaluation->documents as $d)
        <tr><td><a href="{{ route('admin.transfers.documents.show', [$student, $d]) }}" target="_blank">{{ $d->original_name }}</a></td>
            <td>@if($editable)<form class="inline" method="POST" action="{{ route('admin.transfers.documents.delete', [$student, $d]) }}" data-confirm="Remove this document?">@csrf @method('DELETE')<button class="btn small danger">Remove</button></form>@endif</td></tr>
    @empty
        <tr><td style="color:var(--muted)">No documents yet.</td></tr>
    @endforelse
</table></div>
@if($editable)
    <form method="POST" action="{{ route('admin.transfers.documents.upload', $student) }}" enctype="multipart/form-data" class="card" style="max-width:560px;margin-top:10px">
        @csrf
        <label for="document">Add a transcript or letter (PDF, JPG or PNG, up to 5 MB)</label>
        <input id="document" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png" required>
        @error('document')<div class="error">{{ $message }}</div>@enderror
        <p><button class="btn small">Upload</button></p>
    </form>
@endif

<h2>Courses exempted</h2>
<p class="lead">The student will not take these courses again. Tick "counts as credit" only when the HoD allows the credit units to count toward the credits passed.</p>
<div class="table-wrap"><table>
    <tr><th>Our course</th><th>Units</th><th>Previous course</th><th>Grade</th><th>Counts as credit</th><th></th></tr>
    @forelse($evaluation->exemptions as $x)
        <tr>
            <td>{{ $x->course->label() }}: {{ $x->course->title }}</td><td>{{ $x->course->units }}</td>
            <td>{{ trim($x->previous_code.' '.$x->previous_title) ?: '–' }}</td><td>{{ $x->previous_grade ?: '–' }}</td>
            <td>{{ $x->counts_as_credit ? 'Yes' : 'No' }}</td>
            <td>@if($editable)<form class="inline" method="POST" action="{{ route('admin.transfers.exemptions.remove', [$student, $x]) }}">@csrf @method('DELETE')<button class="btn small danger">Remove</button></form>@endif</td>
        </tr>
    @empty
        <tr><td colspan="6" style="color:var(--muted)">No exemptions. The student must take the whole programme from the level they enter.</td></tr>
    @endforelse
</table></div>

@if($editable)
    <form method="POST" action="{{ route('admin.transfers.exemptions.add', $student) }}" class="card" style="max-width:640px;margin-top:10px">
        @csrf
        <strong>Add an exemption</strong>
        <label for="course_id">Our course</label>
        <select id="course_id" name="course_id" required><option value="">Select…</option>@foreach($courses as $c)<option value="{{ $c->id }}">{{ $c->level }}L · {{ $c->label() }}: {{ $c->title }} ({{ $c->units }}u)</option>@endforeach</select>
        @error('course_id')<div class="error">{{ $message }}</div>@enderror
        <div style="display:grid;grid-template-columns:120px 1fr 90px;gap:10px">
            <div><label for="previous_code">Their code</label><input id="previous_code" name="previous_code" type="text" maxlength="40"></div>
            <div><label for="previous_title">Their course title</label><input id="previous_title" name="previous_title" type="text" maxlength="200"></div>
            <div><label for="previous_grade">Grade</label><input id="previous_grade" name="previous_grade" type="text" maxlength="10"></div>
        </div>
        <label class="check"><input type="checkbox" name="counts_as_credit" value="1"> Counts as credit toward the credits passed</label>
        <p><button class="btn small">Add exemption</button></p>
    </form>

    <div class="card" style="max-width:560px;margin-top:18px">
        <strong>Send to the HoD</strong>
        <p class="meta">Needs the institution and at least one document. Once sent, the evaluation is locked.</p>
        <form method="POST" action="{{ route('admin.transfers.submit', $student) }}" data-confirm="Send this evaluation to the HoD? You cannot edit it afterwards.">@csrf<button class="btn gold">Submit for approval</button></form>
    </div>
@endif

@if($evaluation->status === 'submitted')
    @if($isHod)
        <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:18px">
            <div class="card"><strong>HoD approval</strong><p class="meta">Approval locks the evaluation for good and lets the student register.</p>
                <form method="POST" action="{{ route('admin.transfers.approve', $student) }}" data-confirm="Approve and lock this evaluation?">@csrf<button class="btn gold">Approve</button></form></div>
            <div class="card"><strong>Return to the EO</strong>
                <form method="POST" action="{{ route('admin.transfers.return', $student) }}">@csrf<input type="text" name="note" placeholder="Reason" required maxlength="200"><p><button class="btn small danger">Return</button></p></form></div>
        </div>
    @else
        <div class="card" style="margin-top:18px;color:var(--muted)">Waiting for the HoD's approval.</div>
    @endif
@endif
@endsection
