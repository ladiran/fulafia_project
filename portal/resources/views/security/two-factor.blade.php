@extends('layouts.portal')
@section('title', 'Two-factor login')

@section('content')
<h1>Account security</h1>
<p class="lead"><a href="{{ route('security.password') }}">Password</a> · <strong>Two-factor login</strong></p>

@if($recoveryCodes)
    <div class="alert warn">
        <strong>Save these recovery codes now.</strong> Each works once if you lose your phone. They are shown only this one time.
        <pre style="font-size:1.05rem;margin:10px 0">{{ implode("\n", $recoveryCodes) }}</pre>
    </div>
@endif

@if($enabled)
    <div class="card" style="max-width:560px">
        <strong>Two-factor login is on.</strong>
        <p>Recovery codes left: {{ $codesLeft }}.</p>

        <form method="POST" action="{{ route('security.two-factor.codes') }}">
            @csrf
            <label for="pw1">Make new recovery codes (the old ones stop working)</label>
            <input id="pw1" name="current_password" type="password" placeholder="Your password" required autocomplete="current-password">
            @error('current_password')<div class="error">{{ $message }}</div>@enderror
            <p><button class="btn small">New recovery codes</button></p>
        </form>

        @if($required)
            <p style="color:var(--muted)">Two-factor login is compulsory for admin accounts, so it cannot be turned off. If you lose your phone, use a recovery code, or ask the HoD to reset it.</p>
        @else
            <form method="POST" action="{{ route('security.two-factor.disable') }}" data-confirm="Turn off two-factor login?">
                @csrf
                <label for="pw2">Turn it off</label>
                <input id="pw2" name="current_password" type="password" placeholder="Your password" required autocomplete="current-password">
                <p><button class="btn small danger">Turn off</button></p>
            </form>
        @endif
    </div>
@else
    <div class="card" style="max-width:620px">
        <strong>Set up two-factor login</strong>
        @if($required)<p>This is compulsory for admin accounts.</p>@endif
        <ol>
            <li>Install an authenticator app (Google Authenticator, Microsoft Authenticator, Authy or 1Password).</li>
            <li>In the app choose <em>Add account</em>, then <em>Enter a setup key</em>, and type this key. Choose "time based".</li>
        </ol>
        <p style="font-size:1.2rem;letter-spacing:.12em;font-family:ui-monospace,monospace;background:#f1ece0;padding:10px;border-radius:6px;word-break:break-all">{{ trim(chunk_split($pending['secret'], 4, ' ')) }}</p>
        <p style="color:var(--muted);font-size:.9rem">On a phone you can also <a href="{{ $pending['uri'] }}">tap here to add it automatically</a>.</p>
        <form method="POST" action="{{ route('security.two-factor.confirm') }}">
            @csrf
            <label for="code">Then type the 6-digit code the app shows</label>
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="10" required style="max-width:200px">
            @error('code')<div class="error">{{ $message }}</div>@enderror
            <p><button class="btn">Turn on two-factor login</button></p>
        </form>
    </div>
@endif
@endsection
