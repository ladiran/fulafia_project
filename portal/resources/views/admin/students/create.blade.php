@extends('layouts.portal')
@section('title', 'Add student')

@section('content')
<h1>Add student</h1>
<p class="lead">The matric number is issued by the university. A temporary password is generated and shown once after saving.</p>

<div class="card" style="max-width:600px">
    <form method="POST" action="{{ route('admin.students.store') }}">
        @csrf
        <label for="name">Full name</label>
        <input id="name" name="name" type="text" value="{{ old('name') }}" required>
        @error('name')<div class="error">{{ $message }}</div>@enderror

        <label for="identifier">Matric number</label>
        <input id="identifier" name="identifier" type="text" value="{{ old('identifier') }}" required>
        @error('identifier')<div class="error">{{ $message }}</div>@enderror

        <label for="gender">Gender</label>
        <select id="gender" name="gender"><option value="male" @selected(old('gender') == 'male')>Male</option><option value="female" @selected(old('gender') == 'female')>Female</option></select>

        <label for="entry_mode">Admission mode</label>
        <select id="entry_mode" name="entry_mode">
            @foreach($modes as $key => $label)<option value="{{ $key }}" @selected(old('entry_mode') == $key)>{{ $label }}</option>@endforeach
        </select>

        <label for="entry_level">Entry level (transfer students only)</label>
        <select id="entry_level" name="entry_level"><option value="">Not applicable</option><option value="100" @selected(old('entry_level') == 100)>100L</option><option value="200" @selected(old('entry_level') == 200)>200L</option></select>
        @error('entry_level')<div class="error">{{ $message }}</div>@enderror

        <label for="entry_session_id">Session admitted</label>
        <select id="entry_session_id" name="entry_session_id">@foreach($sessions as $s)<option value="{{ $s->id }}" @selected(old('entry_session_id') == $s->id)>{{ $s->name }}</option>@endforeach</select>
        @error('entry_session_id')<div class="error">{{ $message }}</div>@enderror

        <label for="jamb_reg_no">JAMB registration number (JAMB admissions)</label>
        <input id="jamb_reg_no" name="jamb_reg_no" type="text" value="{{ old('jamb_reg_no') }}">
        @error('jamb_reg_no')<div class="error">{{ $message }}</div>@enderror

        <label for="email">Email (optional)</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}">
        @error('email')<div class="error">{{ $message }}</div>@enderror

        <label for="phone">Phone (optional)</label>
        <input id="phone" name="phone" type="text" value="{{ old('phone') }}">

        <p><button class="btn">Add student</button> <a href="{{ route('admin.students.index') }}">Cancel</a></p>
    </form>
</div>
@endsection
