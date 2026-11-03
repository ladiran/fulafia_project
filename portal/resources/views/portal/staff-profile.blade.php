@extends('layouts.portal')
@section('title', 'My public profile')

@section('content')
<h1>My public profile</h1>
<p class="lead">Shown on the staff page: <a href="{{ route('staff.member', $member) }}" target="_blank">{{ $member->fullName() }}</a>. Your name and position are set by the department.</p>

<div class="card" style="max-width:700px">
    <form method="POST" action="{{ route('staff.profile.update') }}" enctype="multipart/form-data">
        @csrf
        <label for="email">Public email</label><input id="email" name="email" type="email" value="{{ old('email', $member->email) }}">
        @error('email')<div class="error">{{ $message }}</div>@enderror
        <label for="research_interests">Research interests</label><textarea id="research_interests" name="research_interests" rows="2" style="width:100%;padding:8px;border:1px solid #c9c3b3;border-radius:6px" maxlength="1000">{{ old('research_interests', $member->research_interests) }}</textarea>
        <label for="bio">Bio (Markdown)</label><textarea id="bio" name="bio" rows="5" style="width:100%;padding:8px;border:1px solid #c9c3b3;border-radius:6px">{{ old('bio', $member->bio) }}</textarea>
        <label for="publications">Publications (Markdown)</label><textarea id="publications" name="publications" rows="6" style="width:100%;padding:8px;border:1px solid #c9c3b3;border-radius:6px">{{ old('publications', $member->publications) }}</textarea>
        <label for="photo">Photo (JPG, PNG or WebP, up to 4 MB)</label><input id="photo" name="photo" type="file" accept=".jpg,.jpeg,.png,.webp">
        @error('photo')<div class="error">{{ $message }}</div>@enderror
        <p><button class="btn">Save</button></p>
    </form>
</div>
@endsection
