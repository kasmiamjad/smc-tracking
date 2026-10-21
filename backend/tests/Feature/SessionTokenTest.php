<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_rotates_the_token_pair(): void
    {
        $user = User::factory()->create();
        $old = $this->tokensFor($user);

        $new = $this->withToken($old['refreshToken'])->postJson('/api/v1/auth/refresh')
            ->assertOk()->assertJsonStructure(['token', 'refreshToken'])->json();

        $this->assertNotSame($old['token'], $new['token']);
        $this->withToken($old['token'])->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withToken($old['refreshToken'])->postJson('/api/v1/auth/refresh')->assertUnauthorized();
        $this->withToken($new['token'])->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_token_types_cannot_be_swapped(): void
    {
        $tokens = $this->tokensFor(User::factory()->create());

        $this->withToken($tokens['refreshToken'])->getJson('/api/v1/auth/me')
            ->assertUnauthorized()->assertJsonPath('error', 'INVALID_TOKEN_TYPE');
        $this->withToken($tokens['token'])->postJson('/api/v1/auth/refresh')
            ->assertUnauthorized()->assertJsonPath('error', 'INVALID_TOKEN_TYPE');
    }

    public function test_expired_access_token_is_rejected(): void
    {
        $tokens = $this->tokensFor(User::factory()->create());

        $this->travel(config('smc.auth.access_token_ttl_minutes') + 1)->minutes();

        $this->withToken($tokens['token'])->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('error', 'UNAUTHENTICATED');
        $this->withToken($tokens['refreshToken'])->postJson('/api/v1/auth/refresh')->assertOk();
    }

    public function test_logout_revokes_only_the_current_session(): void
    {
        $user = User::factory()->create();
        $phone = $this->tokensFor($user);
        $tablet = $this->tokensFor($user);

        $this->withToken($phone['token'])->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->withToken($phone['token'])->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withToken($phone['refreshToken'])->postJson('/api/v1/auth/refresh')->assertUnauthorized();
        $this->withToken($tablet['token'])->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_console_login_uses_email_and_password(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'ops@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'OPS@example.com', 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.role', 'ADMIN');

        $this->postJson('/api/v1/auth/login', ['email' => 'ops@example.com', 'password' => 'wrong'])
            ->assertUnauthorized();

        $technician = User::factory()->create(['email' => 'tech@example.com', 'password' => 'password']);
        $this->postJson('/api/v1/auth/login', ['email' => $technician->email, 'password' => 'password'])->assertUnauthorized();
    }
}
