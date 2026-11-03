@extends('layouts.portal')
@section('title', 'My results')

@section('content')
<h1>My results</h1>
<p class="lead">Published results only. <a href="{{ route('student.results.print') }}" target="_blank">Printable result slip</a></p>
@include('partials.results-summary')
@endsection
