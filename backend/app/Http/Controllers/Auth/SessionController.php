<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Rules\SecurePin;
use App\Services\AuthTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

class SessionController extends Controller
{
    public function __construct(private readonly AuthTokenService $tokens) {}

    /** Exchange a refresh token for a new token pair. The old pair is revoked (rotation). */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->tokens->revokeSession($user->currentAccessToken());

        if (! $user->canAuthenticate()) {
            throw ApiException::forUser($user);
        }

        return response()->json([
            ...$this->tokens->issue($user, $user->currentAccessToken()->device_name),
            'user' => new UserResource($user->load('department')),
        ]);
    }

    public function logout(Request $request): Response
    {
        $this->tokens->revokeSession($request->user()->currentAccessToken());

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('department', 'documents'));
    }

    public function changePin(Request $request): UserResource
    {
        $user = $request->user();

        if ($user->role->usesConsole()) {
            throw new ApiException('NOT_A_TECHNICIAN', 'Only technician accounts use a PIN.', 422);
        }

        $data = $request->validate([
            'currentPin' => ['required', 'string'],
            'newPin' => ['required', new SecurePin, 'different:currentPin'],
        ]);

        if (! Hash::check($data['currentPin'], $user->pin_hash)) {
            throw new ApiException('INVALID_CURRENT_PIN', 'The current PIN is incorrect.', 422);
        }

        $user->forceFill(['pin_hash' => $data['newPin'], 'must_change_pin' => false])->save();

        // Sign out other devices; keep the session that made the change.
        $user->tokens()->where('session_id', '!=', $user->currentAccessToken()->session_id)->delete();

        return new UserResource($user);
    }
}
