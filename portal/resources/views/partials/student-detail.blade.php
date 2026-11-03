{{-- Shared by the admin and Level Adviser student pages. Expects $student with user, specialization, entrySession, registrations.course/session. --}}
<div class="grid">
    <div class="card">
        <strong>{{ $student->user->name }}</strong><br>
        Matric no.: {{ $student->user->identifier }}<br>
        Gender: {{ ucfirst($student->gender) }}<br>
        Phone: {{ $student->phone ?: '–' }}<br>
        Email: {{ str_ends_with($student->user->email, '@students.invalid') ? '–' : $student->user->email }}
    </div>
    <div class="card">
        Level: <strong>{{ $student->current_level }}L</strong><br>
        Status: {{ ucfirst($student->status) }}<br>
        Admission: {{ \App\Models\StudentProfile::ENTRY_MODES[$student->entry_mode] ?? $student->entry_mode }}<br>
        Entry level: {{ $student->entry_level }}L · Session: {{ $student->entrySession?->name ?? '–' }}<br>
        JAMB no.: {{ $student->jamb_reg_no ?: '–' }}<br>
        Specialization: {{ $student->specialization?->name ?? '–' }}
    </div>
</div>

@php($names = $student->nameHistory)
@if($names->isNotEmpty())
    <h2>Name history</h2>
    <div class="table-wrap"><table>
        <tr><th>Previous name</th><th>New name</th><th>Reason</th><th>Effective</th></tr>
        @foreach($names as $n)<tr><td>{{ $n->previous_name }}</td><td>{{ $n->new_name }}</td><td>{{ ucfirst($n->reason) }}</td><td>{{ $n->effective_date->format('j M Y') }}</td></tr>@endforeach
    </table></div>
@endif

<h2>Results</h2>
@php($summary = app(\App\Services\GpaService::class)->summary($student))
@include('partials.results-summary')

<h2>Course registrations</h2>
@php($groups = $student->registrations->groupBy(fn ($r) => $r->session->name.' · Semester '.$r->semester))
@forelse($groups as $label => $regs)
    <h3 style="margin:18px 0 6px">{{ $label }}</h3>
    <div class="table-wrap"><table>
        <tr><th>Course</th><th>Title</th><th>Units</th><th>Status</th>@isset($reviewable)<th></th>@endisset</tr>
        @foreach($regs->sortBy('course.code') as $r)
            <tr>
                <td>{{ $r->course->label() }}</td>
                <td>{{ $r->course->title }}</td>
                <td>{{ $r->course->units }}</td>
                <td><span class="badge {{ ['pending' => 'EXPIRING', 'approved' => 'ACTIVE', 'rejected' => 'EXPIRED'][$r->status] }}">{{ strtoupper($r->status) }}</span>@if($r->note) <small>{{ $r->note }}</small>@endif</td>
                @isset($reviewable)
                    <td>
                        @if($r->status === 'pending')
                            <form class="inline" method="POST" action="{{ route('adviser.registrations.review', $r) }}">@csrf<input type="hidden" name="decision" value="approved"><button class="btn small">Approve</button></form>
                            <form class="inline" method="POST" action="{{ route('adviser.registrations.review', $r) }}">@csrf<input type="hidden" name="decision" value="rejected"><button class="btn small danger">Reject</button></form>
                        @endif
                    </td>
                @endisset
            </tr>
        @endforeach
    </table></div>
@empty
    <div class="card" style="color:var(--muted)">No registrations yet.</div>
@endforelse
