<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sita Sharma',
            'email' => 'Sita@Example.com',
            'phone' => '+9779812345678',
            'password' => 'Str0ngPassword!',
            'password_confirmation' => 'Str0ngPassword!',
            'accept_privacy_notice' => true,
            'device_name' => 'phpunit',
        ], $overrides);
    }

    public function test_user_can_register_and_receives_token_customer_role_and_verification_email(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'name', 'email', 'roles']])
            ->assertJsonPath('user.email', 'sita@example.com')
            ->assertJsonPath('user.email_verified', false)
            ->assertJsonPath('user.roles', [Role::Customer->value])
            ->assertJsonMissingPath('user.password');

        $user = User::where('email', 'sita@example.com')->firstOrFail();
        $this->assertNotSame('Str0ngPassword!', $user->password);
        $this->assertTrue(password_verify('Str0ngPassword!', $user->password));
        $this->assertTrue($user->hasRole(Role::Customer->value));

        Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.registered', 'subject_id' => $user->public_id]);
    }

    public function test_registration_validates_input(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'not-an-email',
            'phone' => '12345',
            'password' => 'short',
            'accept_privacy_notice' => false,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'phone', 'password', 'accept_privacy_notice']);
    }

    public function test_duplicate_email_is_rejected_case_insensitively(): void
    {
        User::factory()->create(['email' => 'sita@example.com']);

        $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'SITA@example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_registration_ignores_privileged_fields(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', $this->payload([
            'email_verified_at' => now()->toDateTimeString(),
            'status' => 'disabled',
            'role' => Role::SuperAdmin->value,
            'roles' => [Role::SuperAdmin->value],
            'public_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
        ]))->assertCreated();

        $user = User::where('email', 'sita@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertTrue($user->isActive());
        $this->assertFalse($user->hasRole(Role::SuperAdmin->value));
        $this->assertNotSame('01HZZZZZZZZZZZZZZZZZZZZZZZ', $user->public_id);
    }

    public function test_api_client_without_device_name_and_without_session_gets_clear_error(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', $this->payload(['device_name' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device_name']);

        $this->assertDatabaseMissing('users', ['email' => 'sita@example.com']);
        $this->assertSame(0, AuditLog::where('action', 'auth.registered')->count());
    }
}
