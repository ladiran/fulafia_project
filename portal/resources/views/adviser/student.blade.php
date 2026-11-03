@extends('layouts.portal')
@section('title', $student->user->name)

@section('content')
<h1>{{ $student->user->name }}</h1>
<p class="lead"><a href="{{ route('adviser.students') }}">Back to {{ $appointment->level }}L students</a></p>

@if($student->registrations->where('status', 'pending')->isNotEmpty())
    <form method="POST" action="{{ route('adviser.students.approve-all', $student) }}" style="margin-bottom:14px">@csrf<button class="btn gold">Approve all pending courses</button></form>
@endif

@php($reviewable = true)
@include('partials.student-detail')
@endsection
