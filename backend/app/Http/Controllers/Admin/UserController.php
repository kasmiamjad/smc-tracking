<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Rules\E164Phone;
use App\Rules\SecurePin;
use App\Services\AccountAdministration;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function __construct(private readonly AccountAdministration $accounts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'departmentId' => ['nullable', 'uuid'],
            'blocked' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $users = User::query()
            ->with('department')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['role'] ?? null, fn ($q, $v) => $q->where('role', $v))
            ->when($filters['departmentId'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when(isset($filters['blocked']), fn ($q) => $request->boolean('blocked') ? $q->whereNotNull('blocked_at') : $q->whereNull('blocked_at'))
            ->when($filters['search'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($q) => $q->where('full_name', 'like', $like)->orWhere('phone_number', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->orderBy('full_name')
            ->paginate($filters['perPage'] ?? 50);

        return UserResource::collection($users);
    }

    /** MOD-05: approval queue, oldest first, with documents for inline preview. */
    public function pending(): AnonymousResourceCollection
    {
        return UserResource::collection(
            User::where('status', UserStatus::PendingApproval)->with('department', 'documents')->oldest()->get()
        );
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load('department', 'documents'));
    }

    /**
     * MOD-02: admin pre-provisioning. Created accounts are active immediately.
     * Technicians get a temporary PIN (given or generated) they must change on first login.
     */
    public function store(Request $request): JsonResponse
    {
        $request->merge(['phone' => PhoneNumber::normalize($request->input('phone')) ?? $request->input('phone')]);
        $role = UserRole::tryFrom($request->input('role', UserRole::Technician->value));

        $data = $request->validate([
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'fullName' => ['required', 'string', 'min:2', 'max:150'],
            'departmentId' => ['nullable', 'uuid', Rule::exists('departments', 'id')],
            ...($role?->usesConsole()
                ? [
                    'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
                    'password' => ['required', Password::min(10)->letters()->numbers()],
                ]
                : [
                    'phone' => ['required', 'string', new E164Phone, Rule::unique('users', 'phone_number')],
                    'pin' => ['nullable', new SecurePin],
                ]),
        ], ['phone.unique' => 'This phone number is already registered.']);

        $temporaryPin = null;
        $attributes = [
            'full_name' => $data['fullName'],
            'role' => $role,
            'status' => UserStatus::Active,
            'department_id' => $data['departmentId'] ?? null,
        ];

        if ($role->usesConsole()) {
            $attributes += ['email' => strtolower($data['email']), 'password' => $data['password']];
        } else {
            $temporaryPin = $data['pin'] ?? SecurePin::generate();
            $attributes += ['phone_number' => $data['phone'], 'pin_hash' => $temporaryPin, 'must_change_pin' => true];
        }

        $user = User::create($attributes);
        $user->forceFill(['approved_at' => now(), 'approved_by' => $request->user()->id])->save();

        return (new UserResource($user->load('department')))
            ->additional(['temporaryPin' => isset($data['pin']) ? null : $temporaryPin])
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, User $user): UserResource
    {
        if ($request->has('phone')) {
            $request->merge(['phone' => PhoneNumber::normalize($request->input('phone')) ?? $request->input('phone')]);
        }

        $data = $request->validate([
            'fullName' => ['sometimes', 'string', 'min:2', 'max:150'],
            'departmentId' => ['sometimes', 'nullable', 'uuid', Rule::exists('departments', 'id')],
            'phone' => ['sometimes', 'string', new E164Phone, Rule::unique('users', 'phone_number')->ignore($user->id)],
        ]);

        $user->fill(array_filter([
            'full_name' => $data['fullName'] ?? null,
            'phone_number' => $data['phone'] ?? null,
        ]));
        if (array_key_exists('departmentId', $data)) {
            $user->department_id = $data['departmentId'];
        }
        $user->save();

        return new UserResource($user->load('department'));
    }

    public function approval(Request $request, User $user): UserResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([UserStatus::Active->value, UserStatus::Rejected->value])],
            'reason' => ['required_if:status,REJECTED', 'nullable', 'string', 'max:500'],
        ]);

        $data['status'] === UserStatus::Active->value
            ? $this->accounts->approve($user, $request->user())
            : $this->accounts->reject($user, $request->user(), $data['reason']);

        return new UserResource($user->load('department'));
    }

    /** MOD-05B: block / unblock. Blocking revokes all tokens and kicks live sessions. */
    public function block(Request $request, User $user): UserResource
    {
        $data = $request->validate([
            'isBlocked' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $request->boolean('isBlocked')
            ? $this->accounts->block($user, $request->user(), $data['reason'] ?? null)
            : $this->accounts->unblock($user);

        return new UserResource($user->load('department'));
    }

    public function resetPin(User $user): JsonResponse
    {
        $pin = $this->accounts->resetPin($user);

        return (new UserResource($user))->additional(['temporaryPin' => $pin])->response();
    }

    public function unlock(User $user): UserResource
    {
        $this->accounts->unlock($user);

        return new UserResource($user);
    }
}
