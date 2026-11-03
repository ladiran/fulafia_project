@extends('layouts.public')
@section('title', 'News and announcements')

@section('content')
<h1>News and announcements</h1>
<p class="lead"><a href="{{ route('news') }}">All</a> · <a href="{{ route('news', ['type' => 'news']) }}">News</a> · <a href="{{ route('news', ['type' => 'announcement']) }}">Announcements</a></p>
@if($posts->isEmpty())
    <div class="card" style="color:var(--muted)">Nothing posted yet.</div>
@else
    <div class="cards">@foreach($posts as $post)@include('public._post-card')@endforeach</div>
    <div class="pager">{{ $posts->links() }}</div>
@endif
@endsection
