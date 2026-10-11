<?php

namespace Tests\Feature\Affiliate;

use App\Enums\AffiliateStatus;
use App\Enums\Role;
use App\Models\AffiliateProfile;
use App\Models\AffiliateTermsVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class SelfServiceTest extends TestCase
{
    use AffiliateTestHelpers, RefreshDatabase;

    private function approvedMember(): AffiliateProfile
    {
        $profile = AffiliateProfile::factory()->status(AffiliateStatus::Approved)->create();
        $profile->user->assignRole(Role::Affiliate->value);

        return $profile;
    }

    public function test_member_can_update_non_sensitive_fields_only(): void
    {
        $profile = $this->approvedMember();
        $code = $profile->referral_code;

        $this->actingAs($profile->user)->patchJson('/api/v1/affiliate/profile', [
            'display_name' => 'New Name',
            'promotion_channels' => ['tiktok'],
            'referral_code' => 'CHANGED1',
            'status' => 'pending',
        ])->assertOk()
            ->assertJsonPath('data.display_name', 'New Name')
            ->assertJsonPath('data.promotion_channels', ['tiktok'])
            ->assertJsonPath('data.referral_code', $code)
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('audit_logs', ['action' => 'affiliate.profile_updated', 'subject_id' => $profile->public_id]);
    }

    public function test_member_can_close_membership_with_password(): void
    {
        $profile = $this->approvedMember();

        $this->actingAs($profile->user)->postJson('/api/v1/affiliate/profile/close', ['password' => 'wrong-password'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);

        $this->actingAs($profile->user)->postJson('/api/v1/affiliate/profile/close', [
            'password' => 'Password1234', 'reason' => 'Taking a break.',
        ])->assertOk()->assertJsonPath('data.status', 'deactivated');

        $this->assertFalse($profile->user->fresh()->hasRole(Role::Affiliate->value));
        $this->assertDatabaseHas('audit_logs', ['action' => 'affiliate.closed_by_member']);
    }

    public function test_suspended_member_cannot_close_membership_to_escape_review(): void
    {
        $profile = AffiliateProfile::factory()->status(AffiliateStatus::Suspended)->create();

        $this->actingAs($profile->user)->postJson('/api/v1/affiliate/profile/close', ['password' => 'Password1234'])
            ->assertUnprocessable();
        $this->assertSame(AffiliateStatus::Suspended, $profile->fresh()->status);
    }

    public function test_member_must_accept_new_terms_and_acceptance_is_recorded_once(): void
    {
        $this->publishTerms('2026-10');
        $profile = $this->approvedMember();
        $profile->termsAcceptances()->create(['terms_version_id' => AffiliateTermsVersion::first()->id, 'accepted_at' => now()]);

        $this->travel(1)->minutes();
        $this->publishTerms('2027-01');

        $this->actingAs($profile->user)->getJson('/api/v1/affiliate/profile')
            ->assertJsonPath('data.needs_terms_acceptance', true)
            ->assertJsonPath('data.current_terms_version', '2027-01');

        $this->actingAs($profile->user)->postJson('/api/v1/affiliate/terms/accept', ['terms_version' => '2026-10', 'accept_terms' => true])
            ->assertUnprocessable();

        $this->actingAs($profile->user)->postJson('/api/v1/affiliate/terms/accept', ['terms_version' => '2027-01', 'accept_terms' => true])
            ->assertOk()->assertJsonPath('data.needs_terms_acceptance', false);
        $this->actingAs($profile->user)->postJson('/api/v1/affiliate/terms/accept', ['terms_version' => '2027-01', 'accept_terms' => true])
            ->assertOk();

        $this->assertSame(2, $profile->termsAcceptances()->count());
    }

    public function test_actions_without_a_profile_return_404(): void
    {
        $user = $this->userWithRole(Role::Customer);

        $this->actingAs($user)->patchJson('/api/v1/affiliate/profile', ['display_name' => 'X Y'])->assertNotFound();
        $this->actingAs($user)->postJson('/api/v1/affiliate/profile/close', ['password' => 'Password1234'])->assertNotFound();
    }

    public function test_staff_member_cannot_act_on_their_own_membership(): void
    {
        $profile = AffiliateProfile::factory()->create();
        $profile->user->assignRole(Role::AffiliateManager->value);

        $this->actingAs($profile->user)->postJson("/api/v1/admin/affiliates/{$profile->public_id}/approve")
            ->assertUnprocessable();
        $this->assertSame(AffiliateStatus::Pending, $profile->fresh()->status);
    }

    public function test_published_terms_cannot_be_edited(): void
    {
        $terms = $this->publishTerms();

        $this->expectException(LogicException::class);
        $terms->update(['body' => 'changed']);
    }

    public function test_notifications_are_private_to_their_owner(): void
    {
        $profile = AffiliateProfile::factory()->create();
        $this->actingAs($this->userWithRole(Role::AffiliateManager))
            ->postJson("/api/v1/admin/affiliates/{$profile->public_id}/approve")->assertOk();

        $notification = $profile->user->notifications()->firstOrFail();
        $other = User::factory()->create();

        $this->actingAs($other)->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($other)->postJson("/api/v1/notifications/{$notification->id}/read")->assertNotFound();
        $this->assertNull($notification->fresh()->read_at);

        $this->actingAs($profile->user)->getJson('/api/v1/notifications')
            ->assertOk()->assertJsonPath('meta.unread', 1)->assertJsonPath('data.0.title', 'Your affiliate application is approved');
        $this->actingAs($profile->user)->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();
        $this->assertNotNull($notification->fresh()->read_at);
    }
}
