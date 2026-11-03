{{-- Expects $summary from GpaService::summary(). Only published results appear here. --}}
@forelse($summary['semesters'] as $sem)
    <h3 style="margin:18px 0 6px">{{ $sem['session'] }} · {{ $sem['semester'] == 1 ? 'First' : 'Second' }} semester</h3>
    <div class="table-wrap"><table>
        <tr><th>Course</th><th>Title</th><th>Units</th><th>Score</th><th>Grade</th><th>GP</th></tr>
        @foreach($sem['results'] as $r)
            <tr>
                <td>{{ $r->registration->course->label() }}</td>
                <td>{{ $r->registration->course->title }}</td>
                <td>{{ $r->registration->course->units }}</td>
                @if($r->flag === 'withheld')
                    <td colspan="3"><em>Withheld</em></td>
                @elseif(! $r->isGraded())
                    <td colspan="3"><em>{{ ucfirst($r->flag ?? 'pending') }}</em></td>
                @else
                    <td>{{ rtrim(rtrim(number_format($r->total, 2), '0'), '.') }}</td>
                    <td><strong>{{ $r->grade }}</strong>@if($r->flag === 'absent') <small>(absent)</small>@endif</td>
                    <td>{{ $r->grade_point }}</td>
                @endif
            </tr>
        @endforeach
    </table></div>
    <p style="margin:6px 0">TCU {{ $sem['tcu'] }} · TGP {{ $sem['tgp'] }} · <strong>GPA {{ $sem['gpa'] !== null ? number_format($sem['gpa'], 2) : '–' }}</strong> · CGPA {{ $sem['cgpa'] !== null ? number_format($sem['cgpa'], 2) : '–' }}</p>
@empty
    <div class="card" style="color:var(--muted)">No published results yet.</div>
@endforelse

@if($summary['semesters'])
    <div class="card" style="margin-top:16px">
        <strong>Cumulative:</strong>
        CGPA <strong>{{ $summary['cgpa'] !== null ? number_format($summary['cgpa'], 2) : '–' }}</strong>
        · Credits attempted {{ $summary['tcu'] }} · Credits passed {{ $summary['credits_passed'] }}
    </div>
@endif
