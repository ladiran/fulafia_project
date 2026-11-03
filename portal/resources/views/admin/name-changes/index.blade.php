@extends('layouts.portal')
@section('title', 'Name changes')

@section('content')
<h1>Change of name (marriage)</h1>
<p class="lead">Female students only. The EO verifies the documents, then the HoD approves. The old name stays in the student's permanent history.</p>

<div class="table-wrap"><table>
    <tr><th>Student</th><th>Current name</th><th>Requested name</th><th>Status</th><th>Submitted</th><th></th></tr>
    @forelse($requests as $r)
        <tr>
            <td>{{ $r->student->user->identifier }}</td>
            <td>{{ $r->current_name }}</td>
            <td>{{ $r->new_name }}</td>
            <td><span class="badge {{ ['submitted' => 'EXPIRING', 'verified' => 'EXPIRING', 'approved' => 'ACTIVE', 'rejected' => 'EXPIRED'][$r->status] }}">{{ strtoupper($r->status) }}</span></td>
            <td>{{ $r->created_at->format('j M Y') }}</td>
            <td><a class="btn small" href="{{ route('admin.name-changes.show', $r) }}">Open</a></td>
        </tr>
    @empty
        <tr><td colspan="6" style="color:var(--muted)">No requests yet.</td></tr>
    @endforelse
</table></div>
@endsection
