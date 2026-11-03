@extends('layouts.portal')
@section('title', $course->exists ? 'Edit course' : 'Add course')

@section('content')
<h1>{{ $course->exists ? 'Edit '.$course->code : 'Add course' }}</h1>

<div class="card" style="max-width:560px">
    <form method="POST" action="{{ $course->exists ? route('admin.courses.update', $course) : route('admin.courses.store') }}">
        @csrf
        @if($course->exists) @method('PUT') @endif

        <label for="code">Course code</label>
        <input id="code" name="code" type="text" value="{{ old('code', $course->code) }}" required placeholder="PHY 101">
        @error('code')<div class="error">{{ $message }}</div>@enderror

        <label for="title">Title</label>
        <input id="title" name="title" type="text" value="{{ old('title', $course->title) }}" required>
        @error('title')<div class="error">{{ $message }}</div>@enderror

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">
            <div><label for="units">Credit units</label><input id="units" name="units" type="text" value="{{ old('units', $course->units) }}" required></div>
            <div><label for="level">Level</label><select id="level" name="level">@foreach([100,200,300,400] as $l)<option value="{{ $l }}" @selected(old('level', $course->level) == $l)>{{ $l }}L</option>@endforeach</select></div>
            <div><label for="semester">Semester</label><select id="semester" name="semester"><option value="1" @selected(old('semester', $course->semester) == 1)>First</option><option value="2" @selected(old('semester', $course->semester) == 2)>Second</option></select></div>
        </div>
        @error('units')<div class="error">{{ $message }}</div>@enderror

        <label for="type">Type</label>
        <select id="type" name="type">
            <option value="C" @selected(old('type', $course->type) == 'C')>C: Core</option>
            <option value="E" @selected(old('type', $course->type) == 'E')>E: Elective</option>
        </select>

        <label for="specialization_id">Required for area (electives only)</label>
        <select id="specialization_id" name="specialization_id">
            <option value="">None: ordinary elective</option>
            @foreach($areas as $a)<option value="{{ $a->id }}" @selected(old('specialization_id', $course->specialization_id) == $a->id)>{{ $a->name }}</option>@endforeach
        </select>

        <label class="check"><input type="checkbox" name="is_gst" value="1" @checked(old('is_gst', $course->is_gst))> General course (GST). Direct Entry students still take these at 100L.</label>
        <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $course->is_active))> Active (open for registration)</label>

        <p><button class="btn">Save</button> <a href="{{ route('admin.courses.index') }}">Cancel</a></p>
    </form>
</div>
@endsection
