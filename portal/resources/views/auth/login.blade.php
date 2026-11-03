@extends('layouts.public')
@section('title', 'Portal login')

@section('content')
<div class="card" style="max-width:420px;margin:0 auto">
    <h1>Portal login</h1>
    <p class="lead">Students: use your matric number. Staff: use your staff ID or email.</p>
    <form method="POST" action="{{ route('login') }}">
        @csrf
        <label for="login">Matric number, staff ID or email</label>
        <input id="login" name="login" type="text" value="{{ old('login') }}" required autofocus autocomplete="username">
        @error('login')<div class="error">{{ $message }}</div>@enderror

        <label for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password">
        @error('password')<div class="error">{{ $message }}</div>@enderror

        <p><button class="btn" type="submit">Log in</button> <a href="{{ route('password.request') }}" style="margin-left:10px">Forgot password?</a></p>
    </form>
</div>
@endsection
