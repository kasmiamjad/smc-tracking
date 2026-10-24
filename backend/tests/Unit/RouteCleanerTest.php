<?php

namespace Tests\Unit;

use App\Services\Routes\RouteCleaner;
use PHPUnit\Framework\TestCase;

class RouteCleanerTest extends TestCase
{
    private const LAT = 19.2000; // ~ Thane

    private const LNG = 73.0000;

    /** A point `north` / `east` metres from the origin, `s` seconds in. */
    private function p(float $north, float $east, int $s, ?float $accuracy = 5, ?float $speed = 1.4): array
    {
        return [
            'lat' => self::LAT + $north / 111_320,
            'lng' => self::LNG + $east / (111_320 * cos(deg2rad(self::LAT))),
            't' => 1_700_000_000_000 + $s * 1000,
            'speed' => $speed,
            'accuracy' => $accuracy,
        ];
    }

    /** Walking north at 1.4 m/s, a ping every 4 s. */
    private function walk(int $from, int $seconds, float $startNorth = 0): array
    {
        $out = [];
        for ($s = 0; $s <= $seconds; $s += 4) {
            $out[] = $this->p($startNorth + 1.4 * $s, 0, $from + $s);
        }

        return $out;
    }

    public function test_clean_walk_is_kept_and_measured(): void
    {
        $r = (new RouteCleaner)->clean($this->walk(0, 100));

        $this->assertSame(26, $r['stats']['keptPoints']);
        $this->assertSame([], $r['stops']);
        $this->assertEqualsWithDelta(140, $r['stats']['distanceMeters'], 2);
        $this->assertSame(100, $r['stats']['movingSeconds']);
    }

    public function test_readings_the_phone_rates_inaccurate_are_dropped(): void
    {
        $points = $this->walk(0, 40);
        $points[5] = $this->p(400, 300, 20, accuracy: 427); // like the 427 m reading in the field data

        $r = (new RouteCleaner)->clean($points);

        $this->assertSame(1, $r['stats']['droppedInaccurate']);
        $this->assertNotContains($points[5]['t'], array_column($r['points'], 't'));
        $this->assertEqualsWithDelta(56, $r['stats']['distanceMeters'], 2);
    }

    public function test_out_and_back_spike_is_dropped_even_with_good_reported_accuracy(): void
    {
        $points = $this->walk(0, 40);
        $points[5] = $this->p(28 + 150, 0, 20, accuracy: 12); // 150 m north of the path and straight back

        $r = (new RouteCleaner)->clean($points);

        $this->assertSame(1, $r['stats']['droppedSpikes']);
        $this->assertLessThan(70, $r['stats']['distanceMeters'], 'the 300 m detour is not counted');
    }

    public function test_impossible_jump_is_dropped(): void
    {
        $points = $this->walk(0, 20);
        $points[] = $this->p(5000, 0, 24); // 5 km in 4 s
        $points = array_merge($points, $this->walk(28, 20, 39));

        $r = (new RouteCleaner)->clean($points);

        $this->assertSame(1, $r['stats']['droppedSpikes']);
    }

    public function test_standing_still_with_gps_wander_becomes_one_stop(): void
    {
        $points = $this->walk(0, 40); // arrive ~56 m north
        // 5 minutes "standing" with the 10-25 m wander seen near buildings, pinging every 15 s.
        $wander = [[8, -5], [-12, 10], [15, 14], [-6, -18], [20, 0], [0, 22], [-15, -10], [10, 18], [-20, 5], [5, -20],
            [12, 12], [-8, 16], [18, -6], [-10, -14], [6, 9], [-16, 2], [14, -12], [-4, 20], [9, -9], [0, 0], [11, 3]];
        foreach ($wander as $k => [$n, $e]) {
            $points[] = $this->p(56 + $n, $e, 44 + $k * 15, accuracy: 18, speed: 0.1);
        }
        $points = array_merge($points, $this->walk(360, 40, 56)); // walk on

        $r = (new RouteCleaner)->clean($points);

        $this->assertCount(1, $r['stops']);
        $stop = $r['stops'][0];
        $this->assertGreaterThanOrEqual(290, $stop['durationSeconds']);
        // The wander is replaced by the stop's centre: no scribble in the drawn route.
        $atStop = array_filter($r['points'], fn ($p) => $p['stop'] === 0);
        $this->assertCount(2, $atStop);
        $this->assertSame(1, count(array_unique(array_map(fn ($p) => $p['lat'].','.$p['lng'], $atStop))));
        $this->assertLessThan(200, $r['stats']['distanceMeters'], 'the wander adds no distance');
        $this->assertSame($stop['durationSeconds'], $r['stats']['stoppedSeconds']);
    }

    public function test_a_stay_split_by_a_brief_gps_wander_is_one_stop(): void
    {
        $points = [];
        foreach (range(0, 12) as $k) {
            $points[] = $this->p(0, 0, $k * 15, speed: 0); // 3 min here
        }
        $points[] = $this->p(55, 0, 195, accuracy: 25, speed: 0); // wanders out of the radius once
        foreach (range(0, 12) as $k) {
            $points[] = $this->p(15, 0, 210 + $k * 15, speed: 0); // 3 more min, 15 m away
        }
        $points = array_merge($points, $this->walk(420, 60, 15)); // then leave

        $r = (new RouteCleaner)->clean($points);

        $this->assertCount(1, $r['stops']);
        // Both halves (0-180 s and 210-390 s) are one stay; it ends once the walk leaves the radius.
        $this->assertGreaterThanOrEqual(390, $r['stops'][0]['durationSeconds']);
        $this->assertLessThan(460, $r['stops'][0]['durationSeconds']);
        $this->assertCount(2, array_filter($r['points'], fn ($p) => $p['stop'] === 0), 'one arrival, one departure');
        $this->assertArrayNotHasKey('count', $r['stops'][0]);
    }

    public function test_two_real_stops_far_apart_stay_separate(): void
    {
        $points = [];
        foreach (range(0, 10) as $k) {
            $points[] = $this->p(0, 0, $k * 15, speed: 0);
        }
        $points = array_merge($points, $this->walk(160, 100, 0)); // 140 m away
        foreach (range(1, 10) as $k) {
            $points[] = $this->p(140, 0, 260 + $k * 15, speed: 0);
        }

        $this->assertCount(2, (new RouteCleaner)->clean($points)['stops']);
    }

    public function test_short_pause_is_not_a_stop(): void
    {
        $points = $this->walk(0, 20);
        foreach (range(1, 6) as $k) {
            $points[] = $this->p(28, 0, 20 + $k * 10, speed: 0); // 60 s at a crossing
        }
        $points = array_merge($points, $this->walk(84, 20, 28));

        $this->assertSame([], (new RouteCleaner)->clean($points)['stops']);
    }

    public function test_empty_input(): void
    {
        $r = (new RouteCleaner)->clean([]);

        $this->assertSame([], $r['points']);
        $this->assertSame(0, $r['stats']['rawPoints']);
    }
}
