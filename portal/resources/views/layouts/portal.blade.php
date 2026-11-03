<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Portal') | {{ config('portal.department') }}</title>
    <link rel="icon" href="{{ asset('images/fulafia-logo.png') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <script src="{{ asset('js/app.js') }}" defer></script>
</head>
<body>
@php($me = auth()->user())
<header class="site-header">
    <div class="wrap">
        <a class="brand" href="{{ route('dashboard') }}">
            <img src="{{ asset('images/fulafia-logo-white.png') }}" alt="FULafia logo">
            <span><strong>{{ config('portal.department') }}</strong><small>Portal · {{ $me->name }}</small></span>
        </a>
        <nav class="nav">
            <a href="{{ route('dashboard') }}">Dashboard</a>
            @if($me->hasPermission('manage_content'))<a href="{{ route('admin.content') }}">Site content</a>@endif
            @if($me->hasPermission('manage_courses'))<a href="{{ route('admin.courses.index') }}">Courses</a>@endif
            @if($me->hasPermission('manage_students'))<a href="{{ route('admin.students.index') }}">Students</a>@endif
            @if($me->isPrincipalAdmin() || $me->role === 'secondary_admin')
                <a href="{{ route('admin.accounts.index') }}">Staff accounts</a>
                <a href="{{ route('admin.sessions') }}">Sessions</a>
                <a href="{{ route('admin.graduation.index') }}">Graduation</a>
                <a href="{{ route('admin.graduates.index') }}">Graduates</a>
                <a href="{{ route('admin.corrections.index') }}">Corrections</a>
                <a href="{{ route('admin.deferments.index') }}">Deferments</a>
                <a href="{{ route('admin.transfers.index') }}">Transfers</a>
                <a href="{{ route('admin.name-changes.index') }}">Name changes</a>
                <a href="{{ route('admin.enquiries.index') }}">Enquiries</a>
            @endif
            @if($me->isPrincipalAdmin())
                <a href="{{ route('admin.specializations') }}">Specializations</a>
                <a href="{{ route('admin.advisers') }}">Level Advisers</a>
                <a href="{{ route('admin.admins') }}">Admins</a>
            @endif
            @if($me->role === 'lecturer' && $me->currentAdviserAppointment())
                <a href="{{ route('adviser.students') }}">My level</a>
            @endif
            @if($me->hasPermission('manage_results') || $me->role === 'lecturer')<a href="{{ route('results.index') }}">Results</a>@endif
            @if($me->hasPermission('manage_results'))<a href="{{ route('admin.broadsheets') }}">Broadsheets</a>@endif
            @if($me->role === 'alumni')<a href="{{ route('student.statement') }}">My statement</a>@endif
            @if($me->role === 'student')
                <a href="{{ route('student.registration') }}">Registration</a>
                <a href="{{ route('student.results') }}">Results</a>
                <a href="{{ route('student.enquiries') }}">Enquiries</a>
                @if($me->studentProfile?->gender === 'female')<a href="{{ route('student.name-change') }}">Change of name</a>@endif
            @endif
            <a href="{{ route('security.password') }}">Security</a>
            <a href="{{ route('home') }}">Public site</a>
            <form class="inline" method="POST" action="{{ route('logout') }}">@csrf<button>Log out</button></form>
        </nav>
    </div>
</header>

<main>
    <div class="wrap">
        @if(session('status'))<div class="alert ok">{{ session('status') }}</div>@endif

        @foreach($adviserAlerts as $a)
            @php($mine = $a->user_id === $me->id)
            <div class="alert {{ $a->isExpired() ? 'bad' : 'warn' }}">
                <strong>Level Adviser tenure {{ $a->isExpired() ? 'expired' : 'ending soon' }}.</strong>
                {{ $mine ? 'Your tenure as '.$a->level.'L Level Adviser' : $a->user->name.' ('.$a->level.'L Level Adviser)' }}
                {{ $a->isExpired() ? 'ended' : 'ends' }} on {{ $a->ends_on->format('j M Y') }}.
                @if($me->isPrincipalAdmin()) <a href="{{ route('admin.advisers') }}">Renew or remove</a> @endif
            </div>
        @endforeach

        @if($me->unreadNotifications->isNotEmpty())
            <div class="card" style="margin-bottom:14px">
                <strong>Notifications</strong>
                <ul>@foreach($me->unreadNotifications as $n)<li>{{ $n->data['message'] }}</li>@endforeach</ul>
                <form method="POST" action="{{ route('notifications.read') }}">@csrf<button class="btn small light" style="border:1px solid var(--line)">Mark all as read</button></form>
            </div>
        @endif

        @yield('content')
    </div>
</main>
</body>
</html>
