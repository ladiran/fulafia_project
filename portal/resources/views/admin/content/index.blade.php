@extends('layouts.portal')
@section('title', 'Site content')

@section('content')
<h1>Public site content</h1>
@include('admin.content._nav')
@if($unread)<div class="alert warn">{{ $unread }} unread message(s) from the contact form. <a href="{{ route('admin.messages') }}">Open</a></div>@endif

<div class="grid">
    <div class="card"><strong>Pages</strong><p>About, vision and mission, HoD's message, admission, programmes, alumni.</p><a class="btn small" href="{{ route('admin.pages.index') }}">Manage</a></div>
    <div class="card"><strong>News and announcements</strong><p>Post news, schedule it, pin important items.</p><a class="btn small" href="{{ route('admin.posts.index') }}">Manage</a></div>
    <div class="card"><strong>Events</strong><p>The department's calendar.</p><a class="btn small" href="{{ route('admin.events.index') }}">Manage</a></div>
    <div class="card"><strong>Staff directory</strong><p>Profiles, research interests and publications.</p><a class="btn small" href="{{ route('admin.staff.index') }}">Manage</a></div>
    <div class="card"><strong>Downloads</strong><p>Handbook, forms, timetables, past questions.</p><a class="btn small" href="{{ route('admin.downloads.index') }}">Manage</a></div>
    <div class="card"><strong>Gallery</strong><p>Photos of the department.</p><a class="btn small" href="{{ route('admin.gallery.index') }}">Manage</a></div>
</div>

<h2>Site settings</h2>
<div class="card" style="max-width:640px">
    <form method="POST" action="{{ route('admin.content.settings') }}">
        @csrf
        @foreach($keys as $key => $label)
            <label for="{{ $key }}">{{ $label }}</label>
            @if($key === 'hero_text')
                <textarea id="{{ $key }}" name="{{ $key }}" rows="3" style="width:100%;padding:8px;border:1px solid #c9c3b3;border-radius:6px">{{ old($key, \App\Models\SiteSetting::get($key)) }}</textarea>
            @else
                <input id="{{ $key }}" name="{{ $key }}" type="{{ $key === 'email' ? 'email' : 'text' }}" value="{{ old($key, \App\Models\SiteSetting::get($key)) }}">
            @endif
            @error($key)<div class="error">{{ $message }}</div>@enderror
        @endforeach
        <p><button class="btn">Save settings</button></p>
    </form>
</div>
@endsection
