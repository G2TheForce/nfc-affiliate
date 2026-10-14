<?php

namespace Tests\Feature\Affiliate;

use App\Enums\AffiliateStatus;
use App\Enums\Role;
use App\Models\AffiliateProfile;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Staff control over an affiliate's sign-in from the affiliate detail page. */
class AccountAccessTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $user = []): AffiliateProfile
    {
        $member = User::factory()->withRole(Role::Affiliate)->create($user);

        return AffiliateProfile::factory()->forUser($member)->status(AffiliateStatus::Approved)->create();
    }

    private function signIn(User $user): void
    {
        $user->createToken('phone');
        DB::table('sessions')->insert(['id' => 'sess-'.$user->id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    }

    public function test_detail_shows_sign_in_summary(): void
    {
        $profile = $this->member(['last_login_at' => now()->subDay()]);
        $this->signIn($profile->user);

        $this->actingAs($this->userWithRole(Role::SuperAdmin))->getJson("/api/v1/admin/affiliates/{$profile->public_id}")
            ->assertOk()
            ->assertJsonPath('data.account.status', 'active')
            ->assertJsonPath('data.account.email_verified', true)
            ->assertJsonPath('data.account.signed_in_devices', 2)
            ->assertJsonPath('data.account.is_staff', false);
    }

    public function test_blocking_sign_in_signs_out_everywhere_and_refuses_login_until_allowed(): void
    {
        $admin = $this->userWithRole(Role::SuperAdmin);
        $profile = $this->member(['email' => 'member@example.com', 'password' => 'MemberPass123']);
        $this->signIn($profile->user);

        $this->actingAs($admin)->postJson("/api/v1/admin/affiliates/{$profile->public_id}/account/status", ['status' => 'disabled', 'reason' => 'Account reported as hacked'])
            ->assertOk()
            ->assertJsonPath('data.account.status', 'disabled')
            ->assertJsonPath('data.account.signed_in_devices', 0)
            ->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.status_changed', 'actor_id' => $admin->id]);

        auth()->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'member@example.com', 'password' => 'MemberPass123', 'device_name' => 't'])->assertUnprocessable()->assertJsonPath('errors.email.0', 'This account is disabled. Please contact support.');

        $this->actingAs($admin)->postJson("/api/v1/admin/affiliates/{$profile->public_id}/account/status", ['status' => 'active', 'reason' => 'Member confirmed identity'])
            ->assertOk()->assertJsonPath('data.account.status', 'active');
        auth()->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'member@example.com', 'password' => 'MemberPass123', 'device_name' => 't'])->assertOk();
    }

    public function test_password_reset_link_is_emailed_and_audited(): void
    {
        Notification::fake();
        $admin = $this->userWithRole(Role::AffiliateManager);
        $profile = $this->member();

        $this->actingAs($admin)->postJson("/api/v1/admin/affiliates/{$profile->public_id}/account/password-reset")->assertOk();

        Notification::assertSentTo($profile->user, ResetPasswordNotification::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.affiliate_password_reset_sent', 'actor_id' => $admin->id]);

        $this->actingAs($admin)->postJson("/api/v1/admin/affiliates/{$profile->public_id}/account/password-reset")
            ->assertUnprocessable()->assertJsonValidationErrors('account');
    }

    public function test_sign_out_everywhere_removes_tokens_and_sessions(): void
    {
        $profile = $this->member();
        $this->signIn($profile->user);

        $this->actingAs($this->userWithRole(Role::SuperAdmin))->postJson("/api/v1/admin/affiliates/{$profile->public_id}/account/sign-out")
            ->assertOk()->assertJsonPath('data.account.signed_in_devices', 0);
        $this->assertSame(0, $profile->user->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.affiliate_signed_out']);
    }

    public function test_verification_email_is_resent_only_while_unverified(): void
    {
        Notification::fake();
        $admin = $this->userWithRole(Role::SuperAdmin);
        $profile = $this->member(['email_verified_at' => null]);

        $this->actingAs($admin)->postJson("/api/v1/admin/affiliates/{$profile->public_id}/account/verification")->assertOk();
        Notification::assertSentTo($profile->user, VerifyEmailNotification::class);

        $profile->user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($admin)->postJson("/api/v1/admin/affiliates/{$profile->public_id}/account/verification")
            ->assertUnprocessable()->assertJsonValidationErrors('account');
    }

    public function test_blocked_accounts_get_no_reset_link(): void
    {
        Notification::fake();
        $profile = $this->member(['status' => 'disabled']);

        $this->actingAs($this->userWithRole(Role::SuperAdmin))->postJson("/api/v1/admin/affiliates/{$profile->public_id}/account/password-reset")
            ->assertUnprocessable()->assertJsonValidationErrors('account');
        Notification::assertNothingSent();
    }

    public function test_staff_accounts_are_left_to_the_staff_screen(): void
    {
        $profile = $this->member();
        $profile->user->assignRole(Role::FinanceManager->value);

        $this->actingAs($this->userWithRole(Role::AffiliateManager))
            ->postJson("/api/v1/admin/affiliates/{$profile->public_id}/account/status", ['status' => 'disabled', 'reason' => 'Trying to lock out finance'])
            ->assertUnprocessable()->assertJsonValidationErrors('account');
        $this->assertTrue($profile->user->refresh()->isActive());
    }

    public function test_roles_without_affiliates_suspend_cannot_manage_sign_in(): void
    {
        $profile = $this->member();

        foreach ([Role::Support, Role::FinanceManager] as $role) {
            $this->actingAs($this->userWithRole($role))->postJson("/api/v1/admin/affiliates/{$profile->public_id}/account/sign-out")->assertForbidden();
        }
    }
}
