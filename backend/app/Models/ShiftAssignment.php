<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'shift_id', 'shift_date', 'scheduled_start_at', 'scheduled_end_at', 'assigned_by'])]
class ShiftAssignment extends Model
{
    use HasFactory, HasUuids;

    protected $attributes = [
        'status' => 'SCHEDULED',
        'check_in_outside_zone' => false,
        'auto_checked_out' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'shift_date' => 'date:Y-m-d',
            'scheduled_start_at' => 'immutable_datetime',
            'scheduled_end_at' => 'immutable_datetime',
            'checked_in_at' => 'immutable_datetime',
            'checked_out_at' => 'immutable_datetime',
            'check_in_lat' => 'float',
            'check_in_lng' => 'float',
            'check_out_lat' => 'float',
            'check_out_lng' => 'float',
            'check_in_outside_zone' => 'boolean',
            'auto_checked_out' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function scheduledMinutes(): int
    {
        return (int) $this->scheduled_start_at->diffInMinutes($this->scheduled_end_at);
    }

    /** Close the shift and record hours. Overtime is time worked beyond the scheduled length. */
    public function complete(CarbonInterface $at, ?float $lat, ?float $lng, bool $automatic = false): void
    {
        $worked = max(0, (int) $this->checked_in_at->diffInMinutes($at));
        $regular = min($worked, $this->scheduledMinutes());

        $this->forceFill([
            'status' => AssignmentStatus::Completed,
            'checked_out_at' => $at,
            'check_out_lat' => $lat,
            'check_out_lng' => $lng,
            'auto_checked_out' => $automatic,
            'worked_minutes' => $worked,
            'regular_minutes' => $regular,
            'overtime_minutes' => $worked - $regular,
        ])->save();
    }

    /** Assignments whose scheduled window overlaps [$start, $end). */
    public function scopeOverlapping(Builder $query, CarbonInterface $start, CarbonInterface $end): void
    {
        $query->where('scheduled_start_at', '<', $end)->where('scheduled_end_at', '>', $start);
    }
}
