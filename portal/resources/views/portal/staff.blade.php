@extends('layouts.portal')
@section('title', 'Staff dashboard')

@section('content')
<h1>Staff dashboard</h1>
<p class="lead">Welcome, {{ auth()->user()->name }}.</p>

@if($appointment)
    <div class="card" style="margin-bottom:14px">
        <strong>Level Adviser, {{ $appointment->level }}L</strong>
        <span class="badge {{ $appointment->status() }}">{{ $appointment->status() }}</span>
        <p>Tenure: {{ $appointment->starts_on->format('j M Y') }} to {{ $appointment->ends_on->format('j M Y') }}.</p>
        <p><a class="btn" href="{{ route('adviser.students') }}">View {{ $appointment->level }}L students and approve courses</a></p>
    </div>
@endif

<div class="grid">
    <div class="card"><strong>Results</strong><p>Enter scores for the courses assigned to you.</p><a class="btn" href="{{ route('results.index') }}">My courses</a></div>
    <div class="card"><strong>Public profile</strong><p>Edit your research interests, bio and publications on the staff page.</p><a class="btn" href="{{ route('staff.profile') }}">Edit profile</a></div>
</div>
@endsection
