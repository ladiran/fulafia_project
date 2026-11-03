@extends('layouts.public')
@section('title', 'Courses')

@section('content')
<h1>Course catalogue</h1>
<p class="lead">C = core course. E = elective. An elective linked to an area of specialization is compulsory for students in that area.</p>
@forelse($byLevel as $level => $courses)
    <h2>{{ $level }} level</h2>
    <div class="table-wrap"><table>
        <tr><th>Code</th><th>Title</th><th>Units</th><th>Semester</th><th>Type</th><th>Area</th></tr>
        @foreach($courses as $c)
            <tr><td>{{ $c->code }}</td><td>{{ $c->title }}</td><td>{{ $c->units }}</td><td>{{ $c->semester == 1 ? 'First' : 'Second' }}</td><td>{{ $c->type }}</td><td>{{ $c->specialization?->name ?? '–' }}</td></tr>
        @endforeach
    </table></div>
@empty
    <div class="card" style="color:var(--muted)">The catalogue is being prepared.</div>
@endforelse
@endsection
