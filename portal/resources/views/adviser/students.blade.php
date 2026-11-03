@extends('layouts.portal')
@section('title', $appointment->level.'L students')

@section('content')
<h1>{{ $appointment->level }}L students</h1>
<p class="lead">You are the Level Adviser for {{ $appointment->level }} level (tenure ends {{ $appointment->ends_on->format('j M Y') }}).</p>

<div class="table-wrap"><table>
    <tr><th>Matric no.</th><th>Name</th><th>Status</th><th>Pending courses</th><th></th></tr>
    @forelse($students as $s)
        <tr>
            <td>{{ $s->user->identifier }}</td>
            <td>{{ $s->user->name }}</td>
            <td>{{ ucfirst($s->status) }}</td>
            <td>@if($s->pending_count)<span class="badge EXPIRING">{{ $s->pending_count }} pending</span>@else –@endif</td>
            <td><a class="btn small" href="{{ route('adviser.students.show', $s) }}">Open</a></td>
        </tr>
    @empty
        <tr><td colspan="5" style="color:var(--muted)">No students at this level.</td></tr>
    @endforelse
</table></div>
@endsection
