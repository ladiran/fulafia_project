@extends('layouts.portal')
@section('title', 'Edit student')

@section('content')
<h1>Edit {{ $student->user->name }}</h1>
<p class="lead">Matric number {{ $student->user->identifier }} cannot be changed here. Deferment, graduation and marriage name changes have their own approval flows.</p>

<div class="card" style="max-width:560px">
    <form method="POST" action="{{ route('admin.students.update', $student) }}">
        @csrf @method('PUT')

        <label for="name">Name @unless(auth()->user()->isPrincipalAdmin())<small>(only the HoD can correct a name)</small>@endunless</label>
        <input id="name" name="name" type="text" value="{{ old('name', $student->user->name) }}" @disabled(! auth()->user()->isPrincipalAdmin())>

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email', str_ends_with($student->user->email, '@students.invalid') ? '' : $student->user->email) }}">
        @error('email')<div class="error">{{ $message }}</div>@enderror

        <label for="phone">Phone</label>
        <input id="phone" name="phone" type="text" value="{{ old('phone', $student->phone) }}">

        <label for="current_level">Current level</label>
        <select id="current_level" name="current_level">@foreach([100,200,300,400] as $l)<option value="{{ $l }}" @selected(old('current_level', $student->current_level) == $l)>{{ $l }}L</option>@endforeach</select>

        <label for="status">Status</label>
        <select id="status" name="status">@foreach(['active','suspended','withdrawn'] as $s)<option value="{{ $s }}" @selected(old('status', $student->status) == $s)>{{ ucfirst($s) }}</option>@endforeach</select>
        @error('status')<div class="error">{{ $message }}</div>@enderror

        <label for="specialization_id">Area of specialization</label>
        <select id="specialization_id" name="specialization_id">
            <option value="">Not chosen</option>
            @foreach($areas as $a)<option value="{{ $a->id }}" @selected(old('specialization_id', $student->specialization_id) == $a->id)>{{ $a->name }}</option>@endforeach
        </select>

        <p><button class="btn">Save</button> <a href="{{ route('admin.students.show', $student) }}">Cancel</a></p>
    </form>
</div>
@endsection
