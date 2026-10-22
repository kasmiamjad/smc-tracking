<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Exceptions\ApiException;
use App\Models\ShiftAssignment;
use App\Models\TechnicianPosition;
use App\Models\User;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;

/** MOD-09: check-in / check-out and the jobs that close shifts nobody closed. */
class Attendance
{
    public function checkIn(User $user, ShiftAssignment $assignment, float $lat, float $lng): ShiftAssignment
    {
        $this->assertOwner($user, $assignment);

        if ($assignment->status !== AssignmentStatus::Scheduled) {
            throw new ApiException('SHIFT_NOT_SCHEDULED', 'This shift has already been started, finished or missed.', 409);
        }

        $now = now();
        $opensAt = $assignment->scheduled_start_at->subMinutes(config('smc.shifts.early_check_in_minutes'));

        if ($now->lt($opensAt)) {
            throw new ApiException('TOO_EARLY', 'Check-in opens '.config('smc.shifts.early_check_in_minutes').' minutes before the shift starts.', 422,
                ['opensAt' => $opensAt->toIso8601String()]);
        }
        if ($now->gte($assignment->scheduled_end_at)) {
            throw new ApiException('SHIFT_ENDED', 'This shift has already ended.', 422);
        }

        return DB::transaction(function () use ($user, $assignment, $lat, $lng, $now) {
            // One live shift at a time; lock the user's rows so double-taps can't race.
            $active = ShiftAssignment::where('user_id', $user->id)->where('status', AssignmentStatus::Active)->lockForUpdate()->exists();
            if ($active) {
                throw new ApiException('ALREADY_CHECKED_IN', 'Check out of your current shift first.', 409);
            }

            $zone = $assignment->shift->geofence;

            // Being outside the zone is recorded, not refused: GPS drift or
            // checking in on the way shouldn't strand a technician.
            $assignment->forceFill([
                'status' => AssignmentStatus::Active,
                'checked_in_at' => $now,
                'check_in_lat' => $lat,
                'check_in_lng' => $lng,
                'check_in_outside_zone' => $zone ? ! Geo::contains($zone, $lat, $lng) : false,
            ])->save();

            return $assignment;
        });
    }

    public function checkOut(User $user, ShiftAssignment $assignment, ?float $lat, ?float $lng): ShiftAssignment
    {
        $this->assertOwner($user, $assignment);

        if ($assignment->status !== AssignmentStatus::Active) {
            throw new ApiException('NOT_CHECKED_IN', 'You are not checked in to this shift.', 409);
        }

        $assignment->complete(now(), $lat, $lng);
        $this->clearPosition($assignment);

        return $assignment;
    }

    /**
     * Scheduled job: close shifts left open long after they ended (at the
     * scheduled end, so nobody is paid for hours they forgot to clock out of),
     * and mark shifts nobody checked in to as absent.
     *
     * @return array{autoCheckedOut: int, absent: int}
     */
    public function closeOverdue(): array
    {
        $cutoff = now()->subMinutes(config('smc.shifts.auto_check_out_after_minutes'));
        $autoCheckedOut = 0;

        ShiftAssignment::where('status', AssignmentStatus::Active)
            ->where('scheduled_end_at', '<', $cutoff)
            ->each(function (ShiftAssignment $a) use (&$autoCheckedOut) {
                $a->complete($a->scheduled_end_at->max($a->checked_in_at), null, null, automatic: true);
                $this->clearPosition($a);
                $autoCheckedOut++;
            });

        $absent = ShiftAssignment::where('status', AssignmentStatus::Scheduled)
            ->where('scheduled_end_at', '<', now())
            ->update(['status' => AssignmentStatus::Absent, 'updated_at' => now()]);

        return ['autoCheckedOut' => $autoCheckedOut, 'absent' => $absent];
    }

    private function assertOwner(User $user, ShiftAssignment $assignment): void
    {
        if ($assignment->user_id !== $user->id) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }
    }

    /** Off-shift technicians disappear from the live map (spec 6.2). */
    private function clearPosition(ShiftAssignment $assignment): void
    {
        TechnicianPosition::where('shift_assignment_id', $assignment->id)->delete();
    }
}
