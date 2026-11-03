@extends('layouts.public')

@section('content')
<section class="hero">
    <h1>{{ \App\Models\SiteSetting::get('hero_title') }}</h1>
    <p>{{ \App\Models\SiteSetting::get('hero_text') }}</p>
    <a class="btn gold" href="{{ route('login') }}">Student &amp; staff portal</a>
</section>

<div class="grid" style="margin-top:22px">
    <div class="card"><strong>Regular admission</strong><p>Entry at 100 level through JAMB.</p></div>
    <div class="card"><strong>Direct Entry</strong><p>Entry at 200 level through JAMB.</p></div>
    <div class="card"><strong>Transfer</strong><p>From other universities or departments, at 100 or 200 level.</p></div>
</div>

<h2>Latest news</h2>
@if($news->isEmpty())
    <div class="card" style="color:var(--muted)">No news yet.</div>
@else
    <div class="cards">@foreach($news as $post)@include('public._post-card')@endforeach</div>
    <p><a href="{{ route('news') }}">All news and announcements</a></p>
@endif

<h2>Upcoming events</h2>
@forelse($events as $event)
    <div class="card" style="margin-bottom:10px"><strong>{{ $event->title }}</strong>
        <div class="meta">{{ $event->starts_at->format('D j M Y, g:i A') }}@if($event->location) · {{ $event->location }}@endif</div></div>
@empty
    <div class="card" style="color:var(--muted)">No upcoming events.</div>
@endforelse
<p><a href="{{ route('events') }}">Event calendar</a></p>

@if($hod)
    <h2>{{ $hod->title }}</h2>
    <div class="card prose">{!! \App\Support\Markdown::render(\Illuminate\Support\Str::limit(strip_tags(\App\Support\Markdown::render($hod->body)), 420)) !!}
        <a href="{{ route('page', $hod->slug) }}">Read more</a></div>
@endif
@endsection
