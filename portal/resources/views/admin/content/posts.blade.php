@extends('layouts.portal')
@section('title', 'News')

@section('content')
<h1>News and announcements</h1>
@include('admin.content._nav')

<div class="table-wrap"><table>
    <tr><th>Title</th><th>Type</th><th>Date</th><th>Status</th><th></th></tr>
    @forelse($items as $p)
        <tr>
            <td>{{ $p->title }}@if($p->is_pinned) <span class="badge REMOVED">PINNED</span>@endif</td>
            <td>{{ \App\Models\Post::TYPES[$p->type] }}</td>
            <td>{{ $p->published_at?->format('j M Y H:i') }}</td>
            <td>@if(! $p->is_published)<span class="badge REMOVED">DRAFT</span>@elseif($p->published_at->isFuture())<span class="badge EXPIRING">SCHEDULED</span>@else<span class="badge ACTIVE">LIVE</span>@endif</td>
            <td><a class="btn small" href="{{ route('admin.posts.index', ['edit' => $p->id]) }}">Edit</a></td>
        </tr>
    @empty
        <tr><td colspan="5" style="color:var(--muted)">No posts yet.</td></tr>
    @endforelse
</table></div>

<h2>{{ $item ? 'Edit post' : 'New post' }}</h2>
<div class="card" style="max-width:760px">
    <form method="POST" action="{{ $item ? route('admin.posts.update', $item) : route('admin.posts.store') }}" enctype="multipart/form-data">
        @csrf @if($item) @method('PUT') @endif
        <label for="type">Type</label><select id="type" name="type">@foreach(\App\Models\Post::TYPES as $k => $l)<option value="{{ $k }}" @selected(old('type', $item?->type) == $k)>{{ $l }}</option>@endforeach</select>
        <label for="title">Title</label><input id="title" name="title" type="text" value="{{ old('title', $item?->title) }}" required maxlength="180">
        @error('title')<div class="error">{{ $message }}</div>@enderror
        <label for="summary">Short summary (optional, shown on cards)</label><input id="summary" name="summary" type="text" value="{{ old('summary', $item?->summary) }}" maxlength="300">
        <label for="body">Content (Markdown)</label>
        <textarea id="body" name="body" rows="10" required style="width:100%;padding:8px;border:1px solid #c9c3b3;border-radius:6px;font-family:ui-monospace,monospace">{{ old('body', $item?->body) }}</textarea>
        @error('body')<div class="error">{{ $message }}</div>@enderror
        <label for="image">Image (JPG, PNG or WebP, up to 4 MB)</label><input id="image" name="image" type="file" accept=".jpg,.jpeg,.png,.webp">
        @error('image')<div class="error">{{ $message }}</div>@enderror
        @if($item?->image)<label class="check"><input type="checkbox" name="remove_image" value="1"> Remove the current image</label>@endif
        <label for="published_at">Publish date and time (leave empty for now; a future time schedules it)</label>
        <input id="published_at" name="published_at" type="datetime-local" value="{{ old('published_at', $item?->published_at?->format('Y-m-d\TH:i')) }}">
        @error('published_at')<div class="error">{{ $message }}</div>@enderror
        <label class="check"><input type="checkbox" name="is_published" value="1" @checked(old('is_published', $item?->is_published))> Published (untick to keep as a draft)</label>
        <label class="check"><input type="checkbox" name="is_pinned" value="1" @checked(old('is_pinned', $item?->is_pinned))> Pin to the top</label>
        <p><button class="btn">Save post</button> @if($item)<a href="{{ route('admin.posts.index') }}">Cancel</a>@endif</p>
    </form>
    @if($item)<form method="POST" action="{{ route('admin.posts.destroy', $item) }}" data-confirm="Delete this post?">@csrf @method('DELETE')<button class="btn small danger">Delete post</button></form>@endif
</div>
@endsection
