<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'role' => UserRole::Technician,
            'status' => UserStatus::Active,
            'phone_number' => '+9665'.fake()->unique()->numerify('########'),
            'pin_hash' => '2580',
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => UserStatus::PendingApproval]);
    }

    public function admin(): static
    {
        return $this->console(UserRole::Admin);
    }

    public function dispatcher(): static
    {
        return $this->console(UserRole::Dispatcher);
    }

    private function console(UserRole $role): static
    {
        return $this->state(fn () => [
            'role' => $role,
            'phone_number' => null,
            'pin_hash' => null,
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
        ]);
    }
}
