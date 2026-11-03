<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('portal.department')) | {{ config('portal.university') }}</title>
    <link rel="icon" href="{{ asset('images/fulafia-logo.png') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <script src="{{ asset('js/app.js') }}" defer></script>
</head>
<body>
<header class="site-header">
    <div class="wrap">
        <a class="brand" href="{{ route('home') }}">
            <img src="{{ asset('images/fulafia-logo-white.png') }}" alt="FULafia logo">
            <span><strong>{{ config('portal.department') }}</strong><small>{{ config('portal.university') }}</small></span>
        </a>
        <nav class="nav">
            <a href="{{ route('home') }}">Home</a>
            @foreach(\App\Models\Page::MENU_GROUPS as $group => $label)
                @if(isset($menuGroups[$group]))
                    <div class="dropdown">
                        <a href="#" data-noop>{{ $label }} ▾</a>
                        <div class="dropdown-menu">@foreach($menuGroups[$group] as $p)<a href="{{ route('page', $p->slug) }}">{{ $p->title }}</a>@endforeach</div>
                    </div>
                @endif
            @endforeach
            <a href="{{ route('staff.directory') }}">Staff</a>
            <a href="{{ route('news') }}">News</a>
            <a href="{{ route('events') }}">Events</a>
            @foreach($menuPages as $p)<a href="{{ route('page', $p->slug) }}">{{ $p->title }}</a>@endforeach
            <a href="{{ route('courses') }}">Courses</a>
            <a href="{{ route('gallery') }}">Gallery</a>
            <a href="{{ route('downloads') }}">Downloads</a>
            <a href="{{ route('contact') }}">Contact</a>
            @auth
                <a class="active" href="{{ route('dashboard') }}">My portal</a>
            @else
                <a class="active" href="{{ route('login') }}">Portal login</a>
            @endauth
        </nav>
        <form class="search" method="GET" action="{{ route('search') }}" role="search">
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Search" aria-label="Search the site" maxlength="80">
        </form>
    </div>
</header>

<main>
    <div class="wrap">
        @if(session('status'))<div class="alert ok">{{ session('status') }}</div>@endif
        @yield('content')
    </div>
</main>

<footer>
    <div class="wrap">
        {{ config('portal.faculty') }}, {{ config('portal.university') }}<br>
        {{ \App\Models\SiteSetting::get('address') }} · {{ \App\Models\SiteSetting::get('phone') }}@if(\App\Models\SiteSetting::get('email')) · {{ \App\Models\SiteSetting::get('email') }}@endif<br>
        <a href="{{ route('verify') }}">Verify a graduate</a> · This portal belongs to the Department of Physics and is separate from the university portal.
    </div>
</footer>
</body>
</html>
