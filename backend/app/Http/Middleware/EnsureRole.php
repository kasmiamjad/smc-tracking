<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('role:ADMIN,DISPATCHER') */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! in_array($request->user()?->role->value, $roles, true)) {
            throw new ApiException('FORBIDDEN', 'You do not have permission to perform this action.', 403);
        }

        return $next($request);
    }
}
