@extends('layouts.public')
@section('title', 'Two-factor login')

@section('content')
<div class="card" style="max-width:420px;margin:0 auto">
    <h1>Two-factor login</h1>
    <p class="lead">Enter the 6-digit code from your authenticator app.</p>
    <form method="POST" action="{{ route('two-factor.challenge') }}">
        @csrf
        <label for="code">Code</label>
        <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="10" autofocus>
        @error('code')<div class="error">{{ $message }}</div>@enderror
        <p><button class="btn">Verify</button></p>

        <details style="margin-top:14px">
            <summary>Lost your phone? Use a recovery code</summary>
            <label for="recovery_code">Recovery code</label>
            <input id="recovery_code" name="recovery_code" type="text" autocomplete="off" maxlength="30" placeholder="xxxxx-xxxxx">
            <p><button class="btn small">Use recovery code</button></p>
        </details>
    </form>
    <p><a href="{{ route('login') }}">Start again</a></p>
</div>
@endsection
