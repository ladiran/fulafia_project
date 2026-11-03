@extends('layouts.portal')
@section('title', 'Import students')

@section('content')
<h1>Import students from a CSV file</h1>
<p class="lead">Upload a spreadsheet saved as CSV. Nothing is created until you have seen the check and confirmed. Up to {{ $max }} students per file.
    <a href="{{ route('admin.students.import.template') }}">Download the template</a>.</p>

<div class="card" style="max-width:640px">
    <form method="POST" action="{{ route('admin.students.import.upload') }}" enctype="multipart/form-data">
        @csrf
        <label for="file">CSV file</label>
        <input id="file" name="file" type="file" accept=".csv,.txt" required>
        @error('file')<div class="error">{{ $message }}</div>@enderror

        <label for="default_session_id">Session admitted, for rows that leave it blank</label>
        <select id="default_session_id" name="default_session_id">
            <option value="">None: every row must give its session</option>
            @foreach($sessions as $s)<option value="{{ $s->id }}" @selected($s->is_current)>{{ $s->name }}{{ $s->is_current ? ' (current)' : '' }}</option>@endforeach
        </select>
        <p><button class="btn">Upload and check</button></p>
    </form>
</div>

<h2>Columns</h2>
<div class="table-wrap"><table>
    <tr><th>Column</th><th>Needed</th><th>What to put</th></tr>
    <tr><td>matric</td><td>Yes</td><td>The number issued by the university. Must be new.</td></tr>
    <tr><td>name</td><td>Yes</td><td>Full name.</td></tr>
    <tr><td>gender</td><td>Yes</td><td>male or female.</td></tr>
    <tr><td>entry_mode</td><td>Yes</td><td>regular, direct_entry or transfer.</td></tr>
    <tr><td>entry_level</td><td>Transfer only</td><td>100 or 200 for transfer students. Regular is always 100 and Direct Entry always 200.</td></tr>
    <tr><td>session</td><td>Unless a default is chosen</td><td>Session admitted, written like 2025/2026. It must already exist under Sessions.</td></tr>
    <tr><td>jamb_reg_no</td><td>Regular and Direct Entry</td><td>JAMB registration number.</td></tr>
    <tr><td>email, phone</td><td>No</td><td>Optional. Without an email the student signs in with the matric number.</td></tr>
    <tr><td>current_level</td><td>No</td><td>For students already past their entry level (100, 200, 300 or 400). Blank means the entry level.</td></tr>
    <tr><td>specialization</td><td>No</td><td>An existing area name, for 400 level students.</td></tr>
</table></div>
<p class="meta">Every new student gets a temporary password they must change at their first sign-in. Transfer students still need their credit evaluation before they can register.</p>

@if($history->isNotEmpty())
    <h2>Recent imports</h2>
    <div class="table-wrap"><table>
        <tr><th>When</th><th>By</th><th>File</th><th>Rows</th><th>Created</th><th>Skipped</th><th>Status</th><th></th></tr>
        @foreach($history as $h)
            <tr>
                <td>{{ $h->created_at->format('j M Y, H:i') }}</td><td>{{ $h->user->name }}</td><td>{{ $h->original_name }}</td>
                <td>{{ $h->total_rows }}</td><td>{{ $h->created_count }}</td><td>{{ $h->error_rows }}</td>
                <td><span class="badge {{ $h->status === 'done' ? 'ACTIVE' : 'EXPIRING' }}">{{ strtoupper($h->status === 'done' ? 'done' : 'not confirmed') }}</span></td>
                <td><a class="btn small" href="{{ route('admin.students.import.show', $h) }}">Open</a></td>
            </tr>
        @endforeach
    </table></div>
@endif
@endsection
