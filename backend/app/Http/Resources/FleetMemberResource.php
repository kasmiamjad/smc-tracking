<?php

namespace App\Http\Resources;

use App\Models\ShiftAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A checked-in technician and their latest position.
 *
 * @mixin ShiftAssignment
 */
class FleetMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->user;
        $position = $user->position?->shift_assignment_id === $this->id ? $user->position : null;
        $age = $position ? max(0, (int) $position->recorded_at->diffInSeconds(now())) : null;

        return [
            'userId' => $user->id,
            'fullName' => $user->full_name,
            'phone' => $user->phone_number,
            'department' => $user->department ? [
                'id' => $user->department->id,
                'name' => $user->department->name,
                'colorHex' => $user->department->color_hex,
            ] : null,
            'assignmentId' => $this->id,
            'shiftName' => $this->shift->name,
            'checkedInAt' => $this->checked_in_at->toIso8601ZuluString(),
            'scheduledEndAt' => $this->scheduled_end_at->toIso8601ZuluString(),
            'position' => $position ? [
                'latitude' => $position->latitude,
                'longitude' => $position->longitude,
                'accuracy' => $position->accuracy,
                'speed' => $position->speed,
                'heading' => $position->heading,
                'batteryLevel' => $position->battery_level,
                'isCharging' => $position->is_charging,
                'recordedAt' => $position->recorded_at->toIso8601ZuluString('millisecond'),
            ] : null,
            'ageSeconds' => $age,
            // Spec 4.4 pulse colors: LIVE = green, LAGGING = amber, OFFLINE = grey.
            'freshness' => match (true) {
                $age === null => 'OFFLINE',
                $age <= config('smc.telemetry.fresh_seconds') => 'LIVE',
                $age <= config('smc.telemetry.lagging_seconds') => 'LAGGING',
                default => 'OFFLINE',
            },
        ];
    }
}
