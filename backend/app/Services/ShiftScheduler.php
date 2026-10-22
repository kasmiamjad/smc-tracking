<?php

namespace App\Services;

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** MOD-08: turn a shift template + people + date range into dated assignments. */
class ShiftScheduler
{
    /**
     * @param  Collection<int, User>  $users
     * @return array{created: int, skipped: list<array{userId: string, date: string, reason: string}>}
     */
    public function assign(Shift $shift, Collection $users, string $from, string $to, User $assigner): array
    {
        $created = 0;
        $skipped = [];

        $dates = collect(CarbonPeriod::create($from, $to))
            ->map(fn ($d) => CarbonImmutable::parse($d->toDateString()))
            ->filter(fn (CarbonImmutable $d) => $shift->runsOn($d));

        DB::transaction(function () use ($shift, $users, $dates, $assigner, &$created, &$skipped) {
            foreach ($users as $user) {
                foreach ($dates as $date) {
                    $day = $date->toDateString();
                    [$start, $end] = $shift->windowOn($day);

                    // Covers "already on this shift" too, since that window overlaps itself.
                    $clash = ShiftAssignment::where('user_id', $user->id)->overlapping($start, $end)->with('shift:id,name')->first();
                    if ($clash) {
                        $skipped[] = [
                            'userId' => $user->id,
                            'date' => $day,
                            'reason' => $clash->shift_id === $shift->id ? 'ALREADY_ASSIGNED' : "OVERLAPS:{$clash->shift->name}",
                        ];

                        continue;
                    }

                    ShiftAssignment::create([
                        'user_id' => $user->id,
                        'shift_id' => $shift->id,
                        'shift_date' => $day,
                        'scheduled_start_at' => $start,
                        'scheduled_end_at' => $end,
                        'assigned_by' => $assigner->id,
                    ]);
                    $created++;
                }
            }
        });

        return ['created' => $created, 'skipped' => $skipped];
    }
}
