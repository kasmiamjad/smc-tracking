<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TechnicianAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_pending_account_with_normalized_phone(): void
    {
        $department = Department::factory()->create();

        $this->postJson('/api/v1/auth/register', [
            'phone' => '+966 50-123 4567',
            'fullName' => 'Ahmed Ali',
            'pin' => '2580',
            'departmentId' => $department->id,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'PENDING_APPROVAL')
            ->assertJsonPath('data.phone', '+966501234567')
            ->assertJsonPath('data.mustChangePin', false)
            ->assertJsonMissingPath('data.pin_hash');

        $user = User::firstWhere('phone_number', '+966501234567');
        $this->assertNotSame('2580', $user->pin_hash);
        $this->assertSame($department->id, $user->department_id);
    }

    public function test_registration_accepts_optional_documents(): void
    {
        Storage::fake('local');

        $this->post('/api/v1/auth/register', [
            'phone' => '+966501234567',
            'fullName' => 'Ahmed Ali',
            'pin' => '2580',
            'documents' => [['file' => UploadedFile::fake()->create('id.pdf', 200, 'application/pdf'), 'docType' => 'NATIONAL_ID']],
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(1, 'data.documents');

        Storage::disk('local')->assertExists(User::first()->documents()->first()->path);
    }

    public function test_duplicate_phone_is_rejected_even_with_different_formatting(): void
    {
        User::factory()->create(['phone_number' => '+966501234567']);

        $this->postJson('/api/v1/auth/register', ['phone' => '00966 501234567', 'fullName' => 'Someone', 'pin' => '2580'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => 'already registered']);
    }

    #[DataProvider('weakPins')]
    public function test_weak_pins_are_rejected(string $pin): void
    {
        $this->postJson('/api/v1/auth/register', ['phone' => '+966501234567', 'fullName' => 'Someone', 'pin' => $pin])
            ->assertJsonValidationErrors('pin');
    }

    public static function weakPins(): array
    {
        return [['0000'], ['1234'], ['9876'], ['123'], ['12345'], ['12a4']];
    }

    public function test_active_technician_logs_in_with_pin(): void
    {
        $user = User::factory()->create(['phone_number' => '+966501234567', 'pin_hash' => '2580']);

        $response = $this->postJson('/api/v1/auth/login-pin', ['phone' => '+966501234567', 'pin' => '2580'])
            ->assertOk()
            ->assertJsonStructure(['token', 'tokenExpiresAt', 'refreshToken', 'refreshTokenExpiresAt', 'user' => ['id', 'fullName']]);

        $this->withToken($response->json('token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_pending_account_cannot_log_in_but_only_after_correct_pin(): void
    {
        User::factory()->pending()->create(['phone_number' => '+966501234567', 'pin_hash' => '2580']);

        // A wrong PIN does not reveal that the account exists and is pending.
        $this->postJson('/api/v1/auth/login-pin', ['phone' => '+966501234567', 'pin' => '1357'])
            ->assertUnauthorized()->assertJsonPath('error', 'INVALID_CREDENTIALS');

        $this->postJson('/api/v1/auth/login-pin', ['phone' => '+966501234567', 'pin' => '2580'])
            ->assertForbidden()->assertJsonPath('error', 'ACCOUNT_PENDING_APPROVAL');
    }

    public function test_blocked_number_gets_spec_error(): void
    {
        User::factory()->create(['phone_number' => '+966501234567', 'pin_hash' => '2580', 'blocked_at' => now()]);

        $this->postJson('/api/v1/auth/login-pin', ['phone' => '+966501234567', 'pin' => '2580'])
            ->assertForbidden()
            ->assertExactJson(['error' => 'ACCOUNT_BLOCKED', 'message' => 'This phone number has been blocked by administration.']);
    }

    public function test_five_consecutive_failures_lock_the_account(): void
    {
        $user = User::factory()->create(['phone_number' => '+966501234567', 'pin_hash' => '2580']);

        foreach ([4, 3, 2, 1] as $remaining) {
            $this->postJson('/api/v1/auth/login-pin', ['phone' => '+966501234567', 'pin' => '1357'])
                ->assertUnauthorized()->assertJsonPath('attemptsRemaining', $remaining);
        }

        $this->postJson('/api/v1/auth/login-pin', ['phone' => '+966501234567', 'pin' => '1357'])
            ->assertStatus(423)->assertJsonPath('error', 'ACCOUNT_LOCKED');

        $this->assertTrue($user->fresh()->isLocked());
    }

    public function test_unknown_numbers_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login-pin', ['phone' => '+966509999999', 'pin' => '1357'])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login-pin', ['phone' => '+966509999999', 'pin' => '1357'])
            ->assertStatus(429)->assertJsonPath('error', 'TOO_MANY_ATTEMPTS');
    }

    public function test_successful_login_resets_failure_counter(): void
    {
        $user = User::factory()->create(['phone_number' => '+966501234567', 'pin_hash' => '2580']);

        $this->postJson('/api/v1/auth/login-pin', ['phone' => '+966501234567', 'pin' => '1357']);
        $this->postJson('/api/v1/auth/login-pin', ['phone' => '+966501234567', 'pin' => '2580'])->assertOk();

        $this->assertSame(0, $user->fresh()->failed_login_attempts);
    }

    public function test_console_accounts_cannot_use_pin_login(): void
    {
        $admin = User::factory()->admin()->create();

        $this->postJson('/api/v1/auth/login-pin', ['phone' => $admin->email, 'pin' => 'password'])->assertUnauthorized();
    }

    public function test_temporary_pin_must_be_changed_before_using_the_api(): void
    {
        $user = User::factory()->create(['pin_hash' => '4826', 'must_change_pin' => true]);
        $this->actingAsUser($user);

        $this->getJson('/api/v1/users/'.$user->id.'/documents')->assertForbidden()->assertJsonPath('error', 'PIN_CHANGE_REQUIRED');

        $this->postJson('/api/v1/auth/change-pin', ['currentPin' => '4826', 'newPin' => '4826'])->assertJsonValidationErrors('newPin');
        $this->postJson('/api/v1/auth/change-pin', ['currentPin' => '0000', 'newPin' => '3971'])->assertJsonPath('error', 'INVALID_CURRENT_PIN');
        $this->postJson('/api/v1/auth/change-pin', ['currentPin' => '4826', 'newPin' => '3971'])->assertOk()->assertJsonPath('data.mustChangePin', false);

        $this->getJson('/api/v1/users/'.$user->id.'/documents')->assertOk();
    }

    public function test_status_change_during_session_is_enforced_immediately(): void
    {
        $user = User::factory()->create();
        $this->actingAsUser($user);
        $this->getJson('/api/v1/auth/me')->assertOk();

        $user->forceFill(['status' => UserStatus::Rejected])->save();
        $this->app['auth']->forgetGuards(); // a real request never shares the guard's cached user

        $this->getJson('/api/v1/auth/me')->assertForbidden()->assertJsonPath('error', 'ACCOUNT_REJECTED');
        $this->assertSame(0, $user->tokens()->count());
    }
}
