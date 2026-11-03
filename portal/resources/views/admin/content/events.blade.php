@extends('layouts.portal')
@section('title', 'Events')

@section('content')
<h1>Events</h1>
@include('admin.content._nav')

<div class="table-wrap"><table>
    <tr><th>Event</th><th>When</th><th>Where</th><th>Status</th><th></th></tr>
    @forelse($items as $e)
        <tr><td>{{ $e->title }}</td><td>{{ $e->starts_at->format('j M Y, g:i A') }}</td><td>{{ $e->location ?: '–' }}</td>
            <td><span class="badge {{ $e->is_published ? 'ACTIVE' : 'REMOVED' }}">{{ $e->is_published ? 'SHOWN' : 'HIDDEN' }}</span></td>
            <td><a class="btn small" href="{{ route('admin.events.index', ['edit' => $e->id]) }}">Edit</a></td></tr>
    @empty
        <tr><td colspan="5" style="color:var(--muted)">No events yet.</td></tr>
    @endforelse
</table></div>

<h2>{{ $item ? 'Edit event' : 'New event' }}</h2>
<div class="card" style="max-width:600px">
    <form method="POST" action="{{ $item ? route('admin.events.update', $item) : route('admin.events.store') }}">
        @csrf @if($item) @method('PUT') @endif
        <label for="title">Title</label><input id="title" name="title" type="text" value="{{ old('title', $item?->title) }}" required maxlength="180">
        @error('title')<div class="error">{{ $message }}</div>@enderror
        <label for="starts_at">Starts</label><input id="starts_at" name="starts_at" type="datetime-local" value="{{ old('starts_at', $item?->starts_at?->format('Y-m-d\TH:i')) }}" required>
        @error('starts_at')<div class="error">{{ $message }}</div>@enderror
        <label for="ends_at">Ends (optional)</label><input id="ends_at" name="ends_at" type="datetime-local" value="{{ old('ends_at', $item?->ends_at?->format('Y-m-d\TH:i')) }}">
        @error('ends_at')<div class="error">{{ $message }}</div>@enderror
        <label for="location">Location</label><input id="location" name="location" type="text" value="{{ old('location', $item?->location) }}" maxlength="150">
        <label for="description">Description</label><textarea id="description" name="description" rows="3" style="width:100%;padding:8px;border:1px solid #c9c3b3;border-radius:6px" maxlength="2000">{{ old('description', $item?->description) }}</textarea>
        <label class="check"><input type="checkbox" name="is_published" value="1" @checked(old('is_published', $item?->is_published ?? true))> Show on the public site</label>
        <p><button class="btn">Save event</button> @if($item)<a href="{{ route('admin.events.index') }}">Cancel</a>@endif</p>
    </form>
    @if($item)<form method="POST" action="{{ route('admin.events.destroy', $item) }}" data-confirm="Delete this event?">@csrf @method('DELETE')<button class="btn small danger">Delete event</button></form>@endif
</div>
@endsection
