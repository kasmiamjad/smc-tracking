<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\AuthTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-checks account state on every authenticated request, so a user who is
 * blocked, rejected or locked loses access even if a token somehow survived.
 * Users holding a temporary PIN may only reach the routes needed to change it.
 */
class EnsureAccountActive
{
    private const ALLOWED_WITH_TEMPORARY_PIN = ['auth.me', 'auth.change-pin', 'auth.logout', 'auth.refresh'];

    public function __construct(private readonly AuthTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user->canAuthenticate()) {
            $this->tokens->revokeAll($user);
            throw ApiException::forUser($user);
        }

        if ($user->must_change_pin && ! $request->routeIs(...self::ALLOWED_WITH_TEMPORARY_PIN)) {
            throw new ApiException('PIN_CHANGE_REQUIRED', 'Set a new PIN before continuing.', 403);
        }

        return $next($request);
    }
}
