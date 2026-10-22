<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Events\FleetPositionUpdated;
use App\Models\ShiftAssignment;
use App\Models\TechnicianPosition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * MOD-11: store pings and keep the live position current.
 *
 * A ping is kept only if it was recorded while the technician was checked in,
 * so tracking provably stops at check-out (spec 6.2). Buffered offline pings
 * that arrive late are still attributed to the shift they were recorded in.
 */
class TelemetryIngestor
{
    /**
     * @param  list<array<string, mixed>>  $pings  validated, camelCase keys
     * @return array{accepted: int, rejected: int, rejectedReasons: array<string, int>}
     */
    public function ingest(User $user, array $pings): array
    {
        $now = CarbonImmutable::now();
        $oldestAllowed = $now->subHours(config('smc.telemetry.max_ping_age_hours'));
        $rejected = [];
        $rows = [];

        $parsed = array_map(fn (array $p) => [...$p, 'recordedAt' => CarbonImmutable::parse($p['recordedAt'])->utc()], $pings);
        $times = array_column($parsed, 'recordedAt');

        $assignments = ShiftAssignment::where('user_id', $user->id)
            ->whereIn('status', [AssignmentStatus::Active, AssignmentStatus::Completed])
            ->where('checked_in_at', '<=', max($times))
            ->where(fn ($q) => $q->whereNull('checked_out_at')->orWhere('checked_out_at', '>=', min($times)))
            ->get();

        foreach ($parsed as $ping) {
            $at = $ping['recordedAt'];

            $reason = match (true) {
                $at->gt($now->addMinute()) => 'FUTURE_TIMESTAMP',
                $at->lt($oldestAllowed) => 'TOO_OLD',
                default => null,
            };

            $assignment = $reason ? null : $assignments->first(
                fn (ShiftAssignment $a) => $at->gte($a->checked_in_at) && ($a->checked_out_at === null || $at->lte($a->checked_out_at))
            );

            if (! $assignment) {
                $reason ??= 'OFF_SHIFT';
                $rejected[$reason] = ($rejected[$reason] ?? 0) + 1;

                continue;
            }

            $rows[] = [
                'user_id' => $user->id,
                'shift_assignment_id' => $assignment->id,
                'latitude' => $ping['latitude'],
                'longitude' => $ping['longitude'],
                'accuracy' => $ping['accuracy'] ?? null,
                'speed' => $ping['speed'] ?? null,
                'heading' => $ping['heading'] ?? null,
                'battery_level' => $ping['batteryLevel'] ?? null,
                'is_charging' => $ping['isCharging'] ?? null,
                'recorded_at' => $at->format('Y-m-d H:i:s.v'),
                'received_at' => $now,
                'active' => $assignment->status === AssignmentStatus::Active,
            ];
        }

        if ($rows) {
            DB::table('location_logs')->insert(array_map(fn ($r) => array_diff_key($r, ['active' => 1]), $rows));
            $this->updatePosition($user, array_values(array_filter($rows, fn ($r) => $r['active'])));
        }

        return [
            'accepted' => count($rows),
            'rejected' => array_sum($rejected),
            'rejectedReasons' => (object) $rejected,
        ];
    }

    /** Move the live pin to the newest ping of the active shift, ignoring out-of-order batches. */
    private function updatePosition(User $user, array $activeRows): void
    {
        if (! $activeRows) {
            return;
        }

        usort($activeRows, fn ($a, $b) => strcmp($b['recorded_at'], $a['recorded_at']));
        $latest = $activeRows[0];

        $current = TechnicianPosition::find($user->id);
        if ($current && $current->recorded_at->format('Y-m-d H:i:s.v') >= $latest['recorded_at']) {
            return;
        }

        $position = TechnicianPosition::updateOrCreate(['user_id' => $user->id], array_diff_key($latest, array_flip(['user_id', 'received_at', 'active'])));

        try {
            FleetPositionUpdated::dispatch($position);
        } catch (Throwable $e) {
            // The live map catching up later is fine; losing pings is not.
            report($e);
        }
    }
}
