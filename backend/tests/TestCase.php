<?php

namespace Tests;

use App\Models\User;
use App\Services\AuthTokenService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Issue a real token pair (abilities and sessions matter) and return it. */
    protected function tokensFor(User $user): array
    {
        return app(AuthTokenService::class)->issue($user, 'phpunit');
    }

    public function withToken(string $token, string $type = 'Bearer'): static
    {
        // The auth guard caches the resolved user across requests within one test.
        $this->app['auth']->forgetGuards();

        return parent::withToken($token, $type);
    }

    protected function actingAsUser(User $user): static
    {
        return $this->withToken($this->tokensFor($user)['token']);
    }
}
