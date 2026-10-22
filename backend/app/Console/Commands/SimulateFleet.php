<?php

namespace App\Console\Commands;

use App\Enums\AssignmentStatus;
use App\Enums\UserStatus;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\AuthTokenService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Fake GPS for building the live map before the mobile app exists.
 * Drives real technicians through the real HTTP API: check-in, a ping every
 * few seconds while driving around, then check-out. Local/dev only.
 *
 *   php artisan app:simulate-fleet --technicians=8 --minutes=10
 */
class SimulateFleet extends Command
{
    protected $signature = 'app:simulate-fleet
        {--technicians=8 : How many technicians to drive}
        {--interval=4 : Seconds between pings}
        {--minutes=0 : Stop and check everyone out after this long (0 = run until Ctrl+C)}
        {--center=24.7136,46.6753 : lat,lng the fleet starts around}
        {--url= : API base URL (default APP_URL)}
        {--flaky : Randomly drop pings so the map shows lagging and offline pins}';

    protected $description = 'Simulate technicians on shift sending GPS pings (dev only)';

    private const SHIFT_NAME = 'Simulation';

    public function handle(AuthTokenService $tokens): int
    {
        if (app()->isProduction()) {
            $this->error('The simulator creates fake shifts and locations. It never runs in production.');

            return self::FAILURE;
        }

        $base = rtrim($this->option('url') ?: config('app.url'), '/').'/api/v1';
        [$lat0, $lng0] = array_map('floatval', explode(',', $this->option('center')));
        $interval = max(1, (int) $this->option('interval'));
        $stopAt = (int) $this->option('minutes') > 0 ? now()->addMinutes((int) $this->option('minutes')) : null;

        $technicians = User::technicians()
            ->where('status', UserStatus::Active)
            ->whereNull('blocked_at')
            ->whereNull('locked_at')
            ->where('must_change_pin', false)
            ->orderBy('full_name')
            ->limit((int) $this->option('technicians'))
            ->get();

        if ($technicians->isEmpty()) {
            $this->error('No active technicians. Run: php artisan db:seed --class=DemoSeeder');

            return self::FAILURE;
        }

        $shift = Shift::firstOrCreate(['name' => self::SHIFT_NAME], [
            'start_time' => '00:00:00',
            'end_time' => '23:59:00',
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
        ]);
        $today = now(config('smc.shifts.timezone'))->toDateString();
        [$start, $end] = $shift->windowOn($today);

        // Set every technician up: token, assignment, check-in, starting point.
        $fleet = [];
        foreach ($technicians as $i => $tech) {
            $assignment = ShiftAssignment::firstOrCreate(
                ['user_id' => $tech->id, 'shift_id' => $shift->id, 'shift_date' => $today],
                ['scheduled_start_at' => $start, 'scheduled_end_at' => $end],
            );

            $active = ShiftAssignment::where('user_id', $tech->id)->where('status', AssignmentStatus::Active)->first();
            $token = $tokens->issue($tech, 'simulator')['token'];
            $angle = 2 * M_PI * $i / $technicians->count();
            $lat = $lat0 + 0.02 * sin($angle);
            $lng = $lng0 + 0.02 * cos($angle);

            if (! $active) {
                if ($assignment->status !== AssignmentStatus::Scheduled) {
                    // Only ever the simulator's own fake shift: reopen it so the simulator can run again today.
                    $assignment->forceFill([
                        'status' => AssignmentStatus::Scheduled,
                        'checked_in_at' => null, 'check_in_lat' => null, 'check_in_lng' => null, 'check_in_outside_zone' => false,
                        'checked_out_at' => null, 'check_out_lat' => null, 'check_out_lng' => null, 'auto_checked_out' => false,
                        'worked_minutes' => null, 'regular_minutes' => null, 'overtime_minutes' => null,
                    ])->save();
                }
                $res = Http::withToken($token)->acceptJson()->post("{$base}/shifts/check-in", [
                    'assignmentId' => $assignment->id, 'location' => ['lat' => $lat, 'lng' => $lng],
                ]);
                if ($res->failed()) {
                    $this->warn("{$tech->full_name}: check-in failed ({$res->status()} {$res->json('error')}), skipping.");

                    continue;
                }
                $active = $assignment;
            }

            $fleet[] = [
                'name' => $tech->full_name,
                'token' => $token,
                'assignmentId' => $active->id,
                'lat' => $lat,
                'lng' => $lng,
                'heading' => fmod(rad2deg($angle) + 90, 360),
                'speed' => random_int(4, 14),
                'stoppedFor' => 0,
                'battery' => random_int(55, 100),
            ];
        }

        if (! $fleet) {
            $this->error('Nobody could be checked in.');

            return self::FAILURE;
        }

        $this->info(sprintf('Driving %d technicians, a ping every %ds%s. Ctrl+C to stop.', count($fleet), $interval, $stopAt ? ", until {$stopAt->format('H:i:s')}" : ''));

        $tick = 0;
        while (! $stopAt || now()->lt($stopAt)) {
            $tick++;
            $tickStarted = microtime(true);
            $sent = $accepted = 0;
            $problems = [];

            // Sequential on purpose: `php artisan serve` handles one request at a time.
            foreach ($fleet as &$t) {
                $this->move($t, $interval);

                if ($this->option('flaky') && random_int(1, 100) <= 15) {
                    continue; // dropped: this pin will start to look lagging
                }

                $sent++;
                try {
                    $res = Http::withToken($t['token'])->acceptJson()->timeout(10)->post("{$base}/telemetry/ping", [
                        'latitude' => round($t['lat'], 7),
                        'longitude' => round($t['lng'], 7),
                        'accuracy' => random_int(3, 12),
                        'speed' => $t['speed'],
                        'heading' => round($t['heading'], 1),
                        'batteryLevel' => $t['battery'],
                        'isCharging' => false,
                        'recordedAt' => now()->toIso8601ZuluString('millisecond'),
                    ]);
                    $res->successful() && (int) $res->json('accepted') === 1
                        ? $accepted++
                        : $problems[] = "{$t['name']}: {$res->status()} ".($res->json('error') ?? json_encode($res->json('rejectedReasons')));
                } catch (ConnectionException $e) {
                    $problems[] = "{$t['name']}: ".$e->getMessage();
                }
            }
            unset($t);

            $this->line(sprintf('[%s] tick %d: %d/%d accepted%s', now()->format('H:i:s'), $tick, $accepted, $sent, $sent < count($fleet) ? ' ('.(count($fleet) - $sent).' dropped on purpose)' : ''));
            foreach ($problems as $problem) {
                $this->warn("  {$problem}");
            }

            usleep((int) max(0, ($interval - (microtime(true) - $tickStarted)) * 1_000_000));
        }

        foreach ($fleet as $t) {
            Http::withToken($t['token'])->acceptJson()->post("{$base}/shifts/check-out", ['assignmentId' => $t['assignmentId']]);
        }
        $this->info('Checked everyone out.');

        return self::SUCCESS;
    }

    /** A wandering drive: mostly straight, gentle turns, occasional stops. */
    private function move(array &$t, int $seconds): void
    {
        if ($t['stoppedFor'] > 0) {
            $t['stoppedFor'] -= $seconds;
            $t['speed'] = 0;

            return;
        }

        if (random_int(1, 100) <= 4) {
            $t['stoppedFor'] = random_int(20, 150); // a job site, a red light, a tea break
            $t['speed'] = 0;

            return;
        }

        $t['speed'] = max(3, min(16, ($t['speed'] ?: 6) + random_int(-2, 2)));
        $t['heading'] = fmod($t['heading'] + random_int(-25, 25) + 360, 360);
        $metres = $t['speed'] * $seconds;
        $t['lat'] += ($metres * cos(deg2rad($t['heading']))) / 111_320;
        $t['lng'] += ($metres * sin(deg2rad($t['heading']))) / (111_320 * cos(deg2rad($t['lat'])));

        if (random_int(1, 10) === 1) {
            $t['battery'] = max(5, $t['battery'] - 1);
        }
    }
}
