<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Notifications\Auth\PasswordChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_updates_name_and_phone_but_not_email_or_role(): void
    {
        $user = $this->userWithRole(Role::Customer, ['email' => 'gita@example.com']);

        $this->actingAs($user)->patchJson('/api/v1/auth/me', [
            'name' => 'Gita Sharma', 'phone' => '+9779812345678', 'email' => 'other@example.com', 'status' => 'disabled',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Gita Sharma')
            ->assertJsonPath('data.phone', '+9779812345678')
            ->assertJsonPath('data.email', 'gita@example.com');

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.profile_updated']);
        $this->assertStringNotContainsString('9812345678', json_encode(AuditLog::latest('id')->first()->properties));

        $this->actingAs($user)->patchJson('/api/v1/auth/me', ['phone' => '9812345678'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->actingAs($user)->patchJson('/api/v1/auth/me', ['phone' => null])->assertOk()->assertJsonPath('data.phone', null);
    }

    public function test_password_change_needs_the_current_password_and_signs_out_other_devices(): void
    {
        Notification::fake();
        $user = $this->userWithRole(Role::Customer, ['password' => Hash::make('OldPassword123')]);
        $user->createToken('phone');
        $tablet = $user->createToken('tablet');

        Sanctum::actingAs($user->fresh(), ['*']);
        $this->postJson('/api/v1/auth/password', ['current_password' => 'wrong', 'password' => 'NewPassword456', 'password_confirmation' => 'NewPassword456'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->actingAs($user)->postJson('/api/v1/auth/password', [
            'current_password' => 'OldPassword123', 'password' => 'NewPassword456', 'password_confirmation' => 'NewPassword456',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewPassword456', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tablet->accessToken->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_changed']);
        Notification::assertSentTo($user, PasswordChanged::class);
    }

    public function test_weak_or_reused_passwords_are_refused(): void
    {
        $user = $this->userWithRole(Role::Customer, ['password' => Hash::make('OldPassword123')]);

        $this->actingAs($user)->postJson('/api/v1/auth/password', ['current_password' => 'OldPassword123', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->actingAs($user)->postJson('/api/v1/auth/password', ['current_password' => 'OldPassword123', 'password' => 'OldPassword123', 'password_confirmation' => 'OldPassword123'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        auth()->forgetGuards();
        $this->postJson('/api/v1/auth/password', [])->assertStatus(401);
    }
}
