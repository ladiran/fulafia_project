@extends('layouts.portal')
@section('title', 'Enquiries')

@section('content')
<h1>Enquiries</h1>
<p class="lead">Ask the department about deferment, a change of name, your results, or anything else. Deferment can only be started by the HoD after your enquiry.</p>

<div class="card" style="max-width:560px;margin-bottom:18px">
    <form method="POST" action="{{ route('student.enquiries.submit') }}">
        @csrf
        <label for="category">Topic</label>
        <select id="category" name="category">@foreach(\App\Models\Enquiry::CATEGORIES as $k => $label)<option value="{{ $k }}" @selected(old('category') == $k)>{{ $label }}</option>@endforeach</select>
        <label for="subject">Subject</label>
        <input id="subject" name="subject" type="text" maxlength="150" value="{{ old('subject') }}" required>
        @error('subject')<div class="error">{{ $message }}</div>@enderror
        <label for="message">Message</label>
        <textarea id="message" name="message" rows="4" style="width:100%;padding:8px;border:1px solid #c9c3b3;border-radius:6px" maxlength="2000" required>{{ old('message') }}</textarea>
        @error('message')<div class="error">{{ $message }}</div>@enderror
        <p><button class="btn">Send</button></p>
    </form>
</div>

@foreach($enquiries as $e)
    <div class="card" style="margin-bottom:10px">
        <strong>{{ $e->subject }}</strong> <span class="badge {{ $e->status === 'open' ? 'EXPIRING' : 'ACTIVE' }}">{{ strtoupper($e->status) }}</span>
        <div style="color:var(--muted);font-size:.9rem">{{ \App\Models\Enquiry::CATEGORIES[$e->category] }} · {{ $e->created_at->format('j M Y') }}</div>
        <p style="white-space:pre-line">{{ $e->message }}</p>
        @if($e->response)<div class="alert ok"><strong>Reply:</strong> {{ $e->response }}</div>@endif
    </div>
@endforeach
@endsection
