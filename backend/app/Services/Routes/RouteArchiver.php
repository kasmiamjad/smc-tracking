<?php

namespace App\Services\Routes;

use App\Enums\AssignmentStatus;
use App\Models\ShiftAssignment;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;

/**
 * Spec 6.3 nightly job: store each finished shift's route as a simplified
 * path, then delete raw pings past the retention period. Raw pings are only
 * ever deleted for shifts whose summary already exists.
 */
class RouteArchiver
{
    public function __construct(private readonly RouteHistory $history, private readonly RouteCleaner $cleaner) {}

    public static function make(): self
    {
        return new self(RouteHistory::make(), RouteCleaner::fromConfig());
    }

    /** @return array{summarized: int, deletedPings: int} */
    public function run(): array
    {
        $summarized = 0;

        ShiftAssignment::where('status', AssignmentStatus::Completed)
            ->where('checked_out_at', '<', now()->subHour()) // let late buffered pings arrive first
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('route_summaries')->whereColumn('route_summaries.shift_assignment_id', 'shift_assignments.id'))
            ->orderBy('checked_out_at')
            ->chunkById(100, function ($assignments) use (&$summarized) {
                foreach ($assignments as $a) {
                    $this->summarize($a);
                    $summarized++;
                }
            });

        $cutoff = now()->subDays(config('smc.routes.raw_retention_days'))->format('Y-m-d H:i:s.v');
        $deleted = 0;
        do {
            // Small batches keep locks short on a table that takes writes every few seconds.
            $batch = DB::table('location_logs')
                ->where('recorded_at', '<', $cutoff)
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('route_summaries')->whereColumn('route_summaries.shift_assignment_id', 'location_logs.shift_assignment_id'))
                ->limit(5000)
                ->delete();
            $deleted += $batch;
        } while ($batch === 5000);

        return ['summarized' => $summarized, 'deletedPings' => $deleted];
    }

    public function summarize(ShiftAssignment $a): void
    {
        $clean = $this->cleaner->clean($this->history->rawPoints($a->id));
        $path = $this->simplify($clean['points'], config('smc.routes.summary_max_points'));

        DB::table('route_summaries')->updateOrInsert(
            ['shift_assignment_id' => $a->id],
            [
                'user_id' => $a->user_id,
                'path' => json_encode(array_map(fn ($p) => [round($p['lat'], 6), round($p['lng'], 6), $p['t']], $path)),
                'stops' => json_encode($clean['stops']),
                'stats' => json_encode($clean['stats']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * Douglas-Peucker: keep the points that matter for the shape, raising the
     * tolerance until at most $max remain. Stop points are always kept.
     */
    public function simplify(array $points, int $max): array
    {
        if (count($points) <= $max) {
            return $points;
        }

        for ($tolerance = 5.0; ; $tolerance *= 1.5) {
            $keep = array_fill(0, count($points), false);
            $keep[0] = $keep[count($points) - 1] = true;
            foreach ($points as $i => $p) {
                if (($p['stop'] ?? null) !== null) {
                    $keep[$i] = true;
                }
            }

            $stack = [[0, count($points) - 1]];
            while ($stack) {
                [$a, $b] = array_pop($stack);
                $worst = -1;
                $worstDistance = 0.0;
                for ($i = $a + 1; $i < $b; $i++) {
                    $d = $this->offset($points[$i], $points[$a], $points[$b]);
                    if ($d > $worstDistance) {
                        [$worst, $worstDistance] = [$i, $d];
                    }
                }
                if ($worst !== -1 && $worstDistance > $tolerance) {
                    $keep[$worst] = true;
                    $stack[] = [$a, $worst];
                    $stack[] = [$worst, $b];
                }
            }

            $kept = array_values(array_filter($points, fn ($i) => $keep[$i], ARRAY_FILTER_USE_KEY));
            if (count($kept) <= $max || $tolerance > 5000) {
                return $kept;
            }
        }
    }

    /** Distance in metres from $p to the line $a-$b (flat approximation, fine at city scale). */
    private function offset(array $p, array $a, array $b): float
    {
        $kx = 111_320 * cos(deg2rad($a['lat']));
        $ky = 110_540;
        [$x, $y] = [($p['lng'] - $a['lng']) * $kx, ($p['lat'] - $a['lat']) * $ky];
        [$dx, $dy] = [($b['lng'] - $a['lng']) * $kx, ($b['lat'] - $a['lat']) * $ky];
        $len2 = $dx * $dx + $dy * $dy;
        if ($len2 == 0.0) {
            return Geo::distanceMeters($p['lat'], $p['lng'], $a['lat'], $a['lng']);
        }
        $t = max(0, min(1, ($x * $dx + $y * $dy) / $len2));

        return hypot($x - $t * $dx, $y - $t * $dy);
    }
}
