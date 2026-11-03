@extends('layouts.portal')
@section('title', 'Contact messages')

@section('content')
<h1>Contact form messages</h1>
@include('admin.content._nav')
@forelse($messages as $m)
    <div class="card" style="margin-bottom:10px">
        <strong>{{ $m->name }}</strong> <a href="mailto:{{ $m->email }}">{{ $m->email }}</a>
        @unless($m->is_read)<span class="badge EXPIRING">NEW</span>@endunless
        <div class="meta">{{ $m->created_at->format('j M Y, g:i A') }}</div>
        <p style="white-space:pre-line">{{ $m->message }}</p>
        <div style="display:flex;gap:8px">
            @unless($m->is_read)<form method="POST" action="{{ route('admin.messages.read', $m) }}">@csrf<button class="btn small">Mark as read</button></form>@endunless
            <form method="POST" action="{{ route('admin.messages.delete', $m) }}" data-confirm="Delete this message?">@csrf @method('DELETE')<button class="btn small danger">Delete</button></form>
        </div>
    </div>
@empty
    <div class="card" style="color:var(--muted)">No messages yet.</div>
@endforelse
@endsection
