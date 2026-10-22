<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local sample data for trying the console: php artisan db:seed --class=DemoSeeder
 * Demo console login: demo-admin@smc.test / demo-password-1 (local only).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder must not run in production.');
        }

        $this->call(DatabaseSeeder::class);
        $departments = Department::pluck('id', 'name');

        User::firstOrCreate(['email' => 'demo-admin@smc.test'], [
            'full_name' => 'Demo Admin',
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
            'password' => 'demo-password-1',
        ]);

        User::firstOrCreate(['email' => 'demo-dispatcher@smc.test'], [
            'full_name' => 'Demo Dispatcher',
            'role' => UserRole::Dispatcher,
            'status' => UserStatus::Active,
            'password' => 'demo-password-1',
        ]);

        $technicians = [
            ['Faisal Al-Harbi', '+966500000101', 'Plumbing', UserStatus::Active],
            ['Omar Siddiqui', '+966500000102', 'Plumbing', UserStatus::Active],
            ['Rahul Menon', '+966500000103', 'Electrical', UserStatus::Active],
            ['Yousef Qahtani', '+966500000104', 'Electrical', UserStatus::Active],
            ['Bilal Ahmed', '+966500000105', 'HVAC', UserStatus::Active],
            ['Khalid Mansour', '+966500000106', 'HVAC', UserStatus::PendingApproval],
            ['Joseph Thomas', '+966500000107', null, UserStatus::PendingApproval],
            ['Imran Sheikh', '+966500000108', 'Electrical', UserStatus::PendingApproval],
        ];

        foreach ($technicians as [$name, $phone, $department, $status]) {
            User::firstOrCreate(['phone_number' => $phone], [
                'full_name' => $name,
                'role' => UserRole::Technician,
                'status' => $status,
                'pin_hash' => '2580',
                'department_id' => $department ? $departments[$department] : null,
            ]);
        }
    }
}
