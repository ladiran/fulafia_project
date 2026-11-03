@extends('layouts.portal')
@section('title', 'Graduates')

@section('content')
<h1>Graduates</h1>
<p class="lead">The permanent register. These records cannot be edited or deleted.</p>

<form method="GET" class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:14px">
    <div style="flex:1;min-width:200px"><label>Search name or matric no.</label><input type="text" name="q" value="{{ request('q') }}"></div>
    <div><label>Year</label><input type="text" name="year" value="{{ request('year') }}" style="width:90px"></div>
    <button class="btn">Filter</button>
</form>

<div class="table-wrap"><table>
    <tr><th>Matric no.</th><th>Name</th><th>Year</th><th>CGPA</th><th>Class</th><th></th></tr>
    @forelse($graduates as $g)
        <tr><td>{{ $g->matric }}</td><td>{{ $g->name }}</td><td>{{ $g->graduation_year }}</td><td>{{ number_format($g->cgpa, 2) }}</td><td>{{ $g->class_of_degree }}</td>
            <td><a class="btn small" href="{{ route('admin.graduates.show', $g) }}">Open</a></td></tr>
    @empty
        <tr><td colspan="6" style="color:var(--muted)">No graduates yet.</td></tr>
    @endforelse
</table></div>
<p>{{ $graduates->links() }}</p>
@endsection
