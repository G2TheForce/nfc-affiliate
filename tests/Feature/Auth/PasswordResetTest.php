<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\Auth\PasswordChanged;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_response_does_not_reveal_whether_account_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk()->json();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com'])->assertOk()->json();

        $this->assertSame($known, $unknown);
        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($n) use ($user) {
            $url = $n->toMail($user)->actionUrl;

            return str_starts_with($url, config('affiliate.frontend_url').'/reset-password?token=');
        });
    }

    public function test_password_can_be_reset_with_valid_token_and_tokens_are_revoked(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $user->createToken('old-device');
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'BrandNewPass123',
            'password_confirmation' => 'BrandNewPass123',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('BrandNewPass123', $user->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_reset', 'subject_id' => $user->public_id]);
        Notification::assertSentTo($user, PasswordChanged::class);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'invalid',
            'email' => $user->email,
            'password' => 'BrandNewPass123',
            'password_confirmation' => 'BrandNewPass123',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);

        $this->assertTrue(Hash::check('Password1234', $user->fresh()->password));
    }

    public function test_reset_token_cannot_be_used_twice(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $payload = [
            'token' => $token, 'email' => $user->email,
            'password' => 'BrandNewPass123', 'password_confirmation' => 'BrandNewPass123',
        ];

        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
        $this->postJson('/api/v1/auth/reset-password', array_merge($payload, [
            'password' => 'AnotherPass1234', 'password_confirmation' => 'AnotherPass1234',
        ]))->assertUnprocessable();
    }
}
