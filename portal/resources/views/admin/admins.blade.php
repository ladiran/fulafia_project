@extends('layouts.portal')
@section('title', 'Admins')

@section('content')
<h1>Admins</h1>
<p class="lead">Appoint staff as admins with only the permissions they need. The Examination Officer is the Secondary Admin and is set up separately.</p>

<div class="table-wrap"><table>
    <tr><th>Name</th><th>Permissions</th><th></th></tr>
    @forelse($admins as $a)
        <tr>
            <td>{{ $a->name }}</td>
            <td>{{ collect($a->permissions)->map(fn ($p) => $permissions[$p] ?? $p)->implode(', ') }}</td>
            <td><form class="inline" method="POST" action="{{ route('admin.admins.destroy', $a) }}" data-confirm="Revoke admin access?">@csrf @method('DELETE')<button class="btn small danger">Revoke</button></form></td>
        </tr>
    @empty
        <tr><td colspan="3" style="color:var(--muted)">No appointed admins yet.</td></tr>
    @endforelse
</table></div>

<h2>Lost phone? Reset two-factor login</h2>
<p class="lead">The person sets two-factor login up again at their next sign-in. Admins use recovery codes first.</p>
<div class="table-wrap"><table>
    @foreach(\App\Models\User::whereIn('role', ['secondary_admin', 'admin'])->orderBy('name')->get() as $u)
        <tr>
            <td>{{ $u->name }} <span class="meta">({{ $u->role === 'secondary_admin' ? 'Examination Officer' : 'Admin' }})</span></td>
            <td>{{ $u->hasTwoFactor() ? 'Two-factor on' : 'Not set up' }}</td>
            <td><form class="inline" method="POST" action="{{ route('admin.users.reset-two-factor', $u) }}" data-confirm="Reset two-factor login for {{ $u->name }}?">@csrf<button class="btn small danger">Reset</button></form></td>
        </tr>
    @endforeach
</table></div>

<h2>Appoint an admin</h2>
<div class="card" style="max-width:460px">
    <form method="POST" action="{{ route('admin.admins.store') }}">
        @csrf
        <label for="user_id">Staff member</label>
        <select id="user_id" name="user_id" required>
            <option value="">Select…</option>
            @foreach($candidates as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
        </select>
        @error('user_id')<div class="error">{{ $message }}</div>@enderror

        <label>Permissions</label>
        @foreach($permissions as $key => $label)
            <label class="check"><input type="checkbox" name="permissions[]" value="{{ $key }}"> {{ $label }}</label>
        @endforeach
        @error('permissions')<div class="error">{{ $message }}</div>@enderror

        <p><button class="btn">Appoint</button></p>
    </form>
</div>
@endsection
