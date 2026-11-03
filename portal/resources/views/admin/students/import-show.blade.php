@extends('layouts.portal')
@section('title', 'Import check')

@section('content')
<h1>{{ $import->status === 'done' ? 'Import finished' : 'Check your file' }}</h1>
<p class="lead">{{ $import->original_name }} · uploaded {{ $import->created_at->format('j M Y, H:i') }} · <a href="{{ route('admin.students.import') }}">Back to import</a></p>

<div class="grid">
    <div class="card"><div class="stat">{{ $import->total_rows }}</div>rows in the file</div>
    <div class="card"><div class="stat">{{ $import->status === 'done' ? $import->created_count : $import->valid_rows }}</div>{{ $import->status === 'done' ? 'accounts created' : 'ready to import' }}</div>
    <div class="card"><div class="stat" style="color:{{ $import->error_rows ? '#a12626' : 'var(--maroon)' }}">{{ $import->error_rows }}</div>with problems (skipped)</div>
</div>

@if($import->status === 'previewed')
    @if($import->valid_rows > 0)
        <div class="card" style="margin-top:14px;max-width:640px">
            <strong>Create {{ $import->valid_rows }} student account(s)?</strong>
            <p class="meta">Rows with problems are skipped. You can fix them and upload them again afterwards. You will get the temporary passwords as a file you can download once.</p>
            <form method="POST" action="{{ route('admin.students.import.confirm', $import) }}" data-confirm="Create {{ $import->valid_rows }} student account(s) now?">@csrf<button class="btn gold">Import {{ $import->valid_rows }} student(s)</button></form>
        </div>
    @else
        <div class="alert bad" style="margin-top:14px">No row can be imported. Fix the problems below and upload the file again.</div>
    @endif

    @if($preview)
        <h2>First rows that will be created</h2>
        <div class="table-wrap"><table>
            <tr><th>Line</th><th>Matric no.</th><th>Name</th><th>Mode</th><th>Entry</th><th>Level now</th></tr>
            @foreach($preview as $line => $r)
                <tr><td>{{ $line }}</td><td>{{ $r['matric'] }}</td><td>{{ $r['name'] }}</td><td>{{ \App\Models\StudentProfile::ENTRY_MODES[$r['entry_mode']] }}</td><td>{{ $r['entry_level'] }}L</td><td>{{ $r['current_level'] }}L</td></tr>
            @endforeach
        </table></div>
    @endif
@else
    <div class="card" style="margin-top:14px;max-width:640px">
        @if($import->credentials_path)
            <strong>Temporary passwords</strong>
            <p>Download them now. The file can be downloaded <strong>once</strong>, then it is deleted. Give each student their password privately; they must change it at their first sign-in.</p>
            <a class="btn gold" href="{{ route('admin.students.import.credentials', $import) }}">Download temporary passwords (CSV)</a>
        @elseif($import->credentials_downloaded_at)
            <strong>The temporary passwords were downloaded</strong> on {{ $import->credentials_downloaded_at->format('j M Y, H:i') }}. If a student lost theirs, use "Reset password" on their page.
        @elseif($import->created_count === 0)
            No accounts were created.
        @else
            The temporary-password file has expired. Use "Reset password" on a student's page to give a new one.
        @endif
    </div>
@endif

@if($import->error_rows > 0)
    <h2>Problems ({{ $import->error_rows }})</h2>
    <p><a href="{{ route('admin.students.import.errors', $import) }}">Download the list of problems (CSV)</a>@if($import->error_rows > 500) · showing the first 500 @endif</p>
    <div class="table-wrap"><table>
        <tr><th>Line</th><th>Matric no.</th><th>Problem</th></tr>
        @foreach(array_slice($import->errors ?? [], 0, 100) as $e)
            <tr><td>{{ $e['line'] }}</td><td>{{ $e['matric'] }}</td><td>{{ implode(' ', $e['messages']) }}</td></tr>
        @endforeach
    </table></div>
@endif
@endsection
