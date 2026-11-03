@extends('layouts.portal')
@section('title', 'Downloads')

@section('content')
<h1>Downloads</h1>
@include('admin.content._nav')

<div class="table-wrap"><table>
    <tr><th>Title</th><th>Category</th><th>File</th><th>Downloads</th><th>Status</th><th></th></tr>
    @forelse($items as $d)
        <tr><td>{{ $d->title }}</td><td>{{ \App\Models\Download::CATEGORIES[$d->category] }}</td><td>{{ $d->media->original_name }}</td><td>{{ $d->download_count }}</td>
            <td><span class="badge {{ $d->is_published ? 'ACTIVE' : 'REMOVED' }}">{{ $d->is_published ? 'SHOWN' : 'HIDDEN' }}</span></td>
            <td><a class="btn small" href="{{ route('admin.downloads.index', ['edit' => $d->id]) }}">Edit</a></td></tr>
    @empty
        <tr><td colspan="6" style="color:var(--muted)">No files yet.</td></tr>
    @endforelse
</table></div>

<h2>{{ $item ? 'Edit file' : 'Add a file' }}</h2>
<div class="card" style="max-width:560px">
    <form method="POST" action="{{ $item ? route('admin.downloads.update', $item) : route('admin.downloads.store') }}" enctype="multipart/form-data">
        @csrf @if($item) @method('PUT') @endif
        <label for="title">Title</label><input id="title" name="title" type="text" value="{{ old('title', $item?->title) }}" required maxlength="180">
        @error('title')<div class="error">{{ $message }}</div>@enderror
        <label for="category">Category</label><select id="category" name="category">@foreach(\App\Models\Download::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected(old('category', $item?->category) == $k)>{{ $l }}</option>@endforeach</select>
        <label for="description">Description (optional)</label><input id="description" name="description" type="text" value="{{ old('description', $item?->description) }}" maxlength="500">
        <label for="file">{{ $item ? 'Replace the file (optional)' : 'File' }} (PDF, Word, Excel or PowerPoint, up to 10 MB)</label>
        <input id="file" name="file" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx" @required(! $item)>
        @error('file')<div class="error">{{ $message }}</div>@enderror
        <label class="check"><input type="checkbox" name="is_published" value="1" @checked(old('is_published', $item?->is_published ?? true))> Show on the public site</label>
        <p><button class="btn">Save</button> @if($item)<a href="{{ route('admin.downloads.index') }}">Cancel</a>@endif</p>
    </form>
    @if($item)<form method="POST" action="{{ route('admin.downloads.destroy', $item) }}" data-confirm="Delete this file?">@csrf @method('DELETE')<button class="btn small danger">Delete file</button></form>@endif
</div>
@endsection
