@extends('layouts.portal')
@section('title', 'Staff directory')

@section('content')
<h1>Staff directory</h1>
@include('admin.content._nav')
<p class="lead">Link a profile to a lecturer account so that lecturer can keep their own research interests, bio and publications up to date.</p>

<div class="table-wrap"><table>
    <tr><th>Name</th><th>Category · cadre</th><th>Linked account</th><th>Status</th><th></th></tr>
    @forelse($items as $m)
        <tr><td>{{ $m->fullName() }}@if($m->isHod()) <span class="badge ACTIVE">HoD</span>@endif</td><td>{{ \App\Models\StaffMember::CATEGORIES[$m->category] }}@if($m->cadre) · {{ $m->cadre }}@endif</td><td>{{ $m->user?->name ?? '–' }}</td>
            <td><span class="badge {{ $m->is_published ? 'ACTIVE' : 'REMOVED' }}">{{ $m->is_published ? 'SHOWN' : 'HIDDEN' }}</span></td>
            <td><a class="btn small" href="{{ route('admin.staff.index', ['edit' => $m->id]) }}">Edit</a></td></tr>
    @empty
        <tr><td colspan="5" style="color:var(--muted)">No profiles yet.</td></tr>
    @endforelse
</table></div>

<h2>{{ $item ? 'Edit profile' : 'New profile' }}</h2>
<div class="card" style="max-width:700px">
    <form method="POST" action="{{ $item ? route('admin.staff.update', $item) : route('admin.staff.store') }}" enctype="multipart/form-data">
        @csrf @if($item) @method('PUT') @endif
        <label for="category">Category</label>
        <select id="category" name="category">@foreach(\App\Models\StaffMember::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected(old('category', $item?->category ?? 'academic') == $k)>{{ $l }}</option>@endforeach</select>
        <label for="cadre">Cadre (academic staff)</label>
        <select id="cadre" name="cadre"><option value="">Not applicable</option>@foreach(\App\Models\StaffMember::CADRES as $c)<option value="{{ $c }}" @selected(old('cadre', $item?->cadre) == $c)>{{ $c }}</option>@endforeach</select>
        @error('cadre')<div class="error">{{ $message }}</div>@enderror
        <p class="meta" style="margin:4px 0">The directory lists academic staff by cadre. The Head of Department always comes straight after the Distinguished Professor(s), whatever their own cadre. The HoD is recognised from the linked lecturer account below.</p>
        <div style="display:grid;grid-template-columns:110px 1fr;gap:10px">
            <div><label for="title">Title</label><input id="title" name="title" type="text" value="{{ old('title', $item?->title) }}" placeholder="Dr." maxlength="30"></div>
            <div><label for="name">Full name</label><input id="name" name="name" type="text" value="{{ old('name', $item?->name) }}" required maxlength="150"></div>
        </div>
        @error('name')<div class="error">{{ $message }}</div>@enderror
        <label for="position">Position</label><input id="position" name="position" type="text" value="{{ old('position', $item?->position) }}" placeholder="Head of Department, Lecturer I…" maxlength="150">
        <label for="email">Public email</label><input id="email" name="email" type="email" value="{{ old('email', $item?->email) }}">
        @error('email')<div class="error">{{ $message }}</div>@enderror
        <label for="user_id">Linked lecturer account (optional)</label>
        <select id="user_id" name="user_id"><option value="">None</option>@foreach($lecturers as $l)<option value="{{ $l->id }}" @selected(old('user_id', $item?->user_id) == $l->id)>{{ $l->name }}</option>@endforeach</select>
        @error('user_id')<div class="error">{{ $message }}</div>@enderror
        <label for="research_interests">Research interests</label><textarea id="research_interests" name="research_interests" rows="2" style="width:100%;padding:8px;border:1px solid #c9c3b3;border-radius:6px" maxlength="1000">{{ old('research_interests', $item?->research_interests) }}</textarea>
        <label for="bio">Bio (Markdown)</label><textarea id="bio" name="bio" rows="5" style="width:100%;padding:8px;border:1px solid #c9c3b3;border-radius:6px">{{ old('bio', $item?->bio) }}</textarea>
        <label for="publications">Publications (Markdown)</label><textarea id="publications" name="publications" rows="5" style="width:100%;padding:8px;border:1px solid #c9c3b3;border-radius:6px">{{ old('publications', $item?->publications) }}</textarea>
        <label for="photo">Photo (JPG, PNG or WebP, up to 4 MB)</label><input id="photo" name="photo" type="file" accept=".jpg,.jpeg,.png,.webp">
        @error('photo')<div class="error">{{ $message }}</div>@enderror
        <label for="sort_order">Order in the directory (lower first)</label><input id="sort_order" name="sort_order" type="text" value="{{ old('sort_order', $item?->sort_order ?? 100) }}" style="width:100px">
        <label class="check"><input type="checkbox" name="is_published" value="1" @checked(old('is_published', $item?->is_published ?? true))> Show in the public directory</label>
        <p><button class="btn">Save profile</button> @if($item)<a href="{{ route('admin.staff.index') }}">Cancel</a>@endif</p>
    </form>
    @if($item)<form method="POST" action="{{ route('admin.staff.destroy', $item) }}" data-confirm="Remove this profile from the directory?">@csrf @method('DELETE')<button class="btn small danger">Remove profile</button></form>@endif
</div>
@endsection
