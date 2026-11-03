@extends('layouts.portal')
@section('title', 'Specializations')

@section('content')
<h1>Areas of specialization</h1>
<p class="lead">Students choose one at the beginning of 400 level. Areas are deactivated rather than deleted, so existing student records stay intact.</p>

<div class="table-wrap"><table>
    <tr><th>Area</th><th>Active</th><th></th></tr>
    @foreach($areas as $a)
        <form method="POST" action="{{ route('admin.specializations.update', $a) }}">
            @csrf
            <tr>
                <td><input type="text" name="name" value="{{ $a->name }}" required></td>
                <td><label class="check"><input type="checkbox" name="is_active" value="1" @checked($a->is_active)> Active</label></td>
                <td><button class="btn small">Save</button></td>
            </tr>
        </form>
    @endforeach
</table></div>
@error('name')<div class="error">{{ $message }}</div>@enderror

<h2>Add an area</h2>
<div class="card" style="max-width:420px">
    <form method="POST" action="{{ route('admin.specializations.store') }}">
        @csrf
        <label for="name">Name</label>
        <input id="name" name="name" type="text" required>
        <p><button class="btn">Add</button></p>
    </form>
</div>
@endsection
