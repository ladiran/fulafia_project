@extends('layouts.public')
@section('title', 'Downloads')

@section('content')
<h1>Downloads</h1>
@forelse(\App\Models\Download::CATEGORIES as $key => $label)
    @if(isset($groups[$key]))
        <h2>{{ $label }}</h2>
        <div class="table-wrap"><table>
            @foreach($groups[$key] as $d)
                <tr>
                    <td><strong>{{ $d->title }}</strong>@if($d->description)<br><span class="meta">{{ $d->description }}</span>@endif</td>
                    <td class="meta">{{ strtoupper(pathinfo($d->media->original_name, PATHINFO_EXTENSION)) }} · {{ number_format($d->media->size / 1024) }} KB</td>
                    <td><a class="btn small" href="{{ route('downloads.get', $d) }}">Download</a></td>
                </tr>
            @endforeach
        </table></div>
    @endif
@empty
@endforelse
@if($groups->isEmpty())<div class="card" style="color:var(--muted)">No files are available yet.</div>@endif
@endsection
