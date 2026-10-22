<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Models\Department;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Timesheets\TimesheetExporter;
use App\Services\Timesheets\TimesheetReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class TimesheetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Shift $shift;

    private Department $plumbing;

    protected function setUp(): void
    {
        parent::setUp();
        config(['smc.shifts.timezone' => 'Asia/Riyadh', 'smc.company_name' => 'Test Co']);
        $this->travelTo('2026-10-21 10:00:00'); // Wed 13:00 Riyadh

        $this->admin = User::factory()->admin()->create();
        $this->shift = Shift::factory()->create(['name' => 'Shift A']); // 08:00-16:00 Riyadh = 05:00-13:00 UTC, 8h
        $this->plumbing = Department::factory()->create(['name' => 'Plumbing']);
    }

    /** A finished shift: checked in/out at the given UTC times. */
    private function worked(User $user, string $date, string $in, string $out): ShiftAssignment
    {
        $a = ShiftAssignment::factory()->for($user)->for($this->shift)->create(['shift_date' => $date]);
        $a->forceFill(['status' => AssignmentStatus::Active, 'checked_in_at' => CarbonImmutable::parse("{$date} {$in}")])->save();
        $a->complete(CarbonImmutable::parse("{$date} {$out}"), 24.7, 46.6);

        return $a;
    }

    private function reportUrl(array $params): string
    {
        return '/api/v1/reports/timesheet?'.http_build_query($params);
    }

    public function test_daily_report_lists_completed_in_progress_and_absent_shifts(): void
    {
        $ali = User::factory()->create(['full_name' => 'Ali', 'department_id' => $this->plumbing->id]);
        $bo = User::factory()->create(['full_name' => 'Bo']);
        $cy = User::factory()->create(['full_name' => 'Cy']);

        $this->worked($ali, '2026-10-20', '04:45:00', '14:00:00'); // previous day: excluded
        $done = $this->worked($ali, '2026-10-21', '04:50:00', '07:20:00'); // 2.5h, left early

        $live = ShiftAssignment::factory()->for($bo)->for($this->shift)->create(['shift_date' => '2026-10-21']);
        $live->forceFill(['status' => AssignmentStatus::Active, 'checked_in_at' => now()->subHours(4)->subMinutes(30)])->save();

        ShiftAssignment::factory()->for($cy)->for($this->shift)->create(['shift_date' => '2026-10-21', 'status' => AssignmentStatus::Absent]);

        $res = $this->actingAsUser($this->admin)->getJson($this->reportUrl(['period' => 'daily', 'startDate' => '2026-10-21']))->assertOk();

        $entries = collect($res->json('data.entries'))->keyBy('id');
        $this->assertCount(3, $entries);
        $this->assertSame(2.5, $entries[$done->id]['workedHours']);
        $this->assertSame('Plumbing', $entries[$done->id]['user']['department']['name']);
        $this->assertTrue($entries[$live->id]['inProgress']);
        $this->assertSame(4.5, $entries[$live->id]['workedHours']);
        $res->assertJsonPath('data.totals', [
            'technicians' => 3, 'shifts' => 2, 'absences' => 1, 'workedHours' => 7, 'regularHours' => 7, 'overtimeHours' => 0,
        ]);
    }

    public function test_daily_report_measures_moving_and_stationary_time_from_pings(): void
    {
        $tech = User::factory()->create();
        $a = $this->worked($tech, '2026-10-21', '05:00:00', '06:00:00');

        // 10 pings 30s apart moving, then 10 pings 30s apart parked, then a 10-minute tracking gap.
        $t = CarbonImmutable::parse('2026-10-21 05:00:00');
        $rows = [];
        foreach (range(0, 20) as $i) {
            $rows[] = ['user_id' => $tech->id, 'shift_assignment_id' => $a->id, 'latitude' => 24.7, 'longitude' => 46.6,
                'speed' => $i <= 10 ? 8.0 : 0.2, 'recorded_at' => $t->addSeconds($i * 30)->format('Y-m-d H:i:s.v')];
        }
        $rows[] = [...end($rows), 'recorded_at' => $t->addMinutes(20)->format('Y-m-d H:i:s.v')];
        DB::table('location_logs')->insert($rows);

        $entry = $this->actingAsUser($this->admin)->getJson($this->reportUrl(['period' => 'daily', 'startDate' => '2026-10-21']))->json('data.entries.0');

        $this->assertSame(0.08, $entry['movingHours']);      // 10 x 30s = 5 min
        $this->assertSame(0.08, $entry['stationaryHours']);  // 10 x 30s = 5 min; the 10-minute gap is not counted
        $this->assertSame(0.17, $entry['gpsCoverage']);      // 10 of 60 minutes tracked
    }

    public function test_weekly_report_builds_a_day_grid_with_overtime(): void
    {
        $ali = User::factory()->create(['full_name' => 'Ali']);
        $this->worked($ali, '2026-10-19', '05:00:00', '13:00:00'); // 8h
        $this->worked($ali, '2026-10-20', '05:00:00', '14:30:00'); // 9.5h -> 1.5 OT
        $this->worked($ali, '2026-10-26', '05:00:00', '13:00:00'); // next week: excluded

        $res = $this->actingAsUser($this->admin)->getJson($this->reportUrl(['period' => 'weekly', 'startDate' => '2026-10-21']))->assertOk();

        $res->assertJsonPath('data.startDate', '2026-10-19')->assertJsonPath('data.endDate', '2026-10-25')->assertJsonCount(7, 'data.dates');
        $person = $res->json('data.people.0');
        $this->assertSame(8, $person['byDate']['2026-10-19']);
        $this->assertSame(9.5, $person['byDate']['2026-10-20']);
        $this->assertSame(0, $person['byDate']['2026-10-21']);
        $this->assertSame([17.5, 16, 1.5], [$person['workedHours'], $person['regularHours'], $person['overtimeHours']]);
    }

    public function test_overnight_shift_counts_on_the_day_it_started(): void
    {
        $night = Shift::factory()->overnight()->create(); // 22:00-06:00 Riyadh = 19:00-03:00 UTC
        $tech = User::factory()->create();
        $a = ShiftAssignment::factory()->for($tech)->for($night)->create(['shift_date' => '2026-10-19']);
        $a->forceFill(['status' => AssignmentStatus::Active, 'checked_in_at' => CarbonImmutable::parse('2026-10-19 19:00:00')])->save();
        $a->complete(CarbonImmutable::parse('2026-10-20 03:00:00'), null, null);

        $byDate = $this->actingAsUser($this->admin)->getJson($this->reportUrl(['period' => 'weekly', 'startDate' => '2026-10-19']))->json('data.people.0.byDate');

        $this->assertSame(8, $byDate['2026-10-19']);
        $this->assertSame(0, $byDate['2026-10-20']);
    }

    public function test_monthly_summary_and_department_filter(): void
    {
        $ali = User::factory()->create(['full_name' => 'Ali', 'department_id' => $this->plumbing->id]);
        $bo = User::factory()->create(['full_name' => 'Bo']);
        $this->worked($ali, '2026-10-01', '05:00:00', '13:00:00');
        $this->worked($ali, '2026-10-02', '05:00:00', '11:00:00');
        ShiftAssignment::factory()->for($ali)->for($this->shift)->create(['shift_date' => '2026-10-03', 'status' => AssignmentStatus::Absent]);
        $this->worked($bo, '2026-10-02', '05:00:00', '13:00:00');
        $this->worked($ali, '2026-09-30', '05:00:00', '13:00:00'); // other month

        $this->actingAsUser($this->admin);
        $res = $this->getJson($this->reportUrl(['period' => 'monthly', 'startDate' => '2026-10-15']))->assertOk();
        $res->assertJsonPath('data.startDate', '2026-10-01')->assertJsonPath('data.endDate', '2026-10-31');

        $ali = collect($res->json('data.people'))->firstWhere('user.fullName', 'Ali');
        $this->assertSame([2, 1, 14, 7], [$ali['daysWorked'], $ali['absences'], $ali['workedHours'], $ali['averageDailyHours']]);

        $this->getJson($this->reportUrl(['period' => 'monthly', 'startDate' => '2026-10-15', 'departmentId' => $this->plumbing->id]))
            ->assertJsonCount(1, 'data.people')->assertJsonPath('data.totals.workedHours', 14);
    }

    public function test_custom_range_limits(): void
    {
        $this->actingAsUser($this->admin);
        $this->getJson($this->reportUrl(['period' => 'custom', 'startDate' => '2026-10-01']))->assertJsonValidationErrors('endDate');
        $this->getJson($this->reportUrl(['period' => 'custom', 'startDate' => '2026-01-01', 'endDate' => '2026-12-31']))->assertJsonPath('error', 'RANGE_TOO_LONG');
        $this->getJson($this->reportUrl(['period' => 'custom', 'startDate' => '2026-10-01', 'endDate' => '2026-10-15']))->assertOk()->assertJsonCount(15, 'data.dates');
    }

    public function test_csv_export_is_payroll_ready(): void
    {
        $this->worked(User::factory()->create(['full_name' => 'عبدالله Ali', 'phone_number' => '+966500000001']), '2026-10-21', '04:50:00', '14:05:00');

        $res = $this->actingAsUser($this->admin)->get('/api/v1/reports/timesheet/export?'.http_build_query(['period' => 'daily', 'startDate' => '2026-10-21', 'exportType' => 'csv']))
            ->assertOk()
            ->assertDownload('timesheet-2026-10-21.csv');

        $lines = array_map('str_getcsv', explode("\n", trim(substr($res->streamedContent(), 3))));
        $this->assertSame('Worked hours', $lines[0][14]);
        $this->assertSame(['2026-10-21', 'عبدالله Ali', '+966500000001'], array_slice($lines[1], 0, 3));
        $this->assertSame(['2026-10-21 07:50', '2026-10-21 17:05'], [$lines[1][8], $lines[1][11]]); // shown in Riyadh time
        $this->assertSame(['9.25', '8', '1.25'], array_slice($lines[1], 14, 3));
    }

    public function test_xlsx_export_has_summary_and_shift_sheets(): void
    {
        $this->worked(User::factory()->create(['full_name' => 'Ali']), '2026-10-05', '05:00:00', '13:00:00');

        $res = $this->actingAsUser($this->admin)->get('/api/v1/reports/timesheet/export?'.http_build_query(['period' => 'monthly', 'startDate' => '2026-10-01', 'exportType' => 'xlsx']))
            ->assertOk()
            ->assertDownload('timesheet-october-2026.xlsx');

        $path = tempnam(sys_get_temp_dir(), 'ts').'.xlsx';
        file_put_contents($path, $res->streamedContent());
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $sheets[$sheet->getName()] = array_map(fn ($r) => $r->toArray(), iterator_to_array($sheet->getRowIterator(), false));
        }
        $reader->close();
        unlink($path);

        $this->assertSame(['Summary', 'Shifts'], array_keys($sheets));
        $this->assertSame(['Ali', 1, 1, 0, 8], [$sheets['Summary'][1][0], $sheets['Summary'][1][3], $sheets['Summary'][1][4], $sheets['Summary'][1][5], $sheets['Summary'][1][6]]);
        $this->assertSame('2026-10-05', $sheets['Shifts'][1][0]);
    }

    public function test_pdf_export(): void
    {
        $this->worked(User::factory()->create(), '2026-10-21', '05:00:00', '13:00:00');

        $res = $this->actingAsUser($this->admin)->get('/api/v1/reports/timesheet/export?'.http_build_query(['period' => 'weekly', 'startDate' => '2026-10-21', 'exportType' => 'pdf']))
            ->assertOk()
            ->assertDownload('timesheet-week-2026-10-19.pdf');

        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_pdf_shapes_arabic_text(): void
    {
        $dept = Department::factory()->create(['name' => 'السباكة']);
        $this->worked(User::factory()->create(['full_name' => 'عبدالله الحربي', 'department_id' => $dept->id]), '2026-10-21', '05:00:00', '13:00:00');

        $exporter = new TimesheetExporter(new TimesheetReport('daily', '2026-10-21'));
        $shape = (new \ReflectionMethod($exporter, 'shape'))->getClosure($exporter);

        $shaped = $shape('عبدالله الحربي');
        $this->assertNotSame('عبدالله الحربي', $shaped);
        $this->assertMatchesRegularExpression('/[\x{FE70}-\x{FEFF}]/u', $shaped, 'uses joined Arabic presentation forms');
        $this->assertSame('Ali 8.25', $shape('Ali 8.25'), 'non-Arabic text is untouched');
        $this->assertSame(8.25, $shape(8.25));

        $this->actingAsUser($this->admin)
            ->get('/api/v1/reports/timesheet/export?'.http_build_query(['period' => 'daily', 'startDate' => '2026-10-21', 'exportType' => 'pdf']))
            ->assertOk();
    }

    public function test_dispatchers_can_run_reports_and_technicians_cannot(): void
    {
        $this->actingAsUser(User::factory()->dispatcher()->create())->getJson($this->reportUrl(['period' => 'daily', 'startDate' => '2026-10-21']))->assertOk();
        $this->actingAsUser(User::factory()->create())->getJson($this->reportUrl(['period' => 'daily', 'startDate' => '2026-10-21']))->assertForbidden();
    }
}
