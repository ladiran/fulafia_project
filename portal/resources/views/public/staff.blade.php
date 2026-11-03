@extends('layouts.public')
@section('title', 'Staff')

@section('content')
<h1>Staff</h1>
@forelse($groups as $category => $members)
    <h2>{{ \App\Models\StaffMember::CATEGORIES[$category] }}</h2>
    <div class="cards">
        @foreach($members as $m)
            <a class="card staff-card" href="{{ route('staff.member', $m) }}" style="text-decoration:none;color:inherit">
                @if($m->photo)<img class="avatar" src="{{ $m->photo->url() }}" alt="" loading="lazy">@else<span class="avatar"></span>@endif
                <span><strong>{{ $m->fullName() }}</strong><br><span class="meta">{{ $m->subtitle() }}</span></span>
            </a>
        @endforeach
    </div>
@empty
    <div class="card" style="color:var(--muted)">The staff directory is being prepared.</div>
@endforelse
@endsection
