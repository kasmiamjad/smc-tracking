<?php

namespace App\Events;

use App\Models\TechnicianPosition;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A technician's live pin moved. Sent immediately (not queued): the live map
 * is only useful in real time. Console users subscribe to private-fleet.
 */
class FleetPositionUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public readonly TechnicianPosition $position) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('fleet')];
    }

    public function broadcastAs(): string
    {
        return 'position.updated';
    }

    public function broadcastWith(): array
    {
        $p = $this->position;

        return [
            'userId' => $p->user_id,
            'assignmentId' => $p->shift_assignment_id,
            'latitude' => $p->latitude,
            'longitude' => $p->longitude,
            'accuracy' => $p->accuracy,
            'speed' => $p->speed,
            'heading' => $p->heading,
            'batteryLevel' => $p->battery_level,
            'isCharging' => $p->is_charging,
            'recordedAt' => $p->recorded_at->toIso8601ZuluString('millisecond'),
        ];
    }
}
