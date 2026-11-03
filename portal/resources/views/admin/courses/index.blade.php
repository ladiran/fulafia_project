@extends('layouts.portal')
@section('title', 'Courses')

@section('content')
<h1>Courses</h1>
<p class="lead">C = core (must be passed). E = elective. An elective linked to an area is mandatory for students in that area.</p>

<form method="GET" class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:14px">
    <div><label>Level</label><select name="level"><option value="">All</option>@foreach([100,200,300,400] as $l)<option value="{{ $l }}" @selected(request('level') == $l)>{{ $l }}L</option>@endforeach</select></div>
    <div><label>Semester</label><select name="semester"><option value="">All</option><option value="1" @selected(request('semester') == 1)>First</option><option value="2" @selected(request('semester') == 2)>Second</option></select></div>
    <div><label>Type</label><select name="type"><option value="">All</option><option value="C" @selected(request('type') == 'C')>Core</option><option value="E" @selected(request('type') == 'E')>Elective</option></select></div>
    <button class="btn">Filter</button>
    <a class="btn gold" href="{{ route('admin.courses.create') }}" style="margin-left:auto">Add course</a>
</form>

<div class="table-wrap"><table>
    <tr><th>Code</th><th>Title</th><th>Units</th><th>Level</th><th>Sem.</th><th>Type</th><th>Area</th><th></th></tr>
    @forelse($courses as $c)
        <tr>
            <td><strong>{{ $c->code }}</strong> @if($c->is_gst)<span class="badge REMOVED">GST</span>@endif @unless($c->is_active)<span class="badge EXPIRED">inactive</span>@endunless</td>
            <td>{{ $c->title }}</td>
            <td>{{ $c->units }}</td>
            <td>{{ $c->level }}L</td>
            <td>{{ $c->semester }}</td>
            <td>{{ $c->type }}</td>
            <td>{{ $c->specialization?->name ?? '–' }}</td>
            <td><a class="btn small" href="{{ route('admin.courses.edit', $c) }}">Edit</a></td>
        </tr>
    @empty
        <tr><td colspan="8" style="color:var(--muted)">No courses match.</td></tr>
    @endforelse
</table></div>
@endsection
