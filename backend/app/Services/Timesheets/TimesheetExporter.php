<?php

namespace App\Services\Timesheets;

use ArPHP\I18N\Arabic;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MOD-20: CSV / Excel / PDF downloads of a TimesheetReport.
 *
 * The main table depends on the period, matching what the console shows:
 * daily = one row per shift, weekly = technician x weekday grid,
 * monthly/custom = one summary row per technician. Excel adds a second
 * sheet with every shift so payroll can drill down.
 */
class TimesheetExporter
{
    public function __construct(private readonly TimesheetReport $report) {}

    public function download(string $type): Response
    {
        $filename = 'timesheet-'.$this->report->slug().'.'.$type;

        return match ($type) {
            'csv' => $this->csv($filename),
            'xlsx' => $this->xlsx($filename),
            'pdf' => $this->pdf($filename),
        };
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>} header and rows of the period's main table */
    public function mainTable(): array
    {
        return match ($this->report->period) {
            'daily' => $this->shiftTable(),
            'weekly' => $this->weekGrid(),
            default => $this->summaryTable(),
        };
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>} */
    public function shiftTable(): array
    {
        $header = ['Date', 'Technician', 'Phone', 'Department', 'Shift', 'Status', 'Scheduled start', 'Scheduled end',
            'Check-in', 'Check-in lat', 'Check-in lng', 'Check-out', 'Check-out lat', 'Check-out lng',
            'Worked hours', 'Regular hours', 'Overtime hours', 'Moving hours', 'Stationary hours', 'GPS coverage', 'Notes'];

        $rows = $this->report->entries()->map(fn ($e) => [
            $e['date'],
            $e['user']['fullName'],
            $e['user']['phone'],
            $e['user']['department']['name'] ?? '',
            $e['shiftName'],
            $e['inProgress'] ? 'IN PROGRESS' : $e['status'],
            $this->local($e['scheduledStartAt']),
            $this->local($e['scheduledEndAt']),
            $this->local($e['checkedInAt']),
            $e['checkIn']['lat'] ?? '',
            $e['checkIn']['lng'] ?? '',
            $this->local($e['checkedOutAt']),
            $e['checkOut']['lat'] ?? '',
            $e['checkOut']['lng'] ?? '',
            $e['workedHours'],
            $e['regularHours'],
            $e['overtimeHours'],
            $e['movingHours'] ?? '',
            $e['stationaryHours'] ?? '',
            $e['gpsCoverage'] === null ? '' : round($e['gpsCoverage'] * 100).'%',
            implode('; ', array_filter([
                $e['autoCheckedOut'] ? 'Auto checked out' : null,
                $e['checkInOutsideZone'] ? 'Checked in outside zone' : null,
            ])),
        ])->all();

        return [$header, $rows];
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>} */
    private function weekGrid(): array
    {
        $dates = $this->report->dates();
        $header = ['Technician', 'Department',
            ...array_map(fn ($d) => CarbonImmutable::parse($d)->format('D j M'), $dates),
            'Week total', 'Regular', 'Overtime'];

        $rows = $this->report->people()->map(fn ($p) => [
            $p['user']['fullName'],
            $p['user']['department']['name'] ?? '',
            ...array_values($p['byDate']),
            $p['workedHours'],
            $p['regularHours'],
            $p['overtimeHours'],
        ])->all();

        return [$header, $rows];
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>} */
    private function summaryTable(): array
    {
        $header = ['Technician', 'Phone', 'Department', 'Days worked', 'Shifts', 'Absences',
            'Total hours', 'Regular hours', 'Overtime hours', 'Average daily hours'];

        $rows = $this->report->people()->map(fn ($p) => [
            $p['user']['fullName'],
            $p['user']['phone'],
            $p['user']['department']['name'] ?? '',
            $p['daysWorked'],
            $p['shifts'],
            $p['absences'],
            $p['workedHours'],
            $p['regularHours'],
            $p['overtimeHours'],
            $p['averageDailyHours'],
        ])->all();

        return [$header, $rows];
    }

    private function csv(string $filename): StreamedResponse
    {
        [$header, $rows] = $this->mainTable();

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Arabic names correctly
            fputcsv($out, $header, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, $row, escape: '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function xlsx(string $filename): StreamedResponse
    {
        return response()->streamDownload(function () {
            $bold = new Style(fontBold: true, backgroundColor: 'E2E8F0');
            $options = new Options;
            $writer = new Writer($options);
            $writer->openToFile('php://output');

            [$header, $rows] = $this->mainTable();
            $writer->getCurrentSheet()->setName(match ($this->report->period) {
                'daily' => 'Day',
                'weekly' => 'Week',
                default => 'Summary',
            });
            $writer->addRow(Row::fromValuesWithStyle($header, $bold));
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }

            if ($this->report->period !== 'daily') {
                [$header, $rows] = $this->shiftTable();
                $writer->addNewSheetAndMakeItCurrent()->setName('Shifts');
                $writer->addRow(Row::fromValuesWithStyle($header, $bold));
                foreach ($rows as $row) {
                    $writer->addRow(Row::fromValues($row));
                }
            }

            $writer->close();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function pdf(string $filename): Response
    {
        [$header, $rows] = $this->mainTable();

        return Pdf::loadView('reports.timesheet', [
            'company' => $this->shape(config('smc.company_name')),
            'title' => $this->report->title(),
            'period' => $this->report->period,
            'timezone' => config('smc.shifts.timezone'),
            'generatedAt' => now(config('smc.shifts.timezone'))->format('j M Y, H:i'),
            'totals' => $this->report->totals(),
            'departments' => $this->report->departments()->map(fn ($d) => [...$d, 'name' => $this->shape($d['name'])]),
            'header' => $header,
            'rows' => array_map(fn ($row) => array_map($this->shape(...), $row), $rows),
        ])
            // Embed only the glyphs used (the wrapper's default embeds whole fonts: ~900 KB per PDF).
            ->setOption('isFontSubsettingEnabled', true)
            ->setPaper('a4', 'landscape')
            ->download($filename);
    }

    /**
     * dompdf has no Arabic shaping or right-to-left layout, so Arabic text
     * (names, departments) would print as separate, reversed letters. Pre-shape
     * it into joined presentation forms in visual order. Other text is untouched.
     */
    private function shape(mixed $value): mixed
    {
        if (! is_string($value) || ! preg_match('/\p{Arabic}/u', $value)) {
            return $value;
        }

        static $arabic;
        $arabic ??= new Arabic;

        // No line wrapping (the table wraps), and keep Western digits.
        return $arabic->utf8Glyphs($value, 1000, false);
    }

    /** UTC ISO string → "YYYY-MM-DD HH:MM" in the business timezone. */
    private function local(?string $iso): string
    {
        return $iso ? CarbonImmutable::parse($iso)->setTimezone(config('smc.shifts.timezone'))->format('Y-m-d H:i') : '';
    }
}
