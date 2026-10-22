<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Attendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    private ShiftAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        config(['smc.shifts.timezone' => 'Asia/Riyadh']);
        $this->travelTo('2026-10-19 04:30:00'); // 07:30 Riyadh; shift is 08:00-16:00 (05:00-13:00 UTC)

        $this->tech = User::factory()->create();
        $this->assignment = ShiftAssignment::factory()->for($this->tech)->for(Shift::factory())->create(['shift_date' => '2026-10-19']);
    }

    private function checkIn(array $location = ['lat' => 24.7136, 'lng' => 46.6753])
    {
        return $this->postJson('/api/v1/shifts/check-in', ['assignmentId' => $this->assignment->id, 'location' => $location]);
    }

    public function test_my_shifts_lists_upcoming_then_active(): void
    {
        $this->actingAsUser($this->tech)->getJson('/api/v1/shifts/my-shifts')
            ->assertOk()->assertJsonPath('active', null)->assertJsonCount(1, 'upcoming')
            ->assertJsonPath('upcoming.0.shift.name', $this->assignment->shift->name)
            ->assertJsonPath('checkInOpensMinutesBefore', 60);

        $this->checkIn()->assertOk();
        $this->getJson('/api/v1/shifts/my-shifts')->assertJsonPath('active.id', $this->assignment->id)->assertJsonCount(0, 'upcoming');
    }

    public function test_check_in_opens_an_hour_before_start(): void
    {
        $this->travelTo('2026-10-19 03:30:00'); // 06:30 Riyadh
        $this->actingAsUser($this->tech);
        $this->checkIn()->assertUnprocessable()->assertJsonPath('error', 'TOO_EARLY')->assertJsonPath('opensAt', '2026-10-19T04:00:00+00:00');

        $this->travelTo('2026-10-19 04:00:00');
        $this->checkIn()->assertOk()->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.checkIn.lat', 24.7136);
    }

    public function test_cannot_check_in_after_the_shift_ended(): void
    {
        $this->travelTo('2026-10-19 13:00:00');
        $this->actingAsUser($this->tech)->checkIn()->assertJsonPath('error', 'SHIFT_ENDED');
    }

    public function test_only_one_active_shift_and_no_double_check_in(): void
    {
        $other = ShiftAssignment::factory()->for($this->tech)->for(Shift::factory()->create(['start_time' => '07:00:00', 'end_time' => '09:00:00']))->create(['shift_date' => '2026-10-19']);
        $this->actingAsUser($this->tech);

        $this->checkIn()->assertOk();
        $this->checkIn()->assertStatus(409)->assertJsonPath('error', 'SHIFT_NOT_SCHEDULED');
        $this->postJson('/api/v1/shifts/check-in', ['assignmentId' => $other->id, 'location' => ['lat' => 1, 'lng' => 1]])
            ->assertStatus(409)->assertJsonPath('error', 'ALREADY_CHECKED_IN');
    }

    public function test_cannot_check_in_to_someone_elses_shift(): void
    {
        $this->actingAsUser(User::factory()->create())->checkIn()->assertNotFound();
    }

    public function test_console_users_cannot_check_in(): void
    {
        $this->actingAsUser(User::factory()->admin()->create())->checkIn()->assertForbidden();
    }

    public function test_check_in_outside_zone_is_allowed_but_flagged(): void
    {
        $this->assignment->shift->update(['geofence' => [
            ['lat' => 24.70, 'lng' => 46.66], ['lat' => 24.72, 'lng' => 46.66], ['lat' => 24.72, 'lng' => 46.69], ['lat' => 24.70, 'lng' => 46.69],
        ]]);
        $this->actingAsUser($this->tech);

        $this->checkIn(['lat' => 24.80, 'lng' => 46.80])->assertOk()->assertJsonPath('data.checkInOutsideZone', true);
    }

    public function test_check_in_inside_zone_is_not_flagged(): void
    {
        $this->assignment->shift->update(['geofence' => [
            ['lat' => 24.70, 'lng' => 46.66], ['lat' => 24.72, 'lng' => 46.66], ['lat' => 24.72, 'lng' => 46.69], ['lat' => 24.70, 'lng' => 46.69],
        ]]);

        $this->actingAsUser($this->tech)->checkIn(['lat' => 24.71, 'lng' => 46.67])->assertJsonPath('data.checkInOutsideZone', false);
    }

    public function test_short_day_has_no_overtime(): void
    {
        $this->travelTo('2026-10-19 05:10:00');
        $this->actingAsUser($this->tech)->checkIn();
        $this->travelTo('2026-10-19 12:40:00'); // 7.5h worked of an 8h shift

        $this->postJson('/api/v1/shifts/check-out', ['assignmentId' => $this->assignment->id, 'location' => ['lat' => 24.7, 'lng' => 46.6]])
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.workedHours', 7.5)
            ->assertJsonPath('data.regularHours', 7.5)
            ->assertJsonPath('data.overtimeHours', 0);
    }

    public function test_time_beyond_shift_length_is_overtime(): void
    {
        $this->travelTo('2026-10-19 04:45:00'); // early check-in counts as worked time
        $this->actingAsUser($this->tech)->checkIn();
        $this->travelTo('2026-10-19 14:00:00'); // 9h15m worked of an 8h shift

        $this->postJson('/api/v1/shifts/check-out', ['assignmentId' => $this->assignment->id])
            ->assertOk()
            ->assertJsonPath('data.workedHours', 9.25)
            ->assertJsonPath('data.regularHours', 8)
            ->assertJsonPath('data.overtimeHours', 1.25)
            ->assertJsonPath('data.checkOut', null);
    }

    public function test_check_out_requires_being_checked_in(): void
    {
        $this->actingAsUser($this->tech)->postJson('/api/v1/shifts/check-out', ['assignmentId' => $this->assignment->id])
            ->assertStatus(409)->assertJsonPath('error', 'NOT_CHECKED_IN');
    }

    public function test_overdue_job_closes_forgotten_shifts_at_scheduled_end_and_marks_absences(): void
    {
        $missed = ShiftAssignment::factory()->for(User::factory())->for($this->assignment->shift)->create(['shift_date' => '2026-10-19']);
        $this->travelTo('2026-10-19 05:00:00');
        $this->actingAsUser($this->tech)->checkIn();

        $this->travelTo('2026-10-19 16:00:00'); // 3h after end: not yet (4h grace)
        $this->assertSame(['autoCheckedOut' => 0, 'absent' => 1], app(Attendance::class)->closeOverdue());

        $this->travelTo('2026-10-19 17:01:00');
        $this->assertSame(['autoCheckedOut' => 1, 'absent' => 0], app(Attendance::class)->closeOverdue());

        $a = $this->assignment->fresh();
        $this->assertSame(AssignmentStatus::Completed, $a->status);
        $this->assertTrue($a->auto_checked_out);
        $this->assertSame('2026-10-19 13:00:00', $a->checked_out_at->format('Y-m-d H:i:s'));
        $this->assertSame([480, 480, 0], [$a->worked_minutes, $a->regular_minutes, $a->overtime_minutes]);
        $this->assertSame(AssignmentStatus::Absent, $missed->fresh()->status);
    }
}
