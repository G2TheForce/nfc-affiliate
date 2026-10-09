<?php

namespace Tests\Feature\Security;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_sent(): void
    {
        $this->getJson('/up')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_server_errors_do_not_leak_details_when_debug_is_off(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/v1/_boom', fn () => throw new RuntimeException('SQLSTATE secret db_password=hunter2'));

        $response = $this->get('/api/v1/_boom')->assertStatus(500);

        $this->assertSame(['message' => 'Server Error'], $response->json());
        $this->assertStringNotContainsString('hunter2', $response->getContent());
    }

    public function test_api_404_is_json(): void
    {
        $this->get('/api/v1/does-not-exist')->assertNotFound()->assertHeader('Content-Type', 'application/json');
    }

    public function test_user_resource_never_exposes_internal_or_secret_fields(): void
    {
        $user = User::factory()->create();
        $data = (new UserResource($user))->resolve();

        $this->assertSame($user->public_id, $data['id']);
        foreach (['password', 'remember_token'] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }
        $this->assertNotSame($user->id, $data['id']);
        $this->assertArrayNotHasKey('id', $user->toArray());
    }

    public function test_spa_shell_is_served_for_frontend_routes(): void
    {
        $this->withoutVite();

        $this->get('/login')->assertOk()->assertSee('<div id="root"></div>', false);
        $this->get('/affiliate/dashboard')->assertOk();
    }
}
