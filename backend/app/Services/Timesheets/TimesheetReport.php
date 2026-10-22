<?php

namespace App\Services\Timesheets;

use App\Enums\AssignmentStatus;
use App\Models\ShiftAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MOD-19: timesheet figures for a period, in one shape shared by the JSON
 * API and every export.
 *
 * - Shifts count toward the date they started (in the business timezone).
 * - Completed shifts use the minutes recorded at check-out; shifts still in
 *   progress count time worked so far and are flagged `inProgress`.
 * - Overtime is time worked beyond the scheduled shift length.
 * - Daily reports also measure moving vs stationary time from GPS pings,
 *   so a check-in with no movement behind it stands out (spec 4.6).
 */
class TimesheetReport
{
    public const PERIODS = ['daily', 'weekly', 'monthly', 'custom'];

    public const MAX_CUSTOM_DAYS = 92;

    /** Pings further apart than this are a gap in tracking, not time spent moving or still. */
    private const MAX_PING_GAP_SECONDS = 60;

    /** Spec 4.2: above 1 m/s counts as moving. */
    private const MOVING_SPEED = 1.0;

    public readonly CarbonImmutable $start;

    public readonly CarbonImmutable $end;

    /** @var Collection<int, ShiftAssignment> */
    private Collection $assignments;

    private array $movement = [];

    public function __construct(
        public readonly string $period,
        string $startDate,
        ?string $endDate = null,
        public readonly ?string $departmentId = null,
        public readonly ?string $userId = null,
    ) {
        $date = CarbonImmutable::parse($startDate);

        [$this->start, $this->end] = match ($period) {
            'daily' => [$date, $date],
            'weekly' => [$date->startOfWeek(), $date->startOfWeek()->addDays(6)],
            'monthly' => [$date->startOfMonth(), $date->endOfMonth()->startOfDay()],
            'custom' => [$date, CarbonImmutable::parse($endDate ?? $startDate)],
        };

        $this->assignments = ShiftAssignment::with(['user.department', 'shift'])
            ->whereBetween('shift_date', [$this->start->toDateString(), $this->end->toDateString()])
            ->whereIn('status', [AssignmentStatus::Active, AssignmentStatus::Completed, AssignmentStatus::Absent])
            ->when($departmentId, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('department_id', $departmentId)))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->orderBy('shift_date')
            ->orderBy('scheduled_start_at')
            ->get();

        if ($period === 'daily') {
            $this->movement = $this->measureMovement($this->assignments->pluck('id')->all());
        }
    }

    /** @return list<string> every date in the period, YYYY-MM-DD */
    public function dates(): array
    {
        $dates = [];
        for ($d = $this->start; $d->lte($this->end); $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        return $dates;
    }

    /** One row per shift. */
    public function entries(): Collection
    {
        return $this->assignments->map(function (ShiftAssignment $a) {
            [$worked, $regular, $overtime] = $this->minutes($a);
            $movement = $this->movement[$a->id] ?? null;

            return [
                'id' => $a->id,
                'date' => $a->shift_date->toDateString(),
                'user' => $this->person($a),
                'shiftName' => $a->shift->name,
                'status' => $a->status->value,
                'inProgress' => $a->status === AssignmentStatus::Active,
                'scheduledStartAt' => $a->scheduled_start_at->toIso8601ZuluString(),
                'scheduledEndAt' => $a->scheduled_end_at->toIso8601ZuluString(),
                'checkedInAt' => $a->checked_in_at?->toIso8601ZuluString(),
                'checkIn' => $a->check_in_lat === null ? null : ['lat' => $a->check_in_lat, 'lng' => $a->check_in_lng],
                'checkInOutsideZone' => $a->check_in_outside_zone,
                'checkedOutAt' => $a->checked_out_at?->toIso8601ZuluString(),
                'checkOut' => $a->check_out_lat === null ? null : ['lat' => $a->check_out_lat, 'lng' => $a->check_out_lng],
                'autoCheckedOut' => $a->auto_checked_out,
                'workedMinutes' => $worked,
                'regularMinutes' => $regular,
                'overtimeMinutes' => $overtime,
                'workedHours' => self::hours($worked),
                'regularHours' => self::hours($regular),
                'overtimeHours' => self::hours($overtime),
                'movingHours' => $movement ? self::hours(intdiv($movement['moving'], 60)) : null,
                'stationaryHours' => $movement ? self::hours(intdiv($movement['stationary'], 60)) : null,
                // Share of worked time covered by GPS pings; low coverage = check-in without tracking.
                'gpsCoverage' => $movement && $worked > 0 ? round(min(1, ($movement['moving'] + $movement['stationary']) / ($worked * 60)), 2) : null,
            ];
        })->values();
    }

    /** One row per technician, with hours per date for the weekly grid. */
    public function people(): Collection
    {
        $dates = $this->dates();

        return $this->assignments->groupBy('user_id')->map(function (Collection $shifts) use ($dates) {
            $worked = $regular = $overtime = 0;
            $byDate = array_fill_keys($dates, 0);
            $daysWorked = [];
            $absences = 0;

            foreach ($shifts as $a) {
                if ($a->status === AssignmentStatus::Absent) {
                    $absences++;

                    continue;
                }
                [$w, $r, $o] = $this->minutes($a);
                $worked += $w;
                $regular += $r;
                $overtime += $o;
                $byDate[$a->shift_date->toDateString()] += $w;
                $daysWorked[$a->shift_date->toDateString()] = true;
            }

            $days = count($daysWorked);

            return [
                'user' => $this->person($shifts->first()),
                'shifts' => $shifts->count() - $absences,
                'daysWorked' => $days,
                'absences' => $absences,
                'inProgress' => $shifts->contains(fn ($a) => $a->status === AssignmentStatus::Active),
                'workedMinutes' => $worked,
                'workedHours' => self::hours($worked),
                'regularHours' => self::hours($regular),
                'overtimeHours' => self::hours($overtime),
                'averageDailyHours' => $days ? self::hours(intdiv($worked, $days)) : 0.0,
                'byDate' => array_map(self::hours(...), $byDate),
            ];
        })->sortBy(fn ($p) => mb_strtolower($p['user']['fullName']))->values();
    }

    public function totals(): array
    {
        $worked = $regular = $overtime = 0;
        $absences = 0;

        foreach ($this->assignments as $a) {
            if ($a->status === AssignmentStatus::Absent) {
                $absences++;

                continue;
            }
            [$w, $r, $o] = $this->minutes($a);
            $worked += $w;
            $regular += $r;
            $overtime += $o;
        }

        return [
            'technicians' => $this->assignments->pluck('user_id')->unique()->count(),
            'shifts' => $this->assignments->count() - $absences,
            'absences' => $absences,
            'workedHours' => self::hours($worked),
            'regularHours' => self::hours($regular),
            'overtimeHours' => self::hours($overtime),
        ];
    }

    /** Hours per department, for the PDF breakdown. */
    public function departments(): Collection
    {
        return $this->people()
            ->groupBy(fn ($p) => $p['user']['department']['name'] ?? 'Unassigned')
            ->map(fn (Collection $people, string $name) => [
                'name' => $name,
                'technicians' => $people->count(),
                'workedHours' => round($people->sum('workedHours'), 2),
                'overtimeHours' => round($people->sum('overtimeHours'), 2),
            ])
            ->sortBy('name')
            ->values();
    }

    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'startDate' => $this->start->toDateString(),
            'endDate' => $this->end->toDateString(),
            'dates' => $this->dates(),
            'timezone' => config('smc.shifts.timezone'),
            'generatedAt' => now()->toIso8601ZuluString(),
            'totals' => $this->totals(),
            'people' => $this->people(),
            'entries' => $this->entries(),
        ];
    }

    /** e.g. "2026-10-22", "week-2026-10-19", "october-2026", "2026-10-01-to-2026-10-15" */
    public function slug(): string
    {
        return match ($this->period) {
            'daily' => $this->start->toDateString(),
            'weekly' => 'week-'.$this->start->toDateString(),
            'monthly' => strtolower($this->start->format('F-Y')),
            'custom' => $this->start->toDateString().'-to-'.$this->end->toDateString(),
        };
    }

    /** e.g. "Thursday 22 October 2026", "Week of 19 October 2026", "October 2026" */
    public function title(): string
    {
        return match ($this->period) {
            'daily' => $this->start->format('l j F Y'),
            'weekly' => 'Week of '.$this->start->format('j F Y'),
            'monthly' => $this->start->format('F Y'),
            'custom' => $this->start->format('j M Y').' – '.$this->end->format('j M Y'),
        };
    }

    public static function hours(int $minutes): float
    {
        return round($minutes / 60, 2);
    }

    /** @return array{0: int, 1: int, 2: int} worked, regular, overtime minutes */
    private function minutes(ShiftAssignment $a): array
    {
        return match ($a->status) {
            AssignmentStatus::Completed => [(int) $a->worked_minutes, (int) $a->regular_minutes, (int) $a->overtime_minutes],
            AssignmentStatus::Active => (function () use ($a) {
                $worked = max(0, (int) $a->checked_in_at->diffInMinutes(now()));
                $regular = min($worked, $a->scheduledMinutes());

                return [$worked, $regular, $worked - $regular];
            })(),
            default => [0, 0, 0],
        };
    }

    private function person(ShiftAssignment $a): array
    {
        $u = $a->user;

        return [
            'id' => $u->id,
            'fullName' => $u->full_name,
            'phone' => $u->phone_number,
            'department' => $u->department ? ['id' => $u->department->id, 'name' => $u->department->name, 'colorHex' => $u->department->color_hex] : null,
        ];
    }

    /**
     * Seconds spent moving vs stationary per shift, from consecutive pings.
     * Each gap between pings is attributed to the later ping's speed; gaps
     * longer than a minute are left out as missing tracking.
     *
     * @return array<string, array{moving: int, stationary: int}>
     */
    private function measureMovement(array $assignmentIds): array
    {
        if (! $assignmentIds) {
            return [];
        }

        $gap = self::MAX_PING_GAP_SECONDS;
        $speed = self::MOVING_SPEED;
        $placeholders = implode(',', array_fill(0, count($assignmentIds), '?'));

        $rows = DB::select("
            SELECT shift_assignment_id AS id,
                   COALESCE(SUM(CASE WHEN gap <= {$gap} AND speed > {$speed} THEN gap END), 0) AS moving,
                   COALESCE(SUM(CASE WHEN gap <= {$gap} AND (speed IS NULL OR speed <= {$speed}) THEN gap END), 0) AS stationary
            FROM (
                SELECT shift_assignment_id, speed,
                       TIMESTAMPDIFF(MICROSECOND,
                           LAG(recorded_at) OVER (PARTITION BY shift_assignment_id ORDER BY recorded_at),
                           recorded_at) / 1000000 AS gap
                FROM location_logs
                WHERE shift_assignment_id IN ({$placeholders})
            ) intervals
            GROUP BY shift_assignment_id
        ", $assignmentIds);

        return collect($rows)->mapWithKeys(fn ($r) => [$r->id => ['moving' => (int) $r->moving, 'stationary' => (int) $r->stationary]])->all();
    }
}
