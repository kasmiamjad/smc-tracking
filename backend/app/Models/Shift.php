<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'start_time', 'end_time', 'days_of_week', 'geofence', 'is_active'])]
class Shift extends Model
{
    use HasFactory, HasUuids;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'days_of_week' => 'array',
            'geofence' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    public function runsOn(CarbonImmutable $date): bool
    {
        return in_array($date->dayOfWeekIso, $this->days_of_week, true);
    }

    public function crossesMidnight(): bool
    {
        return $this->end_time <= $this->start_time;
    }

    /**
     * The UTC start and end of this shift when it starts on $date (a calendar
     * date in the business timezone).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function windowOn(string $date): array
    {
        $tz = config('smc.shifts.timezone');
        $start = CarbonImmutable::parse("{$date} {$this->start_time}", $tz);
        $end = CarbonImmutable::parse("{$date} {$this->end_time}", $tz);

        if ($this->crossesMidnight()) {
            $end = $end->addDay();
        }

        return [$start->utc(), $end->utc()];
    }

    public function lengthMinutes(): int
    {
        [$start, $end] = $this->windowOn(now(config('smc.shifts.timezone'))->toDateString());

        return (int) $start->diffInMinutes($end);
    }
}
