{{-- Expects $graduate with results. Read from the frozen record only. --}}
<div class="card">
    <strong style="font-size:1.1rem">{{ $graduate->name }}</strong><br>
    Matric no.: {{ $graduate->matric }}<br>
    Degree: <strong>{{ $graduate->degree }}</strong>@if($graduate->specialization) ({{ $graduate->specialization }})@endif<br>
    Class of degree: <strong>{{ $graduate->class_of_degree }}</strong> · Final CGPA <strong>{{ number_format($graduate->cgpa, 2) }}</strong><br>
    Graduated: {{ $graduate->graduation_session }} session, {{ $graduate->graduation_year }}<br>
    Credits registered {{ $graduate->credits_registered }} · passed {{ $graduate->credits_passed }}<br>
    Verification code: <strong>{{ $graduate->verification_code }}</strong> (verify at {{ url('/verify') }})
    @if($graduate->isAmended())
        <br><span class="meta">This record was corrected {{ $graduate->amendments->count() }} time(s) with the Head of Department's approval (latest {{ $graduate->amendments->last()->decided_at->format('j M Y') }}). Corrected scores are marked †.</span>
    @endif
</div>

@foreach($graduate->results->groupBy(fn ($r) => $r->session.'|'.$r->semester) as $key => $rows)
    @php([$session, $semester] = explode('|', $key))
    <h3 style="margin:18px 0 6px">{{ $session }} · {{ $semester == 1 ? 'First' : 'Second' }} semester</h3>
    <div class="table-wrap"><table>
        <tr><th>Course</th><th>Title</th><th>Units</th><th>Score</th><th>Grade</th></tr>
        @foreach($rows as $r)
            <tr>
                <td>{{ $r->course_code }} ({{ $r->course_type }})</td><td>{{ $r->course_title }}</td><td>{{ $r->units }}</td>
                @if($r->exempted)<td colspan="2"><em>Exempted (transfer credit)</em></td>
                @elseif($r->waived)<td colspan="2"><em>Waived by the HoD</em></td>
                @elseif($r->grade === null)<td colspan="2"><em>{{ ucfirst($r->flag ?? 'n/a') }}</em></td>
                @else<td>{{ rtrim(rtrim(number_format($r->total, 2), '0'), '.') }}@if($r->getAttribute('corrected')) †@endif</td><td><strong>{{ $r->grade }}</strong></td>@endif
            </tr>
        @endforeach
    </table></div>
@endforeach
