<?php

namespace Database\Factories;

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to today's run of the shift (in the business timezone).
 *
 * @extends Factory<ShiftAssignment>
 */
class ShiftAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'shift_id' => Shift::factory(),
            'shift_date' => now(config('smc.shifts.timezone'))->toDateString(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ShiftAssignment $a) {
            if ($a->scheduled_start_at === null) {
                [$start, $end] = Shift::findOrFail($a->shift_id)->windowOn($a->shift_date->toDateString());
                $a->scheduled_start_at = $start;
                $a->scheduled_end_at = $end;
            }
        });
    }
}
