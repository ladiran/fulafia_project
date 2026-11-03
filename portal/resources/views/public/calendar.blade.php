@extends('layouts.public')
@section('title', 'Academic calendar')

@section('content')
<h1>Academic calendar{{ $session ? ' '.$session->name : '' }}</h1>
<p class="lead">Dates for registration, lectures, examinations and result submission. The Federal University of Lafia and the University Senate remain the authority; confirm against official university notices.</p>
@forelse($events->reject(fn ($e) => in_array($e->category, ['faculty'], true)) as $e)
    <div class="card" style="margin-bottom:8px">
        <strong>{{ $e->title }}</strong>
        <div class="meta">{{ $e->dateLabel() }}</div>
    </div>
@empty
    <div class="card" style="color:var(--muted)">The calendar for this session has not been published yet.</div>
@endforelse
@endsection
