@extends('layouts.public')
@section('title', 'Events')

@section('content')
<h1>Events</h1>
<h2>Upcoming</h2>
@forelse($upcoming as $e)
    <div class="card" style="margin-bottom:10px">
        <strong>{{ $e->title }}</strong>
        <div class="meta">{{ $e->starts_at->format('l j F Y, g:i A') }}@if($e->ends_at) to {{ $e->ends_at->isSameDay($e->starts_at) ? $e->ends_at->format('g:i A') : $e->ends_at->format('j F Y, g:i A') }}@endif
            @if($e->location) · {{ $e->location }}@endif</div>
        @if($e->description)<p>{{ $e->description }}</p>@endif
    </div>
@empty
    <div class="card" style="color:var(--muted)">No upcoming events.</div>
@endforelse

@if($past->isNotEmpty())
    <h2>Past events</h2>
    @foreach($past as $e)<div class="card" style="margin-bottom:8px"><strong>{{ $e->title }}</strong> <span class="meta">{{ $e->starts_at->format('j M Y') }}@if($e->location) · {{ $e->location }}@endif</span></div>@endforeach
@endif
@endsection
