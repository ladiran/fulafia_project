@extends('layouts.public')
@section('title', 'Reset password')

@section('content')
<div class="card" style="max-width:420px;margin:0 auto">
    <h1>Choose a new password</h1>
    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required maxlength="150">
        @error('email')<div class="error">{{ $message }}</div>@enderror
        <label for="password">New password</label>
        <input id="password" name="password" type="password" required autocomplete="new-password">
        @error('password')<div class="error">{{ $message }}</div>@enderror
        <p style="color:var(--muted);font-size:.9rem;margin:4px 0">At least 10 characters, with upper and lower case letters and a number.</p>
        <label for="password_confirmation">Repeat the new password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
        <p><button class="btn">Reset password</button></p>
    </form>
</div>
@endsection
