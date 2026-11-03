@extends('layouts.portal')
@section('title', 'Change of name')

@section('content')
<h1>Change of name on marriage</h1>
<p class="lead">Upload your marriage certificate (or an affidavit) and a copy of the newspaper advertisement of the change of name. The EO verifies the documents and the HoD decides. Your matric number does not change.</p>

@if($blocked)
    <div class="alert warn">{{ $blocked }}</div>
@else
    <div class="card" style="max-width:560px">
        <form method="POST" action="{{ route('student.name-change.submit') }}" enctype="multipart/form-data">
            @csrf
            <label>Current name</label>
            <input type="text" value="{{ auth()->user()->name }}" disabled>

            <label for="new_name">New full name</label>
            <input id="new_name" name="new_name" type="text" value="{{ old('new_name') }}" required maxlength="150">
            @error('new_name')<div class="error">{{ $message }}</div>@enderror

            <label for="document_type">Marriage document</label>
            <select id="document_type" name="document_type">@foreach(\App\Models\NameChangeRequest::DOCUMENT_TYPES as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select>
            <input type="file" name="marriage_document" accept=".pdf,.jpg,.jpeg,.png" required style="margin-top:6px">
            @error('marriage_document')<div class="error">{{ $message }}</div>@enderror

            <label for="newspaper_advert">Newspaper advertisement (copy)</label>
            <input id="newspaper_advert" type="file" name="newspaper_advert" accept=".pdf,.jpg,.jpeg,.png" required>
            @error('newspaper_advert')<div class="error">{{ $message }}</div>@enderror
            <p style="color:var(--muted);font-size:.9rem">PDF, JPG or PNG, up to 4 MB each. Only the EO and HoD can see these files.</p>

            <p><button class="btn">Submit request</button></p>
        </form>
    </div>
@endif

@if($requests->isNotEmpty())
    <h2>My requests</h2>
    <div class="table-wrap"><table>
        <tr><th>Submitted</th><th>New name</th><th>Status</th><th>Note</th></tr>
        @foreach($requests as $r)
            <tr><td>{{ $r->created_at->format('j M Y') }}</td><td>{{ $r->new_name }}</td>
                <td><span class="badge {{ ['submitted' => 'EXPIRING', 'verified' => 'EXPIRING', 'approved' => 'ACTIVE', 'rejected' => 'EXPIRED'][$r->status] }}">{{ strtoupper($r->status) }}</span></td>
                <td>{{ $r->note }}</td></tr>
        @endforeach
    </table></div>
@endif
@endsection
