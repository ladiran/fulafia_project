@extends('layouts.portal')
@section('title', 'Pages')

@section('content')
<h1>Pages</h1>
@include('admin.content._nav')
<p class="lead">Write with Markdown: <code>## Heading</code>, <code>**bold**</code>, <code>- list</code>, <code>[text](https://link)</code>. Raw HTML is removed for safety.</p>

<div class="table-wrap"><table>
    <tr><th>Title</th><th>Address</th><th>Menu</th><th>Status</th><th></th></tr>
    @foreach($items as $p)
        <tr>
            <td>{{ $p->title }}</td><td><a href="{{ route('page', $p->slug) }}" target="_blank">/p/{{ $p->slug }}</a></td>
            <td>{{ $p->show_in_menu ? (\App\Models\Page::MENU_GROUPS[$p->menu_group] ?? 'Top level') : '–' }}</td>
            <td><span class="badge {{ $p->is_published ? 'ACTIVE' : 'REMOVED' }}">{{ $p->is_published ? 'PUBLISHED' : 'HIDDEN' }}</span></td>
            <td><a class="btn small" href="{{ route('admin.pages.index', ['edit' => $p->id]) }}">Edit</a></td>
        </tr>
    @endforeach
</table></div>

<h2>{{ $item ? 'Edit page' : 'New page' }}</h2>
<div class="card" style="max-width:760px">
    <form method="POST" action="{{ $item ? route('admin.pages.update', $item) : route('admin.pages.store') }}">
        @csrf @if($item) @method('PUT') @endif
        <label for="title">Title</label><input id="title" name="title" type="text" value="{{ old('title', $item?->title) }}" required maxlength="150">
        @error('title')<div class="error">{{ $message }}</div>@enderror
        <label for="slug">Address (lowercase letters, numbers and dashes)</label><input id="slug" name="slug" type="text" value="{{ old('slug', $item?->slug) }}" required maxlength="100">
        @error('slug')<div class="error">{{ $message }}</div>@enderror
        <label for="body">Content (Markdown)</label>
        <textarea id="body" name="body" rows="14" required style="width:100%;padding:8px;border:1px solid #c9c3b3;border-radius:6px;font-family:ui-monospace,monospace">{{ old('body', $item?->body) }}</textarea>
        @error('body')<div class="error">{{ $message }}</div>@enderror
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div><label for="menu_group">Menu group</label><select id="menu_group" name="menu_group"><option value="">Top level</option>@foreach(\App\Models\Page::MENU_GROUPS as $k => $l)<option value="{{ $k }}" @selected(old('menu_group', $item?->menu_group) == $k)>{{ $l }}</option>@endforeach</select></div>
            <div><label for="sort_order">Order in menu</label><input id="sort_order" name="sort_order" type="text" value="{{ old('sort_order', $item?->sort_order ?? 100) }}"></div>
        </div>
        <label class="check"><input type="checkbox" name="show_in_menu" value="1" @checked(old('show_in_menu', $item?->show_in_menu))> Show in the menu</label>
        <label class="check"><input type="checkbox" name="is_published" value="1" @checked(old('is_published', $item?->is_published ?? true))> Published</label>
        <p><button class="btn">Save page</button> @if($item)<a href="{{ route('admin.pages.index') }}">Cancel</a>@endif</p>
    </form>
    @if($item)
        <form method="POST" action="{{ route('admin.pages.destroy', $item) }}" data-confirm="Delete this page?">@csrf @method('DELETE')<button class="btn small danger">Delete page</button></form>
    @endif
</div>
@endsection
