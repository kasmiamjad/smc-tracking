<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Attendance;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

Artisan::command('app:create-admin {email} {--name=Administrator}', function (string $email) {
    $password = $this->secret('Password (min 10 chars, letters and numbers)');

    $validator = Validator::make(
        ['email' => $email, 'password' => $password],
        ['email' => ['required', 'email', 'unique:users,email'], 'password' => ['required', Password::min(10)->letters()->numbers()]],
    );

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }

    $user = User::create([
        'full_name' => $this->option('name'),
        'role' => UserRole::Admin,
        'status' => UserStatus::Active,
        'email' => strtolower($email),
        'password' => $password,
    ]);
    $user->forceFill(['approved_at' => now()])->save();

    $this->info("Admin {$user->email} created.");
})->purpose('Create a web-console administrator account');

Artisan::command('app:close-overdue-shifts', function (Attendance $attendance) {
    $result = $attendance->closeOverdue();
    $this->info("Auto-checked-out {$result['autoCheckedOut']}, marked {$result['absent']} absent.");
})->purpose('Auto check-out forgotten shifts and mark missed shifts absent');

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('app:close-overdue-shifts')->everyFiveMinutes()->withoutOverlapping();
