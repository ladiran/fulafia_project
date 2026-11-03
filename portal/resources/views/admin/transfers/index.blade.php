@extends('layouts.portal')
@section('title', 'Transfer students')

@section('content')
<h1>Transfer students</h1>
<p class="lead">Each transfer student needs a credit evaluation before they can register. The EO records the previous work and uploads the documents, then the HoD approves. Approval locks it.</p>

<div class="table-wrap"><table>
    <tr><th>Matric no.</th><th>Name</th><th>Level</th><th>Evaluation</th><th></th></tr>
    @forelse($students as $s)
        @php($e = $s->transferEvaluation)
        <tr>
            <td>{{ $s->user->identifier }}</td><td>{{ $s->user->name }}</td><td>{{ $s->current_level }}L</td>
            <td><span class="badge {{ ['draft' => 'REMOVED', 'submitted' => 'EXPIRING', 'approved' => 'ACTIVE'][$e?->status ?? 'draft'] }}">{{ $e ? strtoupper($e->status) : 'NOT STARTED' }}</span></td>
            <td><a class="btn small" href="{{ route('admin.transfers.show', $s) }}">Open</a></td>
        </tr>
    @empty
        <tr><td colspan="5" style="color:var(--muted)">No transfer students yet. Add one under Students with the Transfer admission mode.</td></tr>
    @endforelse
</table></div>
@endsection
