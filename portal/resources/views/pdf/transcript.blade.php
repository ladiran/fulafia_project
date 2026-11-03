<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Departmental transcript: {{ $person['matric'] }}</title>
<style>
    @page { margin: 78px 40px 62px 40px; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5px; color: #1f2328; }
    #banner { position: fixed; top: -58px; left: 0; right: 0; text-align: center; background: #6d1520; color: #fff; padding: 5px 0; font-size: 9px; letter-spacing: .06em; }
    #footer { position: fixed; bottom: -44px; left: 0; right: 0; border-top: 1px solid #b9b2a0; padding-top: 4px; font-size: 8px; color: #555; }
    #footer .page:before { content: counter(page); }
    .head { width: 100%; border-bottom: 3px solid #ae8b35; padding-bottom: 8px; margin-bottom: 10px; }
    .head td { vertical-align: middle; }
    .head img { height: 58px; }
    .title { font-size: 15px; font-weight: bold; color: #4f0e17; }
    .sub { font-size: 10px; color: #444; }
    h2 { font-size: 11px; color: #4f0e17; margin: 12px 0 4px; }
    table.info { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    table.info td { padding: 2px 6px 2px 0; vertical-align: top; }
    table.info td.k { color: #666; width: 18%; }
    table.grid { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    table.grid th { background: #efe9da; text-align: left; font-size: 8px; text-transform: uppercase; padding: 3px 5px; border: 1px solid #cfc7b2; }
    table.grid td { padding: 3px 5px; border: 1px solid #ddd6c4; }
    .num { text-align: right; }
    .ctr { text-align: center; }
    .note { color: #777; font-style: italic; }
    .sem { margin-top: 10px; page-break-inside: avoid; }
    .sem-title { font-weight: bold; margin-bottom: 3px; }
    .totals { font-size: 9px; margin: 2px 0 0; }
    .award { border: 1px solid #ae8b35; background: #faf6ea; padding: 7px 10px; margin: 8px 0; }
    .warn { border: 1px solid #b04a4a; background: #fdeeee; color: #7a1f1f; padding: 6px 10px; margin: 8px 0; font-weight: bold; }
    .key { font-size: 8px; color: #555; margin-top: 10px; }
</style>
</head>
<body>
<div id="banner">DEPARTMENTAL COPY · NOT AN OFFICIAL TRANSCRIPT · FOR DEPARTMENTAL USE ONLY</div>
<div id="footer">
    Not an official transcript. For departmental use only. Do not issue to the student or to a third party.<br>
    Generated {{ $generated_at->format('j M Y, H:i') }} by {{ $generated_by }} · Page <span class="page"></span>
</div>

<table class="head"><tr>
    <td style="width:70px"><img src="{{ public_path('images/fulafia-logo.png') }}" alt=""></td>
    <td>
        <div class="title">{{ config('portal.university') }}</div>
        <div class="sub">{{ config('portal.faculty') }} · {{ config('portal.department') }}</div>
        <div class="sub"><strong>Departmental transcript</strong> ({{ $kind === 'graduate' ? 'graduate record' : 'interim: published results to date' }})</div>
    </td>
</tr></table>

<div class="warn">Working copy for the department. This is not an official transcript and must not be given out as one.</div>

<table class="info">
    <tr><td class="k">Name</td><td><strong>{{ $person['name'] }}</strong>@if($person['formerly']) <span class="note">(formerly {{ implode(', ', $person['formerly']) }})</span>@endif</td>
        <td class="k">Matric no.</td><td><strong>{{ $person['matric'] }}</strong></td></tr>
    <tr><td class="k">Gender</td><td>{{ $person['gender'] }}</td><td class="k">Admission</td><td>{{ $person['admission'] }}</td></tr>
    <tr><td class="k">Session admitted</td><td>{{ $person['entry_session'] ?: '–' }}</td><td class="k">Level / status</td><td>{{ $person['level'] }} · {{ $person['status'] }}</td></tr>
    <tr><td class="k">Specialization</td><td colspan="3">{{ $person['specialization'] ?: '–' }}</td></tr>
</table>

@if($award)
    <div class="award">
        <strong>{{ $award['degree'] }}</strong> · {{ $award['class'] }} · Final CGPA <strong>{{ number_format($award['cgpa'], 2) }}</strong><br>
        Graduated {{ $award['session'] }} session ({{ $award['year'] }}) · Credits registered {{ $award['credits_registered'] }} · Credits passed {{ $award['credits_passed'] }}
    </div>
@endif

@if(! empty($corrections))
    <div class="award" style="background:#eef3fb;border-color:#6d86b4">
        <strong>Corrections to this record (approved by the HoD):</strong>
        @foreach($corrections as $line)<div>{{ $line }}</div>@endforeach
    </div>
@endif

@forelse($semesters as $sem)
    <div class="sem">
        <div class="sem-title">{{ $sem['label'] }}</div>
        <table class="grid">
            <tr><th style="width:11%">Code</th><th>Title</th><th class="ctr" style="width:6%">Type</th><th class="ctr" style="width:7%">Units</th><th class="ctr" style="width:8%">Score</th><th class="ctr" style="width:7%">Grade</th><th class="ctr" style="width:5%">GP</th></tr>
            @foreach($sem['rows'] as $r)
                <tr>
                    <td>{{ $r['code'] }}</td><td>{{ $r['title'] }}</td><td class="ctr">{{ $r['type'] }}</td><td class="ctr">{{ $r['units'] }}</td>
                    @if($r['grade'] === null)
                        <td class="ctr note" colspan="3">{{ $r['note'] ?? '–' }}</td>
                    @else
                        <td class="ctr">{{ $r['score'] }}</td><td class="ctr"><strong>{{ $r['grade'] }}</strong>@if($r['note']) <span class="note">({{ strtolower($r['note']) }})</span>@endif</td><td class="ctr">{{ $r['gp'] }}</td>
                    @endif
                </tr>
            @endforeach
        </table>
        <div class="totals">TCU {{ $sem['tcu'] }} · TGP {{ $sem['tgp'] }} · <strong>GPA {{ $sem['gpa'] !== null ? number_format($sem['gpa'], 2) : '–' }}</strong> · CGPA {{ $sem['cgpa'] !== null ? number_format($sem['cgpa'], 2) : '–' }}</div>
    </div>
@empty
    <p class="note">No published results yet.</p>
@endforelse

<h2>Cumulative</h2>
<table class="info">
    <tr><td class="k">CGPA</td><td><strong>{{ $totals['cgpa'] !== null ? number_format($totals['cgpa'], 2) : '–' }}</strong></td>
        <td class="k">Credits attempted</td><td>{{ $totals['tcu'] }}</td>
        <td class="k">Credits passed</td><td>{{ $totals['credits_passed'] }}</td></tr>
</table>

<div class="key">
    Key: TCU total credit units, TGP total grade points, GPA grade point average, CGPA cumulative GPA. Type C = core, E = elective.
    Grades: A 70 and above (5), B 60 to 69 (4), C 50 to 59 (3), D 45 to 49 (2), E 40 to 44 (1), F below 40 (0).
    Withheld, incomplete, waived and exempted courses carry no grade point and are left out of the GPA.
</div>
</body>
</html>
