<?php

namespace App\Services\Routes;

use App\Enums\AssignmentStatus;
use App\Models\ShiftAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * MOD-18: where a technician went over a time range, shift by shift.
 *
 * Uses the raw pings while they exist (cleaned for drawing), and the stored
 * simplified route once they have been archived after the retention period.
 */
class RouteHistory
{
    private const MAX_DRAWN_POINTS = 5000;

    public function __construct(private readonly RouteCleaner $cleaner) {}

    public static function make(): self
    {
        return new self(RouteCleaner::fromConfig());
    }

    /** Every shift of the user that overlaps [$from, $to], with its cleaned route. */
    public function forUser(string $userId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $assignments = ShiftAssignment::with('shift:id,name')
            ->where('user_id', $userId)
            ->whereIn('status', [AssignmentStatus::Active, AssignmentStatus::Completed])
            ->where('checked_in_at', '<', $to)
            ->where(fn ($q) => $q->whereNull('checked_out_at')->orWhere('checked_out_at', '>', $from))
            ->orderBy('checked_in_at')
            ->get();

        $segments = $assignments->map(fn (ShiftAssignment $a) => $this->segment($a, $from, $to))->values()->all();

        return [
            'segments' => $segments,
            'totals' => [
                'distanceMeters' => array_sum(array_map(fn ($s) => $s['stats']['distanceMeters'], $segments)),
                'stops' => array_sum(array_map(fn ($s) => count($s['stops']), $segments)),
                'stoppedSeconds' => array_sum(array_map(fn ($s) => $s['stats']['stoppedSeconds'], $segments)),
                'movingSeconds' => array_sum(array_map(fn ($s) => $s['stats']['movingSeconds'], $segments)),
                'rawPoints' => array_sum(array_map(fn ($s) => $s['stats']['rawPoints'], $segments)),
                'droppedPoints' => array_sum(array_map(fn ($s) => $s['stats']['droppedInaccurate'] + $s['stats']['droppedSpikes'], $segments)),
            ],
        ];
    }

    /** One shift's route, limited to the requested window. */
    public function segment(ShiftAssignment $a, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $raw = $this->rawPoints($a->id, $from, $to);
        $base = [
            'assignmentId' => $a->id,
            'shiftName' => $a->shift?->name,
            'checkedInAt' => $a->checked_in_at?->toIso8601ZuluString(),
            'checkedOutAt' => $a->checked_out_at?->toIso8601ZuluString(),
            'inProgress' => $a->status === AssignmentStatus::Active,
        ];

        if ($raw) {
            $clean = $this->cleaner->clean($raw);

            return $base + ['source' => 'raw', 'points' => $this->forDrawing($clean['points']), 'stops' => $clean['stops'], 'stats' => $clean['stats']];
        }

        // Raw pings archived: fall back to the stored simplified route.
        $summary = DB::table('route_summaries')->where('shift_assignment_id', $a->id)->first();
        if ($summary) {
            $inWindow = fn (int $t) => (! $from || $t >= $from->getTimestampMs()) && (! $to || $t <= $to->getTimestampMs());
            $points = array_values(array_filter(json_decode($summary->path, true), fn ($p) => $inWindow($p[2])));

            return $base + [
                'source' => 'summary',
                'points' => array_map(fn ($p) => ['lat' => $p[0], 'lng' => $p[1], 't' => $p[2], 'speed' => null, 'stop' => null], $points),
                'stops' => array_values(array_filter(json_decode($summary->stops, true), fn ($s) => $inWindow($s['to']) && $inWindow($s['from']))),
                'stats' => json_decode($summary->stats, true),
            ];
        }

        return $base + ['source' => 'none', 'points' => [], 'stops' => [], 'stats' => $this->cleaner->clean([])['stats']];
    }

    /** @return list<array{lat: float, lng: float, t: int, speed: ?float, accuracy: ?float}> */
    public function rawPoints(string $assignmentId, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        return DB::table('location_logs')
            ->where('shift_assignment_id', $assignmentId)
            ->when($from, fn ($q) => $q->where('recorded_at', '>=', $from->utc()->format('Y-m-d H:i:s.v')))
            ->when($to, fn ($q) => $q->where('recorded_at', '<=', $to->utc()->format('Y-m-d H:i:s.v')))
            ->orderBy('recorded_at')
            ->get(['latitude', 'longitude', 'recorded_at', 'speed', 'accuracy'])
            ->map(fn ($r) => [
                'lat' => (float) $r->latitude,
                'lng' => (float) $r->longitude,
                't' => CarbonImmutable::parse($r->recorded_at, 'UTC')->getTimestampMs(),
                'speed' => $r->speed === null ? null : (float) $r->speed,
                'accuracy' => $r->accuracy === null ? null : (float) $r->accuracy,
            ])
            ->all();
    }

    /** Thin very long routes for the browser, always keeping stop points and the last point. */
    private function forDrawing(array $points): array
    {
        $n = count($points);
        if ($n <= self::MAX_DRAWN_POINTS) {
            return $points;
        }
        $step = (int) ceil($n / self::MAX_DRAWN_POINTS);

        return array_values(array_filter($points, fn ($p, $i) => $i % $step === 0 || $p['stop'] !== null || $i === $n - 1, ARRAY_FILTER_USE_BOTH));
    }
}
