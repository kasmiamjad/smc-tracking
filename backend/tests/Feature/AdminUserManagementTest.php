<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Events\SessionRevoked;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_pending_queue_lists_only_pending_users_with_documents(): void
    {
        $pending = User::factory()->pending()->count(2)->create();
        User::factory()->create();

        $this->actingAsUser($this->admin)->getJson('/api/v1/admin/users/pending')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $pending[0]->id)
            ->assertJsonPath('data.0.documents', []);
    }

    public function test_admin_approves_without_documents(): void
    {
        $user = User::factory()->pending()->create();

        $this->actingAsUser($this->admin)->patchJson("/api/v1/admin/users/{$user->id}/approval", ['status' => 'ACTIVE'])
            ->assertOk()->assertJsonPath('data.status', 'ACTIVE');

        $this->assertSame($this->admin->id, $user->fresh()->approved_by);
    }

    public function test_rejection_requires_a_reason(): void
    {
        $user = User::factory()->pending()->create();
        $this->actingAsUser($this->admin);

        $this->patchJson("/api/v1/admin/users/{$user->id}/approval", ['status' => 'REJECTED'])->assertJsonValidationErrors('reason');
        $this->patchJson("/api/v1/admin/users/{$user->id}/approval", ['status' => 'REJECTED', 'reason' => 'Unknown person'])
            ->assertOk()->assertJsonPath('data.rejectionReason', 'Unknown person');
    }

    public function test_blocking_revokes_tokens_and_notifies_open_sessions(): void
    {
        Event::fake([SessionRevoked::class]);
        $technician = User::factory()->create();
        $technicianTokens = $this->tokensFor($technician);

        $this->actingAsUser($this->admin)
            ->patchJson("/api/v1/admin/users/{$technician->id}/block", ['isBlocked' => true, 'reason' => 'Left company'])
            ->assertOk()->assertJsonPath('data.isBlocked', true);

        Event::assertDispatched(SessionRevoked::class, fn ($e) => $e->userId === $technician->id && $e->reason === 'BLOCKED');
        $this->assertSame(0, $technician->tokens()->count());
        $this->withToken($technicianTokens['token'])->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login-pin', ['phone' => $technician->phone_number, 'pin' => '2580'])
            ->assertJsonPath('error', 'ACCOUNT_BLOCKED');
    }

    public function test_unblocking_restores_login(): void
    {
        $technician = User::factory()->create(['blocked_at' => now(), 'block_reason' => 'x']);

        $this->actingAsUser($this->admin)->patchJson("/api/v1/admin/users/{$technician->id}/block", ['isBlocked' => false])
            ->assertOk()->assertJsonPath('data.isBlocked', false);

        $this->postJson('/api/v1/auth/login-pin', ['phone' => $technician->phone_number, 'pin' => '2580'])->assertOk();
    }

    public function test_admin_cannot_block_self(): void
    {
        $this->actingAsUser($this->admin)->patchJson("/api/v1/admin/users/{$this->admin->id}/block", ['isBlocked' => true])
            ->assertUnprocessable()->assertJsonPath('error', 'CANNOT_BLOCK_SELF');
    }

    public function test_admin_provisions_technician_with_generated_temporary_pin(): void
    {
        $department = Department::factory()->create();

        $response = $this->actingAsUser($this->admin)->postJson('/api/v1/admin/users', [
            'fullName' => 'Sara Khan', 'phone' => '+966551112233', 'departmentId' => $department->id,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.mustChangePin', true);

        $pin = $response->json('temporaryPin');
        $this->assertMatchesRegularExpression('/^\d{4}$/', $pin);

        $this->postJson('/api/v1/auth/login-pin', ['phone' => '+966551112233', 'pin' => $pin])
            ->assertOk()->assertJsonPath('user.mustChangePin', true);
    }

    public function test_admin_provisions_dispatcher_with_email(): void
    {
        $this->actingAsUser($this->admin)->postJson('/api/v1/admin/users', [
            'role' => 'DISPATCHER', 'fullName' => 'Desk One', 'email' => 'desk@example.com', 'password' => 'longpassword1',
        ])->assertCreated()->assertJsonPath('data.role', 'DISPATCHER')->assertJsonPath('temporaryPin', null);

        $this->postJson('/api/v1/auth/login', ['email' => 'desk@example.com', 'password' => 'longpassword1'])->assertOk();
    }

    public function test_reset_pin_unlocks_and_signs_out(): void
    {
        $technician = User::factory()->create(['locked_at' => now(), 'failed_login_attempts' => 5]);
        $this->tokensFor($technician);

        $pin = $this->actingAsUser($this->admin)->postJson("/api/v1/admin/users/{$technician->id}/reset-pin")
            ->assertOk()->json('temporaryPin');

        $technician->refresh();
        $this->assertFalse($technician->isLocked());
        $this->assertTrue($technician->must_change_pin);
        $this->assertSame(0, $technician->tokens()->count());
        $this->postJson('/api/v1/auth/login-pin', ['phone' => $technician->phone_number, 'pin' => $pin])->assertOk();
    }

    public function test_unlock_clears_lockout_and_rate_limit(): void
    {
        $technician = User::factory()->create(['phone_number' => '+966501234567']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login-pin', ['phone' => '+966501234567', 'pin' => '1357']);
        }

        $this->actingAsUser($this->admin)->postJson("/api/v1/admin/users/{$technician->id}/unlock")->assertOk()->assertJsonPath('data.isLocked', false);

        $this->postJson('/api/v1/auth/login-pin', ['phone' => '+966501234567', 'pin' => '2580'])->assertOk();
    }

    public function test_dispatcher_can_read_but_not_manage_users(): void
    {
        $dispatcher = User::factory()->dispatcher()->create();
        $technician = User::factory()->pending()->create();
        $this->actingAsUser($dispatcher);

        $this->getJson('/api/v1/admin/users/pending')->assertOk();
        $this->getJson("/api/v1/admin/users/{$technician->id}")->assertOk();
        $this->patchJson("/api/v1/admin/users/{$technician->id}/approval", ['status' => 'ACTIVE'])->assertForbidden();
        $this->patchJson("/api/v1/admin/users/{$technician->id}/block", ['isBlocked' => true])->assertForbidden();
    }

    public function test_technician_cannot_reach_admin_routes(): void
    {
        $this->actingAsUser(User::factory()->create())->getJson('/api/v1/admin/users')
            ->assertForbidden()->assertJsonPath('error', 'FORBIDDEN');
    }

    public function test_user_list_filters_and_search(): void
    {
        $department = Department::factory()->create();
        User::factory()->create(['full_name' => 'Omar Plumber', 'department_id' => $department->id]);
        User::factory()->pending()->create(['full_name' => 'Omar Pending']);
        User::factory()->create(['full_name' => 'Zaid Other']);
        $this->actingAsUser($this->admin);

        $this->getJson('/api/v1/admin/users?search=omar')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/admin/users?search=omar&status=ACTIVE')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/admin/users?departmentId={$department->id}")->assertJsonPath('data.0.fullName', 'Omar Plumber');
        $this->getJson('/api/v1/admin/users?role=TECHNICIAN&blocked=0')->assertJsonCount(3, 'data');
    }

    public function test_admin_updates_technician_details(): void
    {
        $technician = User::factory()->create();
        $department = Department::factory()->create();

        $this->actingAsUser($this->admin)->patchJson("/api/v1/admin/users/{$technician->id}", [
            'fullName' => 'Renamed', 'departmentId' => $department->id, 'phone' => '+966 55 000 1111',
        ])->assertOk()->assertJsonPath('data.phone', '+966550001111')->assertJsonPath('data.department.id', $department->id);

        $this->patchJson("/api/v1/admin/users/{$technician->id}", ['departmentId' => null])->assertJsonPath('data.departmentId', null);
        $this->assertSame(UserStatus::Active, $technician->fresh()->status);
    }
}
