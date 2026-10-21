<?php

namespace App\Services;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Each login creates a "session": one short-lived access token and one
 * long-lived refresh token sharing a session_id. Sanctum checks tokens
 * against the database on every request, so deleting rows revokes instantly.
 */
class AuthTokenService
{
    public const ABILITY_ACCESS = 'access';

    public const ABILITY_REFRESH = 'refresh';

    public function issue(User $user, ?string $deviceName = null): array
    {
        $sessionId = (string) Str::uuid();
        $accessExpiresAt = now()->addMinutes(config('smc.auth.access_token_ttl_minutes'));
        $refreshExpiresAt = now()->addDays(config('smc.auth.refresh_token_ttl_days'));

        $access = $user->createToken('access', [self::ABILITY_ACCESS], $accessExpiresAt);
        $refresh = $user->createToken('refresh', [self::ABILITY_REFRESH], $refreshExpiresAt);

        PersonalAccessToken::whereKey([$access->accessToken->getKey(), $refresh->accessToken->getKey()])
            ->update([
                'session_id' => $sessionId,
                'device_name' => $deviceName ? Str::limit($deviceName, 100, '') : null,
            ]);

        return [
            'token' => $access->plainTextToken,
            'tokenExpiresAt' => $accessExpiresAt->toIso8601String(),
            'refreshToken' => $refresh->plainTextToken,
            'refreshTokenExpiresAt' => $refreshExpiresAt->toIso8601String(),
        ];
    }

    /** Revoke both tokens of the session the given token belongs to. */
    public function revokeSession(PersonalAccessToken $token): void
    {
        $token->session_id
            ? PersonalAccessToken::where('session_id', $token->session_id)->delete()
            : $token->delete();
    }

    public function revokeAll(User $user): void
    {
        $user->tokens()->delete();
    }
}
