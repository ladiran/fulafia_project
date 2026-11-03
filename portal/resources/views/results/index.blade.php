@extends('layouts.portal')
@section('title', 'Results')

@php($badge = ['draft' => 'REMOVED', 'submitted' => 'EXPIRING', 'vetted' => 'EXPIRING', 'approved' => 'ACTIVE', 'published' => 'ACTIVE'])

@section('content')
<h1>Results</h1>

@if(! $session)
    <div class="alert warn">No academic session is set up yet.</div>
@elseif($manager)
    <p class="lead">{{ $session->name }} · {{ $session->current_semester == 1 ? 'First' : 'Second' }} semester. Assign lecturers, then open a course to enter, vet, approve or publish its results.</p>
    <div class="table-wrap"><table>
        <tr><th>Course</th><th>Title</th><th>Lecturer</th><th>Sheet</th><th></th></tr>
        @foreach($courses as $c)
            @php($o = $offerings->get($c->id))
            <tr>
                <td>{{ $c->label() }}<br><small>{{ $c->level }}L</small></td>
                <td>{{ $c->title }}</td>
                <td>
                    <form class="inline" method="POST" action="{{ route('results.assign', $c) }}" style="display:flex;gap:6px">
                        @csrf
                        <select name="lecturer_id" style="min-width:150px">
                            <option value="">Unassigned</option>
                            @foreach($lecturers as $l)<option value="{{ $l->id }}" @selected($o?->lecturer_id == $l->id)>{{ $l->name }}</option>@endforeach
                        </select>
                        <button class="btn small">Set</button>
                    </form>
                </td>
                <td>@if($o)<span class="badge {{ $badge[$o->status] }}">{{ strtoupper($o->status) }}</span>@else –@endif</td>
                <td>
                    @if($o)<a class="btn small" href="{{ route('results.show', $o) }}">Open</a>
                    @else<form class="inline" method="POST" action="{{ route('results.open', $c) }}">@csrf<button class="btn small light" style="border:1px solid var(--line)">Open</button></form>@endif
                </td>
            </tr>
        @endforeach
    </table></div>
@else
    <p class="lead">Courses assigned to you in {{ $session->name }}.</p>
    <div class="table-wrap"><table>
        <tr><th>Course</th><th>Title</th><th>Sheet</th><th></th></tr>
        @forelse($offerings as $o)
            <tr>
                <td>{{ $o->course->label() }}</td>
                <td>{{ $o->course->title }}</td>
                <td><span class="badge {{ $badge[$o->status] }}">{{ strtoupper($o->status) }}</span></td>
                <td><a class="btn small" href="{{ route('results.show', $o) }}">Open</a></td>
            </tr>
        @empty
            <tr><td colspan="4" style="color:var(--muted)">No courses have been assigned to you yet.</td></tr>
        @endforelse
    </table></div>
@endif
@endsection
