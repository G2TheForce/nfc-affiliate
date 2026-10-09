<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_client_logs_in_with_token_and_can_call_me(): void
    {
        $user = User::factory()->create(['email' => 'ram@example.com']);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'RAM@example.com',
            'password' => 'Password1234',
            'device_name' => 'flutter-test',
        ])->assertOk()->json('token');

        $this->assertNotEmpty($token);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor_id' => $user->id]);

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->public_id)
            ->assertJsonMissingPath('data.password');
    }

    public function test_spa_logs_in_with_session_cookie(): void
    {
        $user = User::factory()->create();

        $this->withHeader('Referer', 'http://localhost')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password1234'])
            ->assertOk()
            ->assertJsonMissingPath('token')
            ->assertJsonPath('user.id', $user->public_id);

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_wrong_password_fails_with_generic_message_and_is_audited(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'WrongPassword1', 'device_name' => 'x',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);

        $log = AuditLog::where('action', 'auth.login_failed')->firstOrFail();
        $this->assertSame($user->public_id, $log->subject_id);
        $this->assertStringNotContainsString('WrongPassword1', json_encode($log->toArray()));
    }

    public function test_unknown_email_gets_same_error_as_wrong_password(): void
    {
        $user = User::factory()->create();

        $unknown = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com', 'password' => 'Password1234', 'device_name' => 'x',
        ])->assertUnprocessable()->json('errors.email');

        $wrong = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Nope12345678', 'device_name' => 'x',
        ])->assertUnprocessable()->json('errors.email');

        $this->assertSame($unknown, $wrong);
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email, 'password' => 'WrongPassword1', 'device_name' => 'x',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Password1234', 'device_name' => 'x',
        ])->assertTooManyRequests();
    }

    public function test_disabled_account_cannot_log_in(): void
    {
        $user = User::factory()->disabled()->create();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Password1234', 'device_name' => 'x',
        ])->assertUnprocessable();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_disabled_account_with_existing_token_is_blocked(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('old')->plainTextToken;
        $user->forceFill(['status' => 'disabled'])->save();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('device')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_guest_gets_json_401(): void
    {
        $this->get('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    }
}
