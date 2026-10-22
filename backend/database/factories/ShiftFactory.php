<?php

namespace Database\Factories;

use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Shift '.fake()->unique()->bothify('??##'),
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'geofence' => null,
            'is_active' => true,
        ];
    }

    public function overnight(): static
    {
        return $this->state(['start_time' => '22:00:00', 'end_time' => '06:00:00']);
    }
}
