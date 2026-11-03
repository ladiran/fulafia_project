@extends('layouts.portal')
@section('title', 'Course registration')

@section('content')
<h1>Course registration</h1>

@if($blocked)
    <div class="alert warn">{{ $blocked }}</div>

    @if($student->current_level === 400 && $student->specialization_id === null && $session?->specialization_window_open)
        @php($areas = \App\Models\Specialization::where('is_active', true)->orderBy('name')->get())
        <div class="card" style="max-width:460px">
            <strong>Choose your area of specialization</strong>
            <form method="POST" action="{{ route('student.specialization') }}">
                @csrf
                <label for="specialization_id">Area</label>
                <select id="specialization_id" name="specialization_id" required>
                    <option value="">Select…</option>
                    @foreach($areas as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach
                </select>
                @error('specialization_id')<div class="error">{{ $message }}</div>@enderror
                <p style="color:var(--muted);font-size:.9rem">You can choose once. Changing it later needs the department.</p>
                <p><button class="btn">Save area</button></p>
            </form>
        </div>
    @endif
@else
    <p class="lead">{{ $session->name }} session · {{ $session->current_semester == 1 ? 'First' : 'Second' }} semester · {{ $student->current_level }}L</p>
    @error('courses')<div class="alert bad">{{ $message }}</div>@enderror

    <form method="POST" action="{{ route('student.registration.submit') }}">
        @csrf
        <div class="table-wrap"><table>
            <tr><th></th><th>Course</th><th>Title</th><th>Units</th><th>Requirement</th><th>Status</th></tr>
            @foreach($available as $c)
                @php($reg = $mine->get($c->id))
                @php($mandatory = $c->mandatory)
                @php($locked = $reg && $reg->status === 'approved')
                <tr>
                    <td><input type="checkbox" name="courses[]" value="{{ $c->id }}" data-units="{{ $c->units }}" @checked($mandatory || ($reg && ! $c->over_cap)) @disabled($locked || $mandatory || $c->over_cap) ></td>
                    <td>{{ $c->label() }}</td>
                    <td>{{ $c->title }}</td>
                    <td>{{ $c->units }}</td>
                    <td>
                        @if($c->over_cap)
                            <span style="color:#8a6200">Required, but over the {{ config('portal.max_units_per_semester') }}-unit limit: you will register it in a later semester</span>
                        @else
                            {{ $c->carry_over ? 'Required (carry-over: failed earlier)' : ($c->outstanding ? 'Required (not exempted by your transfer evaluation)' : ($mandatory ? 'Required' : 'Optional')) }}
                        @endif
                    </td>
                    <td>@if($reg)<span class="badge {{ ['pending' => 'EXPIRING', 'approved' => 'ACTIVE', 'rejected' => 'EXPIRED'][$reg->status] }}">{{ strtoupper($reg->status) }}</span>@if($reg->note) <small>{{ $reg->note }}</small>@endif @else – @endif</td>
                </tr>
            @endforeach
        </table></div>

        {{-- Disabled checkboxes are not submitted, so required and locked courses are sent explicitly. --}}
        @foreach($available as $c)
            @if($c->mandatory || ($mine->get($c->id)?->status === 'approved'))
                <input type="hidden" name="courses[]" value="{{ $c->id }}">
            @endif
        @endforeach

        <p style="margin-top:14px">Units selected: <strong data-unit-total data-max="{{ config('portal.max_units_per_semester') }}">0</strong> of {{ config('portal.max_units_per_semester') }}
            <span class="meta">(failed courses are registered first)</span></p>
        <p><button class="btn">Submit registration</button></p>
    </form>
@endif
@endsection
