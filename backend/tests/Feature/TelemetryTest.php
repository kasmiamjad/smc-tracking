<?php

namespace Tests\Feature;

use App\Events\FleetPositionUpdated;
use App\Models\Department;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\TechnicianPosition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TelemetryTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    private ShiftAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        config(['smc.shifts.timezone' => 'Asia/Riyadh']);
        $this->travelTo('2026-10-19 05:00:00');

        $this->tech = User::factory()->create(['department_id' => Department::factory()->create(['color_hex' => '#3B82F6'])->id]);
        $this->assignment = ShiftAssignment::factory()->for($this->tech)->for(Shift::factory())->create(['shift_date' => '2026-10-19']);
        $this->actingAsUser($this->tech)->postJson('/api/v1/shifts/check-in', ['assignmentId' => $this->assignment->id, 'location' => ['lat' => 24.7, 'lng' => 46.6]])->assertOk();
    }

    private function ping(string $at, float $lat = 24.7136, array $extra = []): array
    {
        return ['latitude' => $lat, 'longitude' => 46.6753, 'accuracy' => 4.2, 'speed' => 12.5, 'heading' => 182.0, 'batteryLevel' => 78, 'isCharging' => false, 'recordedAt' => $at, ...$extra];
    }

    public function test_single_spec_payload_is_stored_against_the_active_shift(): void
    {
        Event::fake([FleetPositionUpdated::class]);
        $this->travel(10)->seconds();

        $this->postJson('/api/v1/telemetry/ping', [
            'userId' => User::factory()->create()->id, // ignored: the token decides who this is
            ...$this->ping('2026-10-19T05:00:08.100Z'),
        ])->assertOk()->assertJson(['accepted' => 1, 'rejected' => 0]);

        $log = DB::table('location_logs')->first();
        $this->assertSame($this->tech->id, $log->user_id);
        $this->assertSame($this->assignment->id, $log->shift_assignment_id);
        $this->assertSame('2026-10-19 05:00:08.100', $log->recorded_at);

        $position = TechnicianPosition::find($this->tech->id);
        $this->assertSame(24.7136, $position->latitude);
        $this->assertSame(78, $position->battery_level);
        Event::assertDispatched(FleetPositionUpdated::class, fn ($e) => $e->broadcastWith()['recordedAt'] === '2026-10-19T05:00:08.100Z');
    }

    public function test_batch_keeps_on_shift_pings_and_rejects_the_rest(): void
    {
        $this->travel(1)->minutes();

        $this->postJson('/api/v1/telemetry/ping', ['pings' => [
            $this->ping('2026-10-19T04:59:00Z'), // before check-in
            $this->ping('2026-10-19T05:00:20Z'),
            $this->ping('2026-10-19T05:00:24Z'),
            $this->ping('2026-10-19T05:10:00Z'), // in the future
            $this->ping('2026-10-18T04:00:00Z'), // older than 24h
        ]])->assertOk()->assertExactJson([
            'accepted' => 2,
            'rejected' => 3,
            'rejectedReasons' => ['OFF_SHIFT' => 1, 'FUTURE_TIMESTAMP' => 1, 'TOO_OLD' => 1],
        ]);
    }

    public function test_out_of_order_batch_does_not_move_the_pin_backwards(): void
    {
        $this->travel(1)->minutes();
        $this->postJson('/api/v1/telemetry/ping', $this->ping('2026-10-19T05:00:50Z', lat: 24.80));
        $this->postJson('/api/v1/telemetry/ping', $this->ping('2026-10-19T05:00:30Z', lat: 24.60))->assertJson(['accepted' => 1]);

        $this->assertSame(24.80, TechnicianPosition::find($this->tech->id)->latitude);
        $this->assertSame(2, DB::table('location_logs')->count());
    }

    public function test_check_out_stops_tracking_but_late_buffered_pings_are_kept(): void
    {
        $this->travelTo('2026-10-19 06:00:00');
        $this->postJson('/api/v1/telemetry/ping', $this->ping('2026-10-19T05:30:00Z'));
        $this->postJson('/api/v1/shifts/check-out', ['assignmentId' => $this->assignment->id])->assertOk();

        $this->assertNull(TechnicianPosition::find($this->tech->id), 'off-shift technicians leave the live map');

        $this->travelTo('2026-10-19 06:20:00');
        $this->postJson('/api/v1/telemetry/ping', ['pings' => [
            $this->ping('2026-10-19T05:45:00Z'), // recorded during the shift, uploaded after: kept
            $this->ping('2026-10-19T06:10:00Z'), // recorded after check-out: dropped
        ]])->assertExactJson(['accepted' => 1, 'rejected' => 1, 'rejectedReasons' => ['OFF_SHIFT' => 1]]);

        $this->assertNull(TechnicianPosition::find($this->tech->id), 'late pings must not put a finished shift back on the map');
    }

    public function test_pings_without_a_shift_are_rejected(): void
    {
        $this->actingAsUser(User::factory()->create())
            ->postJson('/api/v1/telemetry/ping', $this->ping('2026-10-19T05:00:00Z'))
            ->assertExactJson(['accepted' => 0, 'rejected' => 1, 'rejectedReasons' => ['OFF_SHIFT' => 1]]);
    }

    public function test_validation_and_batch_limit(): void
    {
        $this->postJson('/api/v1/telemetry/ping', ['latitude' => 95, 'longitude' => 46.6])->assertJsonValidationErrors(['latitude', 'recordedAt']);
        $this->postJson('/api/v1/telemetry/ping', ['pings' => array_fill(0, 501, $this->ping('2026-10-19T05:00:00Z'))])->assertJsonValidationErrors('pings');
    }

    public function test_fleet_live_shows_freshness(): void
    {
        $quiet = User::factory()->create(); // checked in, never pinged
        $qa = ShiftAssignment::factory()->for($quiet)->for($this->assignment->shift)->create(['shift_date' => '2026-10-19']);
        $this->actingAsUser($quiet)->postJson('/api/v1/shifts/check-in', ['assignmentId' => $qa->id, 'location' => ['lat' => 1, 'lng' => 1]]);
        $this->actingAsUser($this->tech)->postJson('/api/v1/telemetry/ping', $this->ping('2026-10-19T05:00:00Z'));

        $admin = User::factory()->dispatcher()->create();
        $this->actingAsUser($admin);

        $this->travel(5)->seconds();
        $this->getJson('/api/v1/admin/fleet/live')->assertOk()
            ->assertJsonPath('meta.onShift', 2)->assertJsonPath('meta.live', 1)->assertJsonPath('meta.offline', 1);

        $live = collect($this->getJson('/api/v1/admin/fleet/live')->json('data'))->keyBy('userId');
        $this->assertSame('LIVE', $live[$this->tech->id]['freshness']);
        $this->assertSame('#3B82F6', $live[$this->tech->id]['department']['colorHex']);
        $this->assertSame(78, $live[$this->tech->id]['position']['batteryLevel']);
        $this->assertNull($live[$quiet->id]['position']);

        $this->travel(20)->seconds();
        $this->assertSame('LAGGING', collect($this->getJson('/api/v1/admin/fleet/live')->json('data'))->firstWhere('userId', $this->tech->id)['freshness']);

        $this->travel(10)->seconds();
        $this->assertSame('OFFLINE', collect($this->getJson('/api/v1/admin/fleet/live')->json('data'))->firstWhere('userId', $this->tech->id)['freshness']);
    }

    public function test_technicians_cannot_see_the_fleet(): void
    {
        $this->getJson('/api/v1/admin/fleet/live')->assertForbidden();
    }
}
