<?php

namespace Tests\Feature;

use Tests\TestCase;

class ConsoleRouteTest extends TestCase
{
    public function test_root_redirects_to_console(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_client_side_routes_return_the_app_shell(): void
    {
        $this->assertFileExists(public_path('admin/index.html'), 'Run "npm run build" in admin/ first.');

        foreach (['/admin', '/admin/users', '/admin/users/01a16288-dec2-7293-94d4-23ab60a04a9e', '/admin/departments?x=1'] as $path) {
            $response = $this->get($path)->assertOk()->assertHeader('Cache-Control', 'no-cache, public');

            $this->assertSame(realpath(public_path('admin/index.html')), $response->baseResponse->getFile()->getRealPath(), $path);
        }
    }

    public function test_api_routes_are_not_swallowed_by_the_console(): void
    {
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
    }
}
