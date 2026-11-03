@extends('layouts.public')
@section('title', $page->title)

@section('content')
<h1>{{ $page->title }}</h1>
<div class="card prose">{!! \App\Support\Markdown::render($page->body) !!}</div>
@endsection
