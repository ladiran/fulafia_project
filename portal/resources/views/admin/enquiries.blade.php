@extends('layouts.portal')
@section('title', 'Enquiries')

@section('content')
<h1>Student enquiries</h1>
@forelse($enquiries as $e)
    <div class="card" style="margin-bottom:12px">
        <strong>{{ $e->subject }}</strong>
        <span class="badge {{ $e->status === 'open' ? 'EXPIRING' : 'ACTIVE' }}">{{ strtoupper($e->status) }}</span>
        <div style="color:var(--muted);font-size:.9rem">{{ $e->student->user->name }} ({{ $e->student->user->identifier }}) · {{ \App\Models\Enquiry::CATEGORIES[$e->category] }} · {{ $e->created_at->format('j M Y') }}
            @if($e->category === 'deferment') · <a href="{{ route('admin.deferments.index', ['matric' => $e->student->user->identifier]) }}">Check deferment eligibility</a>@endif</div>
        <p style="white-space:pre-line">{{ $e->message }}</p>
        @if($e->response)
            <div class="alert ok"><strong>Reply:</strong> {{ $e->response }}</div>
        @else
            <form method="POST" action="{{ route('admin.enquiries.respond', $e) }}">@csrf
                <textarea name="response" rows="3" style="width:100%;padding:8px;border:1px solid #c9c3b3;border-radius:6px" required maxlength="2000" placeholder="Reply to the student"></textarea>
                <p><button class="btn small">Send reply and close</button></p></form>
        @endif
    </div>
@empty
    <div class="card" style="color:var(--muted)">No enquiries yet.</div>
@endforelse
@endsection
