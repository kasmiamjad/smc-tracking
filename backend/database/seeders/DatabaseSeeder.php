<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Default departments with the pin colors from spec 4.4. */
    public function run(): void
    {
        $departments = [
            ['name' => 'Plumbing', 'color_hex' => '#3B82F6', 'icon_code' => 'droplet'],
            ['name' => 'Electrical', 'color_hex' => '#EF4444', 'icon_code' => 'bolt'],
            ['name' => 'HVAC', 'color_hex' => '#10B981', 'icon_code' => 'fan'],
        ];

        foreach ($departments as $department) {
            Department::firstOrCreate(['name' => $department['name']], $department);
        }
    }
}
