@extends('layouts.portal')
@section('title', 'Change password')

@section('content')
<h1>Account security</h1>
<p class="lead"><strong>Password</strong> · <a href="{{ route('security.two-factor') }}">Two-factor login</a></p>

@if($forced)<div class="alert warn"><strong>You are using a temporary password.</strong> Choose your own password to continue.</div>@endif

<div class="card" style="max-width:460px">
    <form method="POST" action="{{ route('security.password.update') }}">
        @csrf
        <label for="current_password">Current password</label>
        <input id="current_password" name="current_password" type="password" required autocomplete="current-password">
        @error('current_password')<div class="error">{{ $message }}</div>@enderror

        <label for="password">New password</label>
        <input id="password" name="password" type="password" required autocomplete="new-password">
        @error('password')<div class="error">{{ $message }}</div>@enderror
        <p style="color:var(--muted);font-size:.9rem;margin:4px 0">At least 10 characters, with upper and lower case letters and a number. Use a password you do not use anywhere else.</p>

        <label for="password_confirmation">Repeat the new password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">

        <p><button class="btn">Change password</button></p>
    </form>
</div>
@if(auth()->user()->last_login_at)
    <p style="color:var(--muted);font-size:.9rem;margin-top:14px">Last sign-in: {{ auth()->user()->last_login_at->format('j M Y, g:i A') }} from {{ auth()->user()->last_login_ip }}.</p>
@endif
@endsection
