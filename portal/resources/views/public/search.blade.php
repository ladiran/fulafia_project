@extends('layouts.public')
@section('title', 'Search')

@section('content')
<h1>Search</h1>
<form method="GET" action="{{ route('search') }}" style="max-width:480px;display:flex;gap:8px">
    <input type="text" name="q" value="{{ $q }}" maxlength="80" required><button class="btn">Search</button>
</form>

@if($results === null)
    <p class="lead" style="margin-top:14px">Type at least two characters.</p>
@else
    @php($total = collect($results)->sum(fn ($r) => $r->count()))
    <p class="lead" style="margin-top:14px">{{ $total }} result(s) for "{{ $q }}".</p>
    @foreach([
        'pages' => ['Pages', fn ($r) => [route('page', $r->slug), $r->title]],
        'news' => ['News', fn ($r) => [route('post', $r->slug), $r->title]],
        'events' => ['Events', fn ($r) => [route('events'), $r->title.' ('.$r->starts_at->format('j M Y').')']],
        'staff' => ['Staff', fn ($r) => [route('staff.member', $r), $r->fullName()]],
        'courses' => ['Courses', fn ($r) => [route('courses'), $r->code.': '.$r->title]],
    ] as $key => [$label, $link])
        @if($results[$key]->isNotEmpty())
            <h2>{{ $label }}</h2>
            <ul>@foreach($results[$key] as $r)@php([$url, $text] = $link($r))<li><a href="{{ $url }}">{{ $text }}</a></li>@endforeach</ul>
        @endif
    @endforeach
@endif
@endsection
