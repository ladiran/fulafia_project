@extends('layouts.public')
@section('title', 'Gallery')

@section('content')
<h1>Gallery</h1>
@if($items->isEmpty())
    <div class="card" style="color:var(--muted)">No photos yet.</div>
@else
    <div class="gallery">
        @foreach($items as $i)
            <figure><a href="{{ $i->media->url() }}" target="_blank"><img src="{{ $i->media->url() }}" alt="{{ $i->caption }}" loading="lazy"></a>@if($i->caption)<figcaption>{{ $i->caption }}</figcaption>@endif</figure>
        @endforeach
    </div>
@endif
@endsection
