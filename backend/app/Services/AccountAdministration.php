<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Events\SessionRevoked;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Rules\SecurePin;

/**
 * Admin actions that change whether a user can sign in. Every action that
 * removes access revokes tokens and notifies open sessions in the same step.
 */
class AccountAdministration
{
    public function __construct(
        private readonly AuthTokenService $tokens,
        private readonly LoginGuard $guard,
    ) {}

    public function approve(User $user, User $admin): void
    {
        $user->forceFill([
            'status' => UserStatus::Active,
            'approved_at' => now(),
            'approved_by' => $admin->id,
            'rejection_reason' => null,
        ])->save();
    }

    public function reject(User $user, User $admin, string $reason): void
    {
        $user->forceFill([
            'status' => UserStatus::Rejected,
            'approved_at' => null,
            'approved_by' => null,
            'rejection_reason' => $reason,
        ])->save();

        $this->endSessions($user, 'REJECTED');
    }

    public function block(User $user, User $admin, ?string $reason): void
    {
        if ($user->is($admin)) {
            throw new ApiException('CANNOT_BLOCK_SELF', 'You cannot block your own account.', 422);
        }

        $user->forceFill([
            'blocked_at' => now(),
            'blocked_by' => $admin->id,
            'block_reason' => $reason,
        ])->save();

        $this->endSessions($user, 'BLOCKED');
    }

    public function unblock(User $user): void
    {
        $user->forceFill(['blocked_at' => null, 'blocked_by' => null, 'block_reason' => null])->save();
    }

    /** Issue a temporary PIN the technician must replace at next login. Returns the PIN. */
    public function resetPin(User $user): string
    {
        if ($user->role->usesConsole()) {
            throw new ApiException('NOT_A_TECHNICIAN', 'Only technician accounts use a PIN.', 422);
        }

        $pin = SecurePin::generate();
        $user->forceFill(['pin_hash' => $pin, 'must_change_pin' => true])->save();
        $this->unlock($user);
        $this->endSessions($user, 'PIN_RESET');

        return $pin;
    }

    public function unlock(User $user): void
    {
        $user->forceFill(['locked_at' => null, 'failed_login_attempts' => 0])->save();
        $this->guard->clear($user->phone_number ?? $user->email);
    }

    private function endSessions(User $user, string $reason): void
    {
        $this->tokens->revokeAll($user);
        SessionRevoked::dispatch($user->id, $reason);
    }
}
