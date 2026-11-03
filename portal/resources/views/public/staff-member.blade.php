@extends('layouts.public')
@section('title', $member->fullName())

@section('content')
<p class="meta"><a href="{{ route('staff.directory') }}">Staff</a></p>
<div class="staff-card" style="margin-bottom:14px">
    @if($member->photo)<img class="avatar" style="width:96px;height:96px" src="{{ $member->photo->url() }}" alt="">@endif
    <div><h1 style="margin:0">{{ $member->fullName() }}</h1><div class="meta">{{ $member->subtitle() }}@if($member->email) · <a href="mailto:{{ $member->email }}">{{ $member->email }}</a>@endif</div></div>
</div>
@if($member->research_interests)<div class="card" style="margin-bottom:12px"><strong>Research interests</strong><p>{{ $member->research_interests }}</p></div>@endif
@if($member->bio)<div class="card prose" style="margin-bottom:12px"><strong>About</strong>{!! \App\Support\Markdown::render($member->bio) !!}</div>@endif
@if($member->publications)<div class="card prose"><strong>Publications</strong>{!! \App\Support\Markdown::render($member->publications) !!}</div>@endif
@endsection
