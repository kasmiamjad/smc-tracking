<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Attendance;
use App\Services\Routes\RouteArchiver;
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

Artisan::command('app:archive-routes', function () {
    $result = RouteArchiver::make()->run();
    $this->info("Summarized {$result['summarized']} shift routes, deleted {$result['deletedPings']} raw pings past retention.");
})->purpose('Store simplified routes for finished shifts and purge raw pings past the retention period (spec 6.3)');

/*
 * Scheduled jobs. Ubuntu's stock PHP CLI config (as on the VPS) loads the pcntl
 * extension but lists pcntl_signal in disable_functions. Laravel only checks that
 * the extension is loaded before calling pcntl_signal, so every run crashed after
 * taking its lock, and the default 24 h lock then blocked the job until the next
 * day. Only register the signal handler when the function really exists, and keep
 * locks short so an unexpected crash can never stall a job for long.
 */
$signalsUsable = function_exists('pcntl_signal');

Schedule::command('sanctum:prune-expired --hours=24')->daily()->withoutOverlapping(30, $signalsUsable);
Schedule::command('app:close-overdue-shifts')->everyFiveMinutes()->withoutOverlapping(10, $signalsUsable);
Schedule::command('app:archive-routes')->dailyAt('02:30')->timezone(config('smc.shifts.timezone'))->withoutOverlapping(120, $signalsUsable);
