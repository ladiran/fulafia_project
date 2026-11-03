@extends('layouts.public')
@section('title', 'Verify a graduate')

@section('content')
<div class="card" style="max-width:560px;margin:0 auto">
    <h1>Verify a graduate</h1>
    <p class="lead">Enter the verification code printed on the graduate's statement of result.</p>
    <form method="GET" action="{{ route('verify') }}">
        <label for="code">Verification code</label>
        <input id="code" name="code" type="text" value="{{ $code }}" required maxlength="20" autocomplete="off">
        <p><button class="btn">Verify</button></p>
    </form>

    @if($code !== '')
        @if($graduate)
            <div class="alert ok">
                <strong>Verified.</strong><br>
                {{ $graduate->name }} (matric no. {{ $graduate->matric }})<br>
                {{ $graduate->degree }}, {{ $graduate->class_of_degree }}<br>
                Graduated {{ $graduate->graduation_session }} session ({{ $graduate->graduation_year }})
            </div>
        @else
            <div class="alert bad">No graduate matches that code.</div>
        @endif
    @endif
</div>
@endsection
