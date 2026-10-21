<?php

namespace App\Exceptions;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Domain error rendered as {"error": "CODE", "message": "..."} (spec 4.1).
 */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function invalidCredentials(?int $attemptsRemaining = null): self
    {
        return new self('INVALID_CREDENTIALS', 'The credentials provided are incorrect.', 401,
            $attemptsRemaining === null ? [] : ['attemptsRemaining' => $attemptsRemaining]);
    }

    /** The reason this user may not hold a session. */
    public static function forUser(User $user): self
    {
        return match (true) {
            $user->isBlocked() => new self('ACCOUNT_BLOCKED', 'This phone number has been blocked by administration.', 403),
            $user->isLocked() => new self('ACCOUNT_LOCKED', 'Too many failed attempts. Ask an administrator to reset your access.', 423),
            $user->status === UserStatus::PendingApproval => new self('ACCOUNT_PENDING_APPROVAL', 'Your account is waiting for administrator approval.', 403),
            $user->status === UserStatus::Rejected => new self('ACCOUNT_REJECTED', 'Your registration was rejected.', 403),
            default => new self('ACCOUNT_UNAVAILABLE', 'This account cannot sign in.', 403),
        };
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => $this->errorCode,
            'message' => $this->getMessage(),
            ...$this->extra,
        ], $this->status);
    }
}
