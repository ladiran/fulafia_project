@extends('layouts.public')
@section('title', 'Forgot password')

@section('content')
<div class="card" style="max-width:420px;margin:0 auto">
    <h1>Forgot your password?</h1>
    <p class="lead">Staff: enter your email and we will send a reset link. Students: ask the department office, who can give you a new temporary password.</p>
    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="150" autofocus>
        @error('email')<div class="error">{{ $message }}</div>@enderror
        <p><button class="btn">Send reset link</button></p>
    </form>
    <p><a href="{{ route('login') }}">Back to sign in</a></p>
</div>
@endsection
