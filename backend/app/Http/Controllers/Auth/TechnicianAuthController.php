<?php

namespace App\Http\Controllers\Auth;

use App\Enums\DocumentType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Rules\E164Phone;
use App\Rules\SecurePin;
use App\Services\AuthTokenService;
use App\Services\DocumentStorage;
use App\Services\LoginGuard;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TechnicianAuthController extends Controller
{
    /** MOD-01: self-registration. Documents are optional and may be attached here. */
    public function register(Request $request, DocumentStorage $storage): JsonResponse
    {
        $request->merge(['phone' => PhoneNumber::normalize($request->input('phone')) ?? $request->input('phone')]);

        $data = $request->validate([
            'phone' => ['required', 'string', new E164Phone, Rule::unique('users', 'phone_number')],
            'fullName' => ['required', 'string', 'min:2', 'max:150'],
            'pin' => ['required', new SecurePin],
            'departmentId' => ['nullable', 'uuid', Rule::exists('departments', 'id')],
            'documents' => ['sometimes', 'array', 'max:5'],
            'documents.*.file' => ['required', ...DocumentStorage::rules()],
            'documents.*.docType' => ['required', Rule::enum(DocumentType::class)],
        ], ['phone.unique' => 'This phone number is already registered.']);

        $user = DB::transaction(function () use ($data, $request, $storage) {
            $user = User::create([
                'full_name' => $data['fullName'],
                'role' => UserRole::Technician,
                'status' => UserStatus::PendingApproval,
                'phone_number' => $data['phone'],
                'pin_hash' => $data['pin'],
                'department_id' => $data['departmentId'] ?? null,
            ]);

            foreach ($request->file('documents', []) as $i => $doc) {
                $storage->store($user, $doc['file'], DocumentType::from($data['documents'][$i]['docType']), $user);
            }

            return $user;
        });

        return (new UserResource($user->load('documents')))
            ->additional(['message' => 'Registration received. An administrator will review your account.'])
            ->response()
            ->setStatusCode(201);
    }

    /** MOD-03: phone + 4-digit PIN login. */
    public function login(Request $request, LoginGuard $guard, AuthTokenService $tokens): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string'],
            'pin' => ['required', 'string'],
            'deviceName' => ['nullable', 'string', 'max:100'],
        ]);

        $phone = PhoneNumber::normalize($request->input('phone')) ?? $request->input('phone');
        $user = User::technicians()->where('phone_number', $phone)->first();

        $user = $guard->attempt($phone, $user, $request->input('pin'), 'pin_hash');

        return response()->json([
            ...$tokens->issue($user, $request->input('deviceName')),
            'user' => new UserResource($user->load('department')),
        ]);
    }
}
