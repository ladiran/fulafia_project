@extends('layouts.portal')
@section('title', 'Name change request')

@section('content')
<h1>Name change request</h1>
<p class="lead"><a href="{{ route('admin.name-changes.index') }}">All requests</a> · <a href="{{ route('admin.students.show', $req->student) }}">Student record</a></p>
@error('note')<div class="alert bad">{{ $message }}</div>@enderror

<div class="grid">
    <div class="card">
        {{ $req->student->user->identifier }} (gender: {{ $req->student->gender }})<br>
        Current name: <strong>{{ $req->current_name }}</strong><br>
        Requested name: <strong>{{ $req->new_name }}</strong><br>
        Status: <span class="badge {{ ['submitted' => 'EXPIRING', 'verified' => 'EXPIRING', 'approved' => 'ACTIVE', 'rejected' => 'EXPIRED'][$req->status] }}">{{ strtoupper($req->status) }}</span>
        @if($req->note)<br>Note: {{ $req->note }}@endif
    </div>
    <div class="card">
        <strong>Documents</strong><br>
        <a href="{{ route('admin.name-changes.document', [$req, 'marriage']) }}" target="_blank">{{ \App\Models\NameChangeRequest::DOCUMENT_TYPES[$req->document_type] }}</a><br>
        <a href="{{ route('admin.name-changes.document', [$req, 'newspaper']) }}" target="_blank">Newspaper advertisement</a>
    </div>
</div>

<div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:18px">
    @if($req->status === 'submitted')
        <div class="card"><strong>Verify the documents</strong>
            <form method="POST" action="{{ route('admin.name-changes.verify', $req) }}">@csrf
                <input type="hidden" name="decision" value="ok"><p><button class="btn">Documents are in order</button></p></form>
            <form method="POST" action="{{ route('admin.name-changes.verify', $req) }}">@csrf
                <input type="hidden" name="decision" value="reject"><input type="text" name="note" placeholder="Reason for rejecting" maxlength="200">
                <p><button class="btn small danger">Reject</button></p></form></div>
    @endif
    @if($req->status === 'verified' && $isHod)
        <div class="card"><strong>HoD decision</strong>
            <form method="POST" action="{{ route('admin.name-changes.approve', $req) }}" data-confirm="Approve this change of name?">@csrf<p><button class="btn gold">Approve</button></p></form>
            <form method="POST" action="{{ route('admin.name-changes.reject', $req) }}">@csrf<input type="text" name="note" placeholder="Reason" required maxlength="200"><p><button class="btn small danger">Reject</button></p></form></div>
    @endif
    @if($req->status === 'verified' && ! $isHod)<div class="card" style="color:var(--muted)">Documents verified. Waiting for the HoD's decision.</div>@endif
</div>
@endsection
