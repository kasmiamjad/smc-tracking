<?php

use Illuminate\Support\Facades\Broadcast;

// Per-user channel: session revocation now, siren alerts in Sprint 4.
Broadcast::channel('users.{id}', function ($user, string $id) {
    return $user->id === $id && $user->canAuthenticate();
});

// Live fleet positions, for admins and dispatchers.
Broadcast::channel('fleet', function ($user) {
    return $user->role->usesConsole() && $user->canAuthenticate();
});
