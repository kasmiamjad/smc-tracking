<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Brute-force protection shared by PIN and console login (spec 6.1):
 *  - at most N failed attempts per identifier per window (covers unknown numbers too);
 *  - N consecutive failures on a real account lock it until an admin resets it.
 * Account status is only revealed after the secret is verified, so the
 * endpoint can't be used to discover which numbers are registered.
 */
class LoginGuard
{
    public function attempt(string $identifier, ?User $user, string $secret, string $hashColumn): User
    {
        $key = $this->key($identifier);
        $max = config('smc.auth.max_login_attempts');
        $decaySeconds = config('smc.auth.login_attempt_window_minutes') * 60;

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new ApiException('TOO_MANY_ATTEMPTS', 'Too many login attempts. Try again later.', 429,
                ['retryAfterSeconds' => RateLimiter::availableIn($key)]);
        }

        if ($user === null || $user->{$hashColumn} === null) {
            Hash::check($secret, $this->dummyHash()); // keep timing similar to a real check
            RateLimiter::hit($key, $decaySeconds);
            throw ApiException::invalidCredentials();
        }

        if ($user->isLocked()) {
            throw ApiException::forUser($user);
        }

        if (! Hash::check($secret, $user->{$hashColumn})) {
            RateLimiter::hit($key, $decaySeconds);
            $user->increment('failed_login_attempts');

            if ($user->failed_login_attempts >= $max) {
                $user->forceFill(['locked_at' => now()])->save();
                throw ApiException::forUser($user);
            }

            throw ApiException::invalidCredentials($max - $user->failed_login_attempts);
        }

        RateLimiter::clear($key);
        $user->forceFill(['failed_login_attempts' => 0])->save();

        if (! $user->canAuthenticate()) {
            throw ApiException::forUser($user);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }

    public function clear(string $identifier): void
    {
        RateLimiter::clear($this->key($identifier));
    }

    private function key(string $identifier): string
    {
        return 'login:'.sha1(strtolower($identifier));
    }

    private function dummyHash(): string
    {
        static $hash;

        return $hash ??= Hash::make('dummy-secret');
    }
}
