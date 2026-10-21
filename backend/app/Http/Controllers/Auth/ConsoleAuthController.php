<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthTokenService;
use App\Services\LoginGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin / dispatcher sign-in for the web console. A 4-digit PIN is too weak
 * for accounts that can see every technician's location, so these use
 * email + password with the same lockout rules.
 */
class ConsoleAuthController extends Controller
{
    public function login(Request $request, LoginGuard $guard, AuthTokenService $tokens): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = strtolower($data['email']);
        $user = User::whereIn('role', [UserRole::Admin, UserRole::Dispatcher])->where('email', $email)->first();

        $user = $guard->attempt($email, $user, $data['password'], 'password');

        return response()->json([
            ...$tokens->issue($user, 'web-console'),
            'user' => new UserResource($user),
        ]);
    }
}
