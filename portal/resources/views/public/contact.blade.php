@extends('layouts.public')
@section('title', 'Contact')

@section('content')
<h1>Contact the department</h1>
<div class="grid">
    <div class="card">
        <strong>{{ config('portal.department') }}</strong><br>
        {{ config('portal.faculty') }}<br>
        {{ config('portal.university') }}<br><br>
        {{ \App\Models\SiteSetting::get('address') }}<br>
        Phone: {{ \App\Models\SiteSetting::get('phone') }}<br>
        @if(\App\Models\SiteSetting::get('email'))Email: {{ \App\Models\SiteSetting::get('email') }}<br>@endif
        Office hours: {{ \App\Models\SiteSetting::get('office_hours') }}<br><br>
        <a href="https://www.openstreetmap.org/search?query={{ urlencode(\App\Models\SiteSetting::get('address')) }}" target="_blank" rel="noopener">View on a map</a>
    </div>
    <div class="card">
        <form method="POST" action="{{ route('contact.send') }}">
            @csrf
            <label for="name">Your name</label><input id="name" name="name" type="text" value="{{ old('name') }}" required maxlength="120">
            @error('name')<div class="error">{{ $message }}</div>@enderror
            <label for="email">Your email</label><input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="150">
            @error('email')<div class="error">{{ $message }}</div>@enderror
            <label for="message">Message</label>
            <textarea id="message" name="message" rows="5" required maxlength="3000" style="width:100%;padding:8px;border:1px solid #c9c3b3;border-radius:6px">{{ old('message') }}</textarea>
            @error('message')<div class="error">{{ $message }}</div>@enderror
            <div class="hp" aria-hidden="true"><label>Leave this empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
            <p><button class="btn">Send message</button></p>
        </form>
    </div>
</div>
@endsection
