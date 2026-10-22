<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $company }} · Timesheet · {{ $title }}</title>
    <style>
        @page { margin: 20mm 14mm 16mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5pt; color: #0f172a; }
        header { position: fixed; top: -13mm; left: 0; right: 0; height: 10mm; border-bottom: 2px solid #1d4ed8; }
        header .company { font-size: 12pt; font-weight: bold; color: #1d4ed8; }
        header .doc { float: right; font-size: 9pt; color: #475569; margin-top: 3pt; }
        footer { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7pt; color: #64748b; }
        footer table { width: 100%; border-collapse: collapse; }
        footer td { padding: 0; }
        footer .page { text-align: right; width: 15%; }
        footer .page:after { content: "Page " counter(page); }
        h1 { font-size: 15pt; margin: 0 0 2pt; }
        .meta { color: #64748b; margin-bottom: 10pt; }
        .totals { width: 100%; margin-bottom: 12pt; border-collapse: separate; border-spacing: 6pt 0; margin-left: -6pt; }
        .totals td { background: #f1f5f9; padding: 6pt 8pt; width: 16.6%; }
        .totals .label { font-size: 7pt; color: #64748b; text-transform: uppercase; }
        .totals .value { font-size: 13pt; font-weight: bold; }
        h2 { font-size: 10pt; margin: 12pt 0 4pt; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #e2e8f0; text-align: left; padding: 4pt 5pt; font-size: 7.5pt; }
        table.data td { border-bottom: 0.5pt solid #e2e8f0; padding: 3.5pt 5pt; }
        table.data tr:nth-child(even) td { background: #f8fafc; }
        .num, table.data th.num { text-align: right; white-space: nowrap; }
        .rtl { white-space: nowrap; }
        .signatures { width: 100%; margin-top: 8pt; page-break-inside: avoid; }
        .signatures td { width: 33%; padding-right: 20pt; vertical-align: bottom; }
        .line { border-top: 0.75pt solid #0f172a; padding-top: 3pt; margin-top: 20pt; font-size: 8pt; }
        .muted { color: #64748b; }
    </style>
</head>
<body>
<header>
    <span class="company">{{ $company }}</span>
    <span class="doc">Timesheet · {{ $title }}</span>
</header>
<footer>
    <table><tr>
        <td>Generated {{ $generatedAt }} ({{ $timezone }}) · Overtime is time worked beyond the scheduled shift length.</td>
        <td class="page"></td>
    </tr></table>
</footer>

<h1>Timesheet: {{ $title }}</h1>
<div class="meta">{{ ucfirst($period) }} report · times in {{ $timezone }}</div>

<table class="totals">
    <tr>
        <td><div class="label">Technicians</div><div class="value">{{ $totals['technicians'] }}</div></td>
        <td><div class="label">Shifts worked</div><div class="value">{{ $totals['shifts'] }}</div></td>
        <td><div class="label">Total hours</div><div class="value">{{ number_format($totals['workedHours'], 2) }}</div></td>
        <td><div class="label">Regular hours</div><div class="value">{{ number_format($totals['regularHours'], 2) }}</div></td>
        <td><div class="label">Overtime hours</div><div class="value">{{ number_format($totals['overtimeHours'], 2) }}</div></td>
        <td><div class="label">Absences</div><div class="value">{{ $totals['absences'] }}</div></td>
    </tr>
</table>

@if (count($departments))
    <h2>By department</h2>
    <table class="data" style="width: 55%">
        <tr><th>Department</th><th class="num">Technicians</th><th class="num">Total hours</th><th class="num">Overtime hours</th></tr>
        @foreach ($departments as $d)
            <tr>
                <td>{{ $d['name'] }}</td>
                <td class="num">{{ $d['technicians'] }}</td>
                <td class="num">{{ number_format($d['workedHours'], 2) }}</td>
                <td class="num">{{ number_format($d['overtimeHours'], 2) }}</td>
            </tr>
        @endforeach
    </table>
@endif

<h2>{{ $period === 'daily' ? 'Shifts' : ($period === 'weekly' ? 'Hours by day' : 'Technicians') }}</h2>
@if (count($rows))
    @php
        // The daily table has many columns; keep the ones that matter on paper.
        $keep = $period === 'daily'
            ? array_keys(array_intersect($header, ['Technician', 'Department', 'Shift', 'Status', 'Check-in', 'Check-out', 'Worked hours', 'Regular hours', 'Overtime hours', 'Moving hours', 'GPS coverage', 'Notes']))
            : array_keys($header);
    @endphp
    <table class="data">
        <tr>
            @foreach ($keep as $i)
                <th class="{{ is_numeric($rows[0][$i] ?? null) ? 'num' : '' }}">{{ $header[$i] }}</th>
            @endforeach
        </tr>
        @foreach ($rows as $row)
            <tr>
                @foreach ($keep as $i)
                    @php $v = $row[$i]; @endphp
                    {{-- Pre-shaped Arabic is in visual order, so wrapping would put its lines in the wrong order. --}}
                    <td class="{{ is_numeric($v) ? 'num' : (is_string($v) && preg_match('/\p{Arabic}/u', $v) ? 'rtl' : '') }}">{{ is_float($v) ? number_format($v, 2) : $v }}</td>
                @endforeach
            </tr>
        @endforeach
    </table>
@else
    <p class="muted">No shifts were worked in this period.</p>
@endif

<table class="signatures">
    <tr>
        <td><div class="line">Prepared by (name, signature, date)</div></td>
        <td><div class="line">Supervisor approval</div></td>
        <td><div class="line">Payroll / HR</div></td>
    </tr>
</table>
</body>
</html>
