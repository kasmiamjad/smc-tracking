<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Guards against the VPS failure where pcntl_signal is disabled in php.ini:
 * Laravel then crashes after taking a job's lock, and a long lock stalls it.
 */
class ScheduleTest extends TestCase
{
    /** @return array<string, Event> */
    private function events(): array
    {
        $events = [];
        foreach (app(Schedule::class)->events() as $event) {
            preg_match('/artisan["\']? (\S+)/', $event->command, $m); // "artisan" is quoted differently per OS
            $events[$m[1] ?? $event->command] = $event;
        }

        return $events;
    }

    public function test_expected_jobs_are_scheduled(): void
    {
        $this->assertEqualsCanonicalizing(['sanctum:prune-expired', 'app:close-overdue-shifts', 'app:archive-routes'], array_keys($this->events()));
        $this->assertSame('*/5 * * * *', $this->events()['app:close-overdue-shifts']->expression);
    }

    public function test_locks_are_short_and_signal_handlers_only_used_when_available(): void
    {
        foreach ($this->events() as $name => $event) {
            $this->assertTrue($event->withoutOverlapping, "{$name} must not overlap");
            $this->assertLessThanOrEqual(120, $event->expiresAt, "{$name}: a crashed run must not block the job for long");
            $this->assertSame(function_exists('pcntl_signal'), $event->releaseOnTerminationSignals, "{$name}: would crash where pcntl_signal is disabled");
        }
        $this->assertLessThanOrEqual(10, $this->events()['app:close-overdue-shifts']->expiresAt);
    }
}
