<?php

namespace App\Http\Resources;

use App\Models\ShiftAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ShiftAssignment */
class ShiftAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hours = fn (?int $minutes) => $minutes === null ? null : round($minutes / 60, 2);

        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'shiftId' => $this->shift_id,
            'shift' => new ShiftResource($this->whenLoaded('shift')),
            'shiftDate' => $this->shift_date->toDateString(),
            'scheduledStartAt' => $this->scheduled_start_at->toIso8601ZuluString(),
            'scheduledEndAt' => $this->scheduled_end_at->toIso8601ZuluString(),
            'status' => $this->status,
            'checkedInAt' => $this->checked_in_at?->toIso8601ZuluString(),
            'checkIn' => $this->check_in_lat === null ? null : ['lat' => $this->check_in_lat, 'lng' => $this->check_in_lng],
            'checkInOutsideZone' => $this->check_in_outside_zone,
            'checkedOutAt' => $this->checked_out_at?->toIso8601ZuluString(),
            'checkOut' => $this->check_out_lat === null ? null : ['lat' => $this->check_out_lat, 'lng' => $this->check_out_lng],
            'autoCheckedOut' => $this->auto_checked_out,
            'workedHours' => $hours($this->worked_minutes),
            'regularHours' => $hours($this->regular_minutes),
            'overtimeHours' => $hours($this->overtime_minutes),
        ];
    }
}
