<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

$apiError = fn (string $code, string $message, int $status) => response()->json(['error' => $code, 'message' => $message], $status);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api/v1', 'middleware' => ['auth:sanctum', 'abilities:access']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // There is no server-rendered login page: API callers get a JSON 401
        // (rendered below) and anything else is sent to the console's sign-in.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/admin/login');

        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'active' => EnsureAccountActive::class,
            'role' => EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($apiError): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Same {"error","message"} envelope as ApiException for framework errors.
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/*')
            ? $apiError('UNAUTHENTICATED', 'Missing, invalid or expired token.', 401) : null);
        // Laravel converts AuthorizationException to AccessDeniedHttpException before rendering.
        $exceptions->render(fn (AccessDeniedHttpException $e, Request $request) => match (true) {
            ! $request->is('api/*') => null,
            // Wrong token type: refresh token on an API call, or vice versa.
            $e->getPrevious() instanceof MissingAbilityException => $apiError('INVALID_TOKEN_TYPE', 'This token cannot be used for this request.', 401),
            default => $apiError('FORBIDDEN', 'You do not have permission to perform this action.', 403),
        });
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $request->is('api/*')
            ? $apiError('NOT_FOUND', 'The requested resource was not found.', 404) : null);
        $exceptions->render(fn (TooManyRequestsHttpException $e, Request $request) => $request->is('api/*')
            ? $apiError('TOO_MANY_REQUESTS', 'Too many requests. Slow down.', 429) : null);
    })->create();
