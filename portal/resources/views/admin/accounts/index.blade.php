@extends('layouts.portal')
@section('title', 'Staff accounts')

@php($roleLabel = ['principal_admin' => 'Head of Department', 'secondary_admin' => 'Examination Officer', 'admin' => 'Appointed admin', 'lecturer' => 'Lecturer'])

@section('content')
<h1>Staff accounts</h1>
<p class="lead">Sign-in accounts for lecturers, the Examination Officer and the HoD. A new account gets a temporary password that the person must change at their first sign-in. Students are managed under Students.</p>
@error('account')<div class="alert bad">{{ $message }}</div>@enderror

<form method="GET" class="card" style="display:flex;gap:10px;align-items:end;margin-bottom:14px">
    <div style="flex:1"><label for="q">Search name, email or staff ID</label><input id="q" name="q" type="text" value="{{ request('q') }}"></div>
    <button class="btn">Search</button>
</form>

<div class="table-wrap"><table>
    <tr><th>Name</th><th>Staff ID</th><th>Email</th><th>Role</th><th>Status</th><th>Last sign-in</th><th></th></tr>
    @foreach($users as $u)
        <tr>
            <td>{{ $u->name }}@if($advisers->has($u->id)) <span class="badge ACTIVE">{{ $advisers[$u->id] }}L ADVISER</span>@endif</td>
            <td>{{ $u->identifier }}</td>
            <td>{{ $u->email }}</td>
            <td>{{ $roleLabel[$u->role] }}@if($u->isAdmin()) <span class="meta">· 2FA {{ $u->hasTwoFactor() ? 'on' : 'not set up' }}</span>@endif</td>
            <td><span class="badge {{ $u->is_active ? 'ACTIVE' : 'REMOVED' }}">{{ $u->is_active ? 'ACTIVE' : 'INACTIVE' }}</span>@if($u->must_change_password) <span class="badge EXPIRING">TEMP PASSWORD</span>@endif</td>
            <td class="meta">{{ $u->last_login_at?->format('j M Y') ?? 'never' }}</td>
            <td style="white-space:nowrap">
                @if($canManage($u))
                    <a class="btn small" href="{{ route('admin.accounts.index', ['edit' => $u->id]) }}">Edit</a>
                    <form class="inline" method="POST" action="{{ route('admin.accounts.reset-password', $u) }}" data-confirm="Give {{ $u->name }} a new temporary password?">@csrf<button class="btn small light" style="border:1px solid var(--line)">Reset password</button></form>
                    @if($u->is_active)
                        <form class="inline" method="POST" action="{{ route('admin.accounts.deactivate', $u) }}" data-confirm="{{ $u->name }} will no longer be able to sign in. Continue?">@csrf<button class="btn small danger">Deactivate</button></form>
                    @else
                        <form class="inline" method="POST" action="{{ route('admin.accounts.reactivate', $u) }}">@csrf<button class="btn small gold">Reactivate</button></form>
                    @endif
                @endif
            </td>
        </tr>
    @endforeach
</table></div>

<h2>{{ $item ? 'Edit account' : 'New staff account' }}</h2>
<div class="card" style="max-width:560px">
    <form method="POST" action="{{ $item ? route('admin.accounts.update', $item) : route('admin.accounts.store') }}">
        @csrf @if($item) @method('PUT') @endif
        <label for="name">Full name</label><input id="name" name="name" type="text" value="{{ old('name', $item?->name) }}" required maxlength="150">
        @error('name')<div class="error">{{ $message }}</div>@enderror
        <label for="email">Email (used to sign in and to reset the password)</label><input id="email" name="email" type="email" value="{{ old('email', $item?->email) }}" required maxlength="150">
        @error('email')<div class="error">{{ $message }}</div>@enderror
        <label for="identifier">Staff ID</label><input id="identifier" name="identifier" type="text" value="{{ old('identifier', $item?->identifier) }}" required maxlength="40">
        @error('identifier')<div class="error">{{ $message }}</div>@enderror

        @unless($item)
            <label class="check"><input type="checkbox" name="add_to_directory" value="1" @checked(old('add_to_directory', true))> Also add to the public staff directory</label>
            <label for="cadre">Cadre</label>
            <select id="cadre" name="cadre"><option value="">Not set</option>@foreach(\App\Models\StaffMember::CADRES as $c)<option value="{{ $c }}" @selected(old('cadre') == $c)>{{ $c }}</option>@endforeach</select>
            <div style="display:grid;grid-template-columns:110px 1fr;gap:10px">
                <div><label for="title">Title</label><input id="title" name="title" type="text" value="{{ old('title') }}" placeholder="Dr." maxlength="30"></div>
                <div><label for="position">Other position (optional)</label><input id="position" name="position" type="text" value="{{ old('position') }}" placeholder="Level adviser, Coordinator…" maxlength="150"></div>
            </div>
        @endunless
        <p><button class="btn">{{ $item ? 'Save changes' : 'Create account' }}</button> @if($item)<a href="{{ route('admin.accounts.index') }}">Cancel</a>@endif</p>
    </form>
</div>

@if($isHod)
    <h2>Offices</h2>
    <div class="grid">
        <div class="card">
            <strong>Appoint the Examination Officer</strong>
            <p class="meta">Only one at a time. The current EO goes back to being a lecturer.</p>
            <form method="POST" action="{{ route('admin.accounts.appoint-eo') }}" data-confirm="Appoint this person as Examination Officer?">
                @csrf
                <select name="user_id" required><option value="">Select…</option>@foreach($users->whereIn('role', ['lecturer', 'admin'])->where('is_active', true) as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select>
                @error('user_id')<div class="error">{{ $message }}</div>@enderror
                <p><button class="btn small">Appoint EO</button></p>
            </form>
        </div>
        <div class="card">
            <strong>Hand over the office of HoD</strong>
            <p class="meta">For when a new Head of Department takes over. You become a lecturer and lose all admin rights immediately.</p>
            <form method="POST" action="{{ route('admin.accounts.handover') }}" data-confirm="Hand over the office of Head of Department? You will lose admin access.">
                @csrf
                <select name="new_hod_id" required><option value="">New HoD…</option>@foreach($users->whereIn('role', ['lecturer', 'admin', 'secondary_admin'])->where('is_active', true) as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select>
                @error('new_hod_id')<div class="error">{{ $message }}</div>@enderror
                <input type="password" name="current_password" placeholder="Your password" required style="margin-top:6px" autocomplete="current-password">
                @error('current_password')<div class="error">{{ $message }}</div>@enderror
                <label class="check"><input type="checkbox" name="confirm" value="1"> I understand this transfers the office</label>
                @error('confirm')<div class="error">{{ $message }}</div>@enderror
                <p><button class="btn small danger">Hand over</button></p>
            </form>
        </div>
    </div>
@endif
@endsection
