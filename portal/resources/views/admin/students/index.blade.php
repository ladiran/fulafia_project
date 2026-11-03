@extends('layouts.portal')
@section('title', 'Students')

@section('content')
<h1>Students</h1>

<form method="GET" class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:14px">
    <div style="flex:1;min-width:200px"><label>Search name or matric no.</label><input type="text" name="q" value="{{ request('q') }}"></div>
    <div><label>Level</label><select name="level"><option value="">All</option>@foreach([100,200,300,400] as $l)<option value="{{ $l }}" @selected(request('level') == $l)>{{ $l }}L</option>@endforeach</select></div>
    <div><label>Status</label><select name="status"><option value="">All</option>@foreach(\App\Models\StudentProfile::STATUSES as $s)<option value="{{ $s }}" @selected(request('status') == $s)>{{ ucfirst($s) }}</option>@endforeach</select></div>
    <button class="btn">Filter</button>
    <a class="btn gold" href="{{ route('admin.students.create') }}">Add student</a>
    <a class="btn light" style="border:1px solid var(--line)" href="{{ route('admin.students.import') }}">Import CSV</a>
</form>

<div class="table-wrap"><table>
    <tr><th>Matric no.</th><th>Name</th><th>Level</th><th>Entry</th><th>Status</th><th></th></tr>
    @forelse($students as $s)
        <tr>
            <td>{{ $s->user->identifier }}</td>
            <td>{{ $s->user->name }}</td>
            <td>{{ $s->current_level }}L</td>
            <td>{{ \App\Models\StudentProfile::ENTRY_MODES[$s->entry_mode] ?? $s->entry_mode }}</td>
            <td>{{ ucfirst($s->status) }}</td>
            <td><a class="btn small" href="{{ route('admin.students.show', $s) }}">Open</a></td>
        </tr>
    @empty
        <tr><td colspan="6" style="color:var(--muted)">No students match.</td></tr>
    @endforelse
</table></div>
<p>{{ $students->links() }}</p>
@endsection
