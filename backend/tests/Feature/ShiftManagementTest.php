<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Models\Department;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['smc.shifts.timezone' => 'Asia/Riyadh']); // UTC+3, no DST
        $this->travelTo('2026-10-19 06:00:00'); // Monday 09:00 in Riyadh
        $this->admin = User::factory()->admin()->create();
    }

    // ---- templates ------------------------------------------------------

    public function test_admin_creates_shift_template(): void
    {
        $this->actingAsUser($this->admin)->postJson('/api/v1/shifts', [
            'name' => 'Shift A', 'startTime' => '08:00', 'endTime' => '16:00', 'daysOfWeek' => [5, 1, 2],
        ])->assertCreated()
            ->assertJsonPath('data.startTime', '08:00')
            ->assertJsonPath('data.daysOfWeek', [1, 2, 5])
            ->assertJsonPath('data.lengthMinutes', 480)
            ->assertJsonPath('data.crossesMidnight', false)
            ->assertJsonPath('data.timezone', 'Asia/Riyadh');
    }

    public function test_overnight_shift_length(): void
    {
        $this->actingAsUser($this->admin)->postJson('/api/v1/shifts', [
            'name' => 'Night', 'startTime' => '16:00', 'endTime' => '00:00', 'daysOfWeek' => [1],
        ])->assertCreated()->assertJsonPath('data.crossesMidnight', true)->assertJsonPath('data.lengthMinutes', 480);
    }

    public function test_template_validation(): void
    {
        $this->actingAsUser($this->admin)->postJson('/api/v1/shifts', [
            'name' => 'Bad', 'startTime' => '08:00', 'endTime' => '08:00', 'daysOfWeek' => [0, 8],
            'geofence' => [['lat' => 24.7, 'lng' => 46.6]],
        ])->assertJsonValidationErrors(['endTime', 'daysOfWeek.0', 'daysOfWeek.1', 'geofence']);
    }

    public function test_shift_with_assignments_cannot_be_deleted_but_can_be_deactivated(): void
    {
        $shift = Shift::factory()->create();
        ShiftAssignment::factory()->for($shift)->create();
        $this->actingAsUser($this->admin);

        $this->deleteJson("/api/v1/shifts/{$shift->id}")->assertStatus(409)->assertJsonPath('error', 'SHIFT_IN_USE');
        $this->putJson("/api/v1/shifts/{$shift->id}", ['isActive' => false])->assertOk()->assertJsonPath('data.isActive', false);
        $this->deleteJson('/api/v1/shifts/'.Shift::factory()->create()->id)->assertNoContent();
    }

    public function test_dispatcher_reads_shifts_and_roster_but_cannot_change_them(): void
    {
        $this->actingAsUser(User::factory()->dispatcher()->create());

        $this->getJson('/api/v1/shifts')->assertOk();
        $this->getJson('/api/v1/shifts/roster')->assertOk();
        $this->postJson('/api/v1/shifts', ['name' => 'X', 'startTime' => '08:00', 'endTime' => '09:00', 'daysOfWeek' => [1]])->assertForbidden();
    }

    // ---- assignment -----------------------------------------------------

    public function test_assigning_a_week_respects_days_and_stores_utc_windows(): void
    {
        $shift = Shift::factory()->create(['days_of_week' => [1, 3, 5]]); // Mon, Wed, Fri 08:00-16:00 Riyadh
        $tech = User::factory()->create();

        $this->actingAsUser($this->admin)->postJson('/api/v1/shifts/assign', [
            'shiftId' => $shift->id, 'userIds' => [$tech->id], 'startDate' => '2026-10-19', 'endDate' => '2026-10-25',
        ])->assertCreated()->assertJson(['created' => 3, 'skipped' => [], 'technicians' => 1]);

        $monday = ShiftAssignment::where('shift_date', '2026-10-19')->first();
        $this->assertSame('2026-10-19 05:00:00', $monday->scheduled_start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-19 13:00:00', $monday->scheduled_end_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->admin->id, $monday->assigned_by);
    }

    public function test_overnight_assignment_ends_next_day(): void
    {
        $shift = Shift::factory()->overnight()->create(); // 22:00-06:00
        $tech = User::factory()->create();

        $this->actingAsUser($this->admin)->postJson('/api/v1/shifts/assign', [
            'shiftId' => $shift->id, 'userIds' => [$tech->id], 'startDate' => '2026-10-19', 'endDate' => '2026-10-19',
        ])->assertCreated();

        $a = ShiftAssignment::first();
        $this->assertSame('2026-10-19 19:00:00', $a->scheduled_start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-20 03:00:00', $a->scheduled_end_at->format('Y-m-d H:i:s'));
    }

    public function test_duplicates_and_overlaps_are_skipped_with_reasons(): void
    {
        $day = Shift::factory()->create(['name' => 'Day', 'start_time' => '08:00:00', 'end_time' => '16:00:00']);
        $late = Shift::factory()->create(['name' => 'Late', 'start_time' => '14:00:00', 'end_time' => '22:00:00']);
        $tech = User::factory()->create();
        $this->actingAsUser($this->admin);

        $body = fn (Shift $s) => ['shiftId' => $s->id, 'userIds' => [$tech->id], 'startDate' => '2026-10-19', 'endDate' => '2026-10-20'];

        $this->postJson('/api/v1/shifts/assign', $body($day))->assertJson(['created' => 2]);
        $this->postJson('/api/v1/shifts/assign', $body($day))->assertJson(['created' => 0])->assertJsonPath('skipped.0.reason', 'ALREADY_ASSIGNED');
        $this->postJson('/api/v1/shifts/assign', $body($late))->assertJson(['created' => 0])->assertJsonPath('skipped.1.reason', 'OVERLAPS:Day');
    }

    public function test_department_assignment_only_includes_active_unblocked_technicians(): void
    {
        $dept = Department::factory()->create();
        $shift = Shift::factory()->create();
        $ok = User::factory()->create(['department_id' => $dept->id]);
        User::factory()->pending()->create(['department_id' => $dept->id]);
        User::factory()->create(['department_id' => $dept->id, 'blocked_at' => now()]);
        User::factory()->dispatcher()->create(['department_id' => $dept->id]);

        $this->actingAsUser($this->admin)->postJson('/api/v1/shifts/assign', [
            'shiftId' => $shift->id, 'departmentId' => $dept->id, 'startDate' => '2026-10-19', 'endDate' => '2026-10-19',
        ])->assertCreated()->assertJson(['created' => 1, 'technicians' => 1]);

        $this->assertSame([$ok->id], ShiftAssignment::pluck('user_id')->all());
    }

    public function test_assignment_date_rules(): void
    {
        $shift = Shift::factory()->create();
        $tech = User::factory()->create();
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/shifts/assign', ['shiftId' => $shift->id, 'userIds' => [$tech->id], 'startDate' => '2026-10-18', 'endDate' => '2026-10-19'])
            ->assertJsonValidationErrors('startDate');
        $this->postJson('/api/v1/shifts/assign', ['shiftId' => $shift->id, 'userIds' => [$tech->id], 'startDate' => '2026-10-19', 'endDate' => '2027-01-31'])
            ->assertJsonPath('error', 'RANGE_TOO_LONG');

        $shift->update(['is_active' => false]);
        $this->postJson('/api/v1/shifts/assign', ['shiftId' => $shift->id, 'userIds' => [$tech->id], 'startDate' => '2026-10-19', 'endDate' => '2026-10-19'])
            ->assertJsonValidationErrors('shiftId');
    }

    public function test_roster_lists_a_week_and_filters_by_department(): void
    {
        $dept = Department::factory()->create();
        $shift = Shift::factory()->create();
        $inDept = User::factory()->create(['department_id' => $dept->id]);
        $other = User::factory()->create();
        $this->actingAsUser($this->admin)->postJson('/api/v1/shifts/assign', [
            'shiftId' => $shift->id, 'userIds' => [$inDept->id, $other->id], 'startDate' => '2026-10-19', 'endDate' => '2026-10-30',
        ]);

        $this->getJson('/api/v1/shifts/roster?from=2026-10-19')
            ->assertOk()->assertJsonCount(14, 'data')->assertJsonPath('meta.to', '2026-10-25')
            ->assertJsonPath('data.0.shift.name', $shift->name)
            ->assertJsonStructure(['data' => [['user' => ['fullName', 'department']]]]);

        $this->getJson("/api/v1/shifts/roster?from=2026-10-19&departmentId={$dept->id}")->assertJsonCount(7, 'data');
    }

    public function test_only_unstarted_assignments_can_be_removed(): void
    {
        $scheduled = ShiftAssignment::factory()->create();
        $started = ShiftAssignment::factory()->create(['status' => AssignmentStatus::Active, 'checked_in_at' => now()]);
        $this->actingAsUser($this->admin);

        $this->deleteJson("/api/v1/shift-assignments/{$scheduled->id}")->assertNoContent();
        $this->deleteJson("/api/v1/shift-assignments/{$started->id}")->assertStatus(409);
    }
}
