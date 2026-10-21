<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells the user's open apps to sign out and stop tracking at once (spec 4.1.3).
 * Tokens are already deleted when this fires, so the client cannot
 * re-subscribe or keep sending telemetry even if it ignores the event.
 */
class SessionRevoked implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $userId,
        public readonly string $reason,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'session.revoked';
    }

    public function broadcastWith(): array
    {
        return ['reason' => $this->reason];
    }
}
