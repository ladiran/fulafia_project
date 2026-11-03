<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Broadsheet {{ $level }}L {{ $session }}</title>
<style>
    @page { margin: 26px 28px 34px 28px; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 6.4px; color: #000; }
    #footer { position: fixed; bottom: -22px; left: 0; right: 0; font-size: 6px; color: #444; }
    #footer .page:before { content: counter(page); }
    .top { text-align: center; margin-bottom: 4px; }
    .top img { height: 54px; }
    .top .u { font-size: 9px; font-weight: bold; }
    .info { width: 100%; font-weight: bold; font-size: 7px; margin: 5px 0 3px; }
    .info td { padding: 0; }
    .warn { border: 1px solid #a12626; color: #a12626; font-weight: bold; font-size: 7.5px; text-align: center; padding: 3px; margin: 3px 0; }
    table.bs { border-collapse: collapse; width: 100%; }
    table.bs th, table.bs td { border: 0.5px solid #555; padding: 1.2px 1px; text-align: center; }
    table.bs td.mat { white-space: nowrap; text-align: left; padding-left: 2px; }
    table.bs thead { display: table-header-group; }
    table.bs tr { page-break-inside: avoid; }
    table.bs th { font-size: 5.8px; }
    .gp { background: #ffff66; }
    .code { font-size: 6.2px; font-weight: bold; line-height: 1.15; }
    table.bs td.rem { text-align: left; font-size: 5.8px; padding-left: 3px; }
    .l { text-align: left; }
    .sum { margin-top: 10px; font-size: 7px; }
    .sum td { padding: 1px 6px 1px 0; }
    .sig { margin-top: 14px; font-size: 7px; }
    .sig td { padding: 8px 14px 0 0; }
    .legend { margin-top: 8px; font-size: 7px; }
</style>
</head>
<body>
<div id="footer">{{ config('portal.department') }} · Level {{ $level }} broadsheet · {{ $session }} · generated {{ $generated_at->format('j M Y, H:i') }} by {{ $generated_by }} · page <span class="page"></span></div>

<div class="top">
    <img src="{{ public_path('images/fulafia-logo.png') }}" alt="">
    <div class="u">{{ strtoupper(config('portal.university')) }}</div>
    <div class="u" style="font-size:7.5px">{{ strtoupper(config('portal.faculty')) }}</div>
</div>

<table class="info"><tr>
    <td style="width:30%">DEPARTMENT: &nbsp; {{ strtoupper(preg_replace('/^Department of /i', '', config('portal.department'))) }}</td>
    <td style="text-align:center">EXAM BROADSHEET, {{ $semester_label }} SEMESTER, {{ $session }}</td>
    <td style="width:25%;text-align:right">LEVEL: {{ $level }} LEVEL</td>
</tr></table>

@if($unpublished)
    <div class="warn">INCOMPLETE: results are not published yet for {{ implode(', ', $unpublished) }}. Those courses are left out. Do not submit this broadsheet to Senate.</div>
@endif

<table class="bs">
    <thead>
        <tr>
            <th rowspan="3" style="width:16px">S/N</th><th rowspan="3" style="width:56px">MAT. NO.</th>
            @foreach($courses as $c)
                <th colspan="2" class="code">{!! implode('<br>', explode(' ', e($c['code']), 2)) !!}@if($c['elective'])*@endif</th>
            @endforeach
            @if($has_previous)<th colspan="4" rowspan="2">PREVIOUS CUMULATIVE</th>@endif
            <th colspan="4" rowspan="2">CURRENT SEMESTER</th>
            @if($has_previous)<th colspan="4" rowspan="2">CURRENT CUMULATIVE</th>@endif
            <th rowspan="3" style="width:{{ $has_previous ? 22 : 28 }}%">REMARKS</th>
        </tr>
        <tr>@foreach($courses as $c)<th colspan="2">{{ $c['units'] }}</th>@endforeach</tr>
        <tr>
            @foreach($courses as $c)<th style="width:15px">SCR</th><th style="width:9px">GP</th>@endforeach
            @if($has_previous)@foreach(['CTCR', 'CTCE', 'CTGP', 'CGPA'] as $h)<th style="width:15px">{{ $h }}</th>@endforeach @endif
            @foreach(['TCR', 'TCE', 'TGP', 'GPA'] as $h)<th style="width:15px">{{ $h }}</th>@endforeach
            @if($has_previous)@foreach(['CTCR', 'CTCE', 'CTGP', 'CGPA'] as $h)<th style="width:15px">{{ $h }}</th>@endforeach @endif
        </tr>
    </thead>
    <tbody>
    @foreach($rows as $r)
        <tr>
            <td>{{ $r['sn'] }}</td><td class="mat">{{ $r['matric'] }}</td>
            @foreach($courses as $c)
                @php($cell = $r['cells'][$c['id']] ?? null)
                @if($cell)<td>{{ $cell['score'] }}</td><td class="gp">{{ $cell['gp'] }}</td>@else<td>-</td><td class="gp">-</td>@endif
            @endforeach
            @if($has_previous)
                <td>{{ $r['prev']['ctcr'] }}</td><td>{{ $r['prev']['ctce'] }}</td><td>{{ $r['prev']['ctgp'] }}</td><td>{{ $r['prev']['cgpa'] !== null ? number_format($r['prev']['cgpa'], 2) : '' }}</td>
            @endif
            <td>{{ $r['cur']['tcr'] }}</td><td>{{ $r['cur']['tce'] }}</td><td>{{ $r['cur']['tgp'] }}</td><td>{{ $r['cur']['gpa'] !== null ? number_format($r['cur']['gpa'], 2) : '' }}</td>
            @if($has_previous)
                <td>{{ $r['cum']['ctcr'] }}</td><td>{{ $r['cum']['ctce'] }}</td><td>{{ $r['cum']['ctgp'] }}</td><td>{{ $r['cum']['cgpa'] !== null ? number_format($r['cum']['cgpa'], 2) : '' }}</td>
            @endif
            <td class="rem">{{ $r['remark'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<div style="page-break-inside: avoid">
<table style="width:100%"><tr><td style="vertical-align:top">
    <table class="sum">
        <tr><td colspan="3"><strong>SUMMARY</strong></td></tr>
        <tr><td>NO. PASS</td><td>=</td><td>{{ sprintf('%02d', $summary['pass']) }}</td></tr>
        <tr><td>NO. TO REPEAT COURSES</td><td>=</td><td>{{ sprintf('%02d', $summary['repeat']) }}</td></tr>
        <tr><td>NO. WITH UNREGISTERED COURSES</td><td>=</td><td>{{ sprintf('%02d', $summary['unregistered']) }}</td></tr>
        <tr><td>NO. WITH REPEAT &amp; UNREGISTERED COURSES</td><td>=</td><td>{{ sprintf('%02d', $summary['both']) }}</td></tr>
        <tr><td>NO. ON PROBATION</td><td>=</td><td>{{ sprintf('%02d', $summary['probation']) }}</td></tr>
        <tr><td>NO. TO WITHDRAW</td><td>=</td><td>{{ sprintf('%02d', $summary['withdraw']) }}</td></tr>
        <tr><td><strong>TOTAL</strong></td><td>=</td><td><strong>{{ sprintf('%02d', $summary['total']) }}</strong></td></tr>
    </table>
</td>
@if($classes !== null)
<td style="vertical-align:top;width:30%">
    <table class="sum">
        <tr><td colspan="3"><strong>CLASS OF DEGREE</strong></td></tr>
        @foreach(array_column(config('portal.degree_classes'), 1) as $label)
            <tr><td>{{ strtoupper($label) }}</td><td>=</td><td>{{ $classes[$label] ?? 0 }}</td></tr>
        @endforeach
        <tr><td><strong>TOTAL</strong></td><td>=</td><td><strong>{{ array_sum($classes) }}</strong></td></tr>
    </table>
</td>
@endif
</tr></table>

<table class="sig">
    <tr><td>HEAD OF DEPARTMENT: ______________________________________</td><td>DATE: ______________________</td></tr>
    <tr><td style="padding-left:130px;padding-top:1px">NAME/SIGN</td><td></td></tr>
    <tr><td>DEAN: ______________________________________</td><td>DATE: ______________________</td></tr>
    <tr><td style="padding-left:60px;padding-top:1px">NAME/SIGN</td><td></td></tr>
</table>
<div class="legend"><strong>LEGEND:</strong> &nbsp; - = Not registered &nbsp;·&nbsp; * = Elective &nbsp;·&nbsp; ABS = Absent &nbsp;·&nbsp; INC = Incomplete &nbsp;·&nbsp; WH = Withheld &nbsp;·&nbsp; RPT = repeat &nbsp;·&nbsp; TAKE = registered course missing<br>
    TCR total credits registered, TCE total credits earned, TGP total grade points, GPA grade point average, CTCR/CTCE/CTGP/CGPA the cumulative equivalents.</div>
</div>
</body>
</html>
