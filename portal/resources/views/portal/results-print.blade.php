<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Result slip: {{ $student->user->identifier }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <script src="{{ asset('js/app.js') }}" defer></script>
    <style>
        body { background: #fff; padding: 20px; }
        .slip-head { display: flex; align-items: center; gap: 16px; border-bottom: 3px solid var(--gold); padding-bottom: 10px; margin-bottom: 14px; }
        .slip-head img { height: 70px; }
        @media print { .no-print { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
<p class="no-print"><button class="btn" data-print>Print</button></p>
<div class="slip-head">
    <img src="{{ asset('images/fulafia-logo.png') }}" alt="">
    <div>
        <strong style="font-size:1.2rem">{{ config('portal.university') }}</strong><br>
        {{ config('portal.faculty') }} · {{ config('portal.department') }}<br>
        <strong>Statement of result</strong> (published results only)
    </div>
</div>
<p>
    <strong>{{ $student->user->name }}</strong> · Matric no.: {{ $student->user->identifier }} · Level: {{ $student->current_level }}L
    @if($student->specialization) · Specialization: {{ $student->specialization->name }}@endif
</p>
@include('partials.results-summary')
<p style="margin-top:20px;font-size:.85rem;color:var(--muted)">Generated {{ now()->format('j M Y, H:i') }}. This slip is not a transcript.</p>
</body>
</html>
