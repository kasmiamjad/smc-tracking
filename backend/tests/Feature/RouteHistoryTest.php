<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Routes\RouteArchiver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RouteHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    private User $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        config(['smc.shifts.timezone' => 'Asia/Riyadh']);
        $this->travelTo('2026-10-21 12:00:00');
        $this->tech = User::factory()->create(['full_name' => 'Ali Hassan']);
        $this->dispatcher = User::factory()->dispatcher()->create();
    }

    /** A completed shift on $date (05:00-13:00 UTC) with a walk north, one 427 m-accuracy spike and a 5 min stop. */
    private function shiftWithPings(string $date): ShiftAssignment
    {
        $a = ShiftAssignment::factory()->for($this->tech)->for(Shift::factory())->create(['shift_date' => $date]);
        $start = CarbonImmutable::parse("{$date} 06:00:00");
        $a->forceFill(['status' => AssignmentStatus::Active, 'checked_in_at' => $start])->save();

        $rows = [];
        $add = function (float $north, int $s, float $acc = 5, float $speed = 1.4) use (&$rows, $a, $start) {
            $rows[] = [
                'user_id' => $this->tech->id, 'shift_assignment_id' => $a->id,
                'latitude' => 24.70 + $north / 111_320, 'longitude' => 46.67, 'accuracy' => $acc, 'speed' => $speed,
                'recorded_at' => $start->addSeconds($s)->format('Y-m-d H:i:s.v'),
            ];
        };
        for ($s = 0; $s <= 200; $s += 4) {
            $add(1.4 * $s, $s);                              // walk 280 m north
        }
        $add(5000, 202, acc: 427);                           // the phone's own rating says: don't trust me
        for ($k = 1; $k <= 21; $k++) {
            $add(280 + ($k % 2 ? 12 : -12), 200 + $k * 15, acc: 15, speed: 0.1); // 5 min stop with wander
        }
        DB::table('location_logs')->insert($rows);
        $a->complete($start->addMinutes(10), null, null);

        return $a;
    }

    public function test_history_returns_a_cleaned_route_with_stops(): void
    {
        $this->shiftWithPings('2026-10-21');

        $res = $this->actingAsUser($this->dispatcher)
            ->getJson("/api/v1/admin/route-history?userId={$this->tech->id}")
            ->assertOk()
            ->assertJsonPath('user.fullName', 'Ali Hassan')
            ->assertJsonPath('timezone', 'Asia/Riyadh')
            ->assertJsonPath('from', '2026-10-20T21:00:00Z') // today 00:00 in Riyadh
            ->assertJsonCount(1, 'segments')
            ->assertJsonPath('segments.0.source', 'raw')
            ->assertJsonPath('segments.0.stats.droppedInaccurate', 1)
            ->assertJsonPath('totals.stops', 1);

        $segment = $res->json('segments.0');
        $this->assertEqualsWithDelta(280, $segment['stats']['distanceMeters'], 40);
        $this->assertGreaterThanOrEqual(300, $segment['stops'][0]['durationSeconds']);
        $this->assertLessThan(5000 / 111_320 + 24.70, max(array_column($segment['points'], 'lat')), 'the 5 km spike is not drawn');
    }

    public function test_time_window_and_other_days(): void
    {
        $this->shiftWithPings('2026-10-20');
        $this->shiftWithPings('2026-10-21');
        $this->actingAsUser($this->dispatcher);

        $this->getJson("/api/v1/admin/route-history?userId={$this->tech->id}&from=2026-10-20T00:00:00Z&to=2026-10-21T00:00:00Z")
            ->assertJsonCount(1, 'segments')
            ->assertJsonPath('segments.0.checkedInAt', '2026-10-20T06:00:00Z');

        // 06:00-06:01: only the first minute of walking.
        $minute = $this->getJson("/api/v1/admin/route-history?userId={$this->tech->id}&from=2026-10-21T06:00:00Z&to=2026-10-21T06:01:00Z")->json('segments.0');
        $this->assertSame(16, $minute['stats']['rawPoints']);

        $this->getJson("/api/v1/admin/route-history?userId={$this->tech->id}&from=2026-10-01T00:00:00Z&to=2026-10-03T00:00:00Z")
            ->assertJsonPath('error', 'RANGE_TOO_LONG');
    }

    public function test_archived_routes_are_served_from_their_summary(): void
    {
        $a = $this->shiftWithPings('2026-09-10');

        $this->travelTo('2026-10-21 12:00:00');
        $this->assertSame(['summarized' => 1, 'deletedPings' => 73], app(RouteArchiver::class)->run());
        $this->assertSame(0, DB::table('location_logs')->where('shift_assignment_id', $a->id)->count());

        $segment = $this->actingAsUser($this->dispatcher)
            ->getJson("/api/v1/admin/route-history?userId={$this->tech->id}&from=2026-09-10T00:00:00Z&to=2026-09-11T00:00:00Z")
            ->assertOk()
            ->json('segments.0');

        $this->assertSame('summary', $segment['source']);
        $this->assertNotEmpty($segment['points']);
        $this->assertCount(1, $segment['stops']);
        $this->assertSame(73, $segment['stats']['rawPoints']);
    }

    public function test_archiver_keeps_recent_raw_pings_and_unsummarized_shifts(): void
    {
        $recent = $this->shiftWithPings('2026-10-21');
        $this->travel(2)->hours();
        $active = ShiftAssignment::factory()->for(User::factory())->for(Shift::factory())->create(['shift_date' => '2026-09-01']);
        $active->forceFill(['status' => AssignmentStatus::Active, 'checked_in_at' => now()->subDays(50)])->save();
        DB::table('location_logs')->insert(['user_id' => $active->user_id, 'shift_assignment_id' => $active->id, 'latitude' => 1, 'longitude' => 1, 'recorded_at' => now()->subDays(50)->format('Y-m-d H:i:s.v')]);

        $result = app(RouteArchiver::class)->run();

        $this->assertSame(['summarized' => 1, 'deletedPings' => 0], $result);
        $this->assertSame(73, DB::table('location_logs')->where('shift_assignment_id', $recent->id)->count(), 'within 30 days: raw kept');
        $this->assertSame(1, DB::table('location_logs')->where('shift_assignment_id', $active->id)->count(), 'never deleted without a summary');
    }

    public function test_simplify_respects_the_point_budget_and_keeps_stops(): void
    {
        $points = [];
        for ($i = 0; $i < 3000; $i++) {
            $points[] = ['lat' => 24.7 + sin($i / 50) / 1000, 'lng' => 46.6 + $i / 100000, 't' => $i * 1000, 'speed' => 1.0, 'stop' => $i === 1500 ? 0 : null];
        }

        $out = app(RouteArchiver::class)->simplify($points, 500);

        $this->assertLessThanOrEqual(500, count($out));
        $this->assertSame(0, $out[0]['t']);
        $this->assertSame(2_999_000, end($out)['t']);
        $this->assertContains(1_500_000, array_column($out, 't'));
    }

    public function test_drawer_trail_is_cleaned_too(): void
    {
        $a = $this->shiftWithPings('2026-10-21');

        $res = $this->actingAsUser($this->dispatcher)->getJson("/api/v1/admin/fleet/trail/{$a->id}")->assertOk();

        $this->assertSame(73, $res->json('meta.totalPings'));
        $this->assertCount(1, $res->json('stops'));
        $this->assertLessThan(60, count($res->json('data')));
    }

    public function test_technicians_cannot_read_route_history(): void
    {
        $this->actingAsUser($this->tech)->getJson("/api/v1/admin/route-history?userId={$this->tech->id}")->assertForbidden();
    }
}
