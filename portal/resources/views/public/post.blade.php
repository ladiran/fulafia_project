@extends('layouts.public')
@section('title', $post->title)

@section('content')
<p class="meta"><a href="{{ route('news') }}">News and announcements</a></p>
<h1>{{ $post->title }}</h1>
<p class="meta">{{ \App\Models\Post::TYPES[$post->type] }} · {{ $post->published_at->format('j F Y') }}</p>
@if($post->image)<img src="{{ $post->image->url() }}" alt="" style="max-width:100%;border-radius:10px;margin-bottom:14px">@endif
<div class="card prose">{!! \App\Support\Markdown::render($post->body) !!}</div>
@endsection
