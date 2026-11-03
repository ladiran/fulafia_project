@extends('layouts.portal')
@section('title', 'Gallery')

@section('content')
<h1>Gallery</h1>
@include('admin.content._nav')

<div class="card" style="max-width:560px;margin-bottom:18px">
    <strong>Upload photos</strong>
    <form method="POST" action="{{ route('admin.gallery.store') }}" enctype="multipart/form-data">
        @csrf
        <label for="photos">Photos (JPG, PNG or WebP, up to 4 MB each, 20 at a time)</label>
        <input id="photos" name="photos[]" type="file" accept=".jpg,.jpeg,.png,.webp" multiple required>
        @error('photos')<div class="error">{{ $message }}</div>@enderror
        @error('photos.*')<div class="error">{{ $message }}</div>@enderror
        <label for="caption">Caption (applies to all)</label><input id="caption" name="caption" type="text" maxlength="200">
        <p><button class="btn">Upload</button></p>
    </form>
</div>

<div class="cards">
    @forelse($items as $i)
        <form method="POST" action="{{ route('admin.gallery.update', $i) }}" class="card">
            @csrf @method('PUT')
            <img src="{{ $i->media->url() }}" alt="" style="width:100%;height:130px;object-fit:cover;border-radius:6px">
            <input type="text" name="caption" value="{{ $i->caption }}" placeholder="Caption" maxlength="200" style="margin-top:6px">
            <div style="display:flex;gap:8px;align-items:center;margin-top:6px">
                <input type="text" name="sort_order" value="{{ $i->sort_order }}" style="width:70px" title="Order">
                <label class="check" style="margin:0"><input type="checkbox" name="is_published" value="1" @checked($i->is_published)> Shown</label>
                <button class="btn small">Save</button>
            </div>
            <button class="btn small danger" style="margin-top:6px" form="del-{{ $i->id }}">Delete</button>
        </form>
        <form id="del-{{ $i->id }}" method="POST" action="{{ route('admin.gallery.destroy', $i) }}" data-confirm="Delete this photo?" hidden>@csrf @method('DELETE')</form>
    @empty
        <div class="card" style="color:var(--muted)">No photos yet.</div>
    @endforelse
</div>
@endsection
