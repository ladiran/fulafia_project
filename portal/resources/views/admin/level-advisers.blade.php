@extends('layouts.portal')
@section('title', 'Level Advisers')

@section('content')
<h1>Level Advisers</h1>
<p class="lead">One adviser per level, appointed for two years. Both you and the adviser are notified two months before the tenure ends. An expired adviser stays in post until you remove them.</p>

<div class="table-wrap"><table>
    <tr><th>Level</th><th>Adviser</th><th>Started</th><th>Ends</th><th>Status</th><th></th></tr>
    @foreach($levels as $level)
        @php($a = $holding->get($level))
        <tr>
            <td>{{ $level }}L</td>
            @if($a)
                <td>{{ $a->user->name }}</td>
                <td>{{ $a->starts_on->format('j M Y') }}</td>
                <td>{{ $a->ends_on->format('j M Y') }}</td>
                <td><span class="badge {{ $a->status() }}">{{ $a->status() }}</span></td>
                <td>
                    <form class="inline" method="POST" action="{{ route('admin.advisers.renew', $a) }}">@csrf<button class="btn small gold">Renew</button></form>
                    <form class="inline" method="POST" action="{{ route('admin.advisers.destroy', $a) }}" data-confirm="Remove this Level Adviser?">@csrf @method('DELETE')<button class="btn small danger">Remove</button></form>
                </td>
            @else
                <td colspan="5" style="color:var(--muted)">Not appointed</td>
            @endif
        </tr>
    @endforeach
</table></div>

<h2>Appoint a Level Adviser</h2>
<div class="card" style="max-width:460px">
    <form method="POST" action="{{ route('admin.advisers.store') }}">
        @csrf
        <label for="user_id">Lecturer</label>
        <select id="user_id" name="user_id" required>
            <option value="">Select…</option>
            @foreach($candidates as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
        </select>
        @error('user_id')<div class="error">{{ $message }}</div>@enderror

        <label for="level">Level</label>
        <select id="level" name="level" required>
            @foreach($levels as $level)<option value="{{ $level }}">{{ $level }}L</option>@endforeach
        </select>
        @error('level')<div class="error">{{ $message }}</div>@enderror

        <p><button class="btn">Appoint for 2 years</button></p>
    </form>
</div>

@if($history->isNotEmpty())
<h2>Past advisers</h2>
<div class="table-wrap"><table>
    <tr><th>Level</th><th>Adviser</th><th>Tenure</th><th>Removed</th></tr>
    @foreach($history as $h)
        <tr><td>{{ $h->level }}L</td><td>{{ $h->user->name }}</td><td>{{ $h->starts_on->format('j M Y') }} to {{ $h->ends_on->format('j M Y') }}</td><td>{{ $h->removed_at->format('j M Y') }}</td></tr>
    @endforeach
</table></div>
@endif
@endsection
