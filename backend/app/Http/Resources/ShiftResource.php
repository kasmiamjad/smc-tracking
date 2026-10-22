<?php

namespace App\Http\Resources;

use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Shift */
class ShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'startTime' => substr($this->start_time, 0, 5),
            'endTime' => substr($this->end_time, 0, 5),
            'crossesMidnight' => $this->crossesMidnight(),
            'lengthMinutes' => $this->lengthMinutes(),
            'daysOfWeek' => $this->days_of_week,
            'geofence' => $this->geofence,
            'isActive' => $this->is_active,
            'timezone' => config('smc.shifts.timezone'),
            'assignmentsCount' => $this->whenCounted('assignments'),
        ];
    }
}
