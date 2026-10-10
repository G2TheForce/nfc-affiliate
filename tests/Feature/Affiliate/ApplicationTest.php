<?php

namespace Tests\Feature\Affiliate;

use App\Enums\AffiliateStatus;
use App\Enums\Role;
use App\Models\AffiliateProfile;
use App\Models\User;
use App\Notifications\Affiliate\ApplicationReceived;
use App\Notifications\Affiliate\NewApplicationForReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ApplicationTest extends TestCase
{
    use AffiliateTestHelpers, RefreshDatabase;

    public function test_verified_customer_can_apply_and_reviewers_are_notified(): void
    {
        Notification::fake();
        $this->publishTerms();
        $customer = $this->userWithRole(Role::Customer);
        $reviewer = $this->userWithRole(Role::AffiliateManager);
        $finance = $this->userWithRole(Role::FinanceManager);

        $this->actingAs($customer)->postJson('/api/v1/affiliate/applications', $this->applicationPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.country_code', 'NP')
            ->assertJsonPath('data.referral_code', null)
            ->assertJsonPath('data.terms_accepted.0.version', '2026-10')
            ->assertJsonPath('data.needs_terms_acceptance', false);

        $profile = $customer->affiliateProfile()->firstOrFail();
        $this->assertSame(AffiliateStatus::Pending, $profile->status);
        $this->assertSame(1, $profile->statusHistory()->count());
        $this->assertFalse($customer->fresh()->hasRole(Role::Affiliate->value));
        $this->assertDatabaseHas('audit_logs', ['action' => 'affiliate.applied', 'subject_id' => $profile->public_id]);

        Notification::assertSentTo($customer, ApplicationReceived::class);
        Notification::assertSentTo($reviewer, NewApplicationForReview::class);
        Notification::assertNotSentTo($finance, NewApplicationForReview::class);
    }

    public function test_application_requires_published_terms(): void
    {
        $this->actingAs($this->userWithRole(Role::Customer))
            ->postJson('/api/v1/affiliate/applications', $this->applicationPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['terms_version']);
    }

    public function test_application_must_accept_the_current_terms_version(): void
    {
        $this->publishTerms('2026-10');
        $this->travel(1)->minutes();
        $this->publishTerms('2026-11');

        $this->actingAs($this->userWithRole(Role::Customer))
            ->postJson('/api/v1/affiliate/applications', $this->applicationPayload(['terms_version' => '2026-10']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['terms_version']);
    }

    public function test_application_validates_fields(): void
    {
        $this->publishTerms();

        $this->actingAs($this->userWithRole(Role::Customer))
            ->postJson('/api/v1/affiliate/applications', $this->applicationPayload([
                'display_name' => '',
                'promotion_channels' => ['carrier-pigeon'],
                'website_urls' => ['javascript:alert(1)'],
                'accept_terms' => false,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['display_name', 'promotion_channels.0', 'website_urls.0', 'accept_terms']);
    }

    public function test_unverified_user_cannot_apply(): void
    {
        $this->publishTerms();
        $user = User::factory()->unverified()->withRole(Role::Customer)->create();

        $this->actingAs($user)->postJson('/api/v1/affiliate/applications', $this->applicationPayload())->assertForbidden();
        $this->assertSame(0, AffiliateProfile::count());
    }

    public function test_staff_cannot_apply(): void
    {
        $this->publishTerms();

        $this->actingAs($this->userWithRole(Role::FinanceManager))
            ->postJson('/api/v1/affiliate/applications', $this->applicationPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account']);
    }

    public function test_duplicate_application_is_rejected(): void
    {
        $this->publishTerms();
        $customer = $this->userWithRole(Role::Customer);

        $this->actingAs($customer)->postJson('/api/v1/affiliate/applications', $this->applicationPayload())->assertCreated();
        $this->actingAs($customer)->postJson('/api/v1/affiliate/applications', $this->applicationPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(1, AffiliateProfile::count());
    }

    public function test_applicant_cannot_set_status_or_referral_code(): void
    {
        $this->publishTerms();
        $customer = $this->userWithRole(Role::Customer);

        $this->actingAs($customer)->postJson('/api/v1/affiliate/applications', $this->applicationPayload([
            'status' => 'approved',
            'referral_code' => 'HACKED01',
            'approved_at' => now()->toDateTimeString(),
        ]))->assertCreated()->assertJsonPath('data.status', 'pending');

        $profile = $customer->affiliateProfile;
        $this->assertNull($profile->referral_code);
        $this->assertNull($profile->approved_at);
    }

    public function test_rejected_applicant_can_reapply_only_after_cooldown(): void
    {
        $this->publishTerms();
        $customer = $this->userWithRole(Role::Customer);
        $admin = $this->userWithRole(Role::AffiliateManager);

        $this->actingAs($customer)->postJson('/api/v1/affiliate/applications', $this->applicationPayload())->assertCreated();
        $profile = $customer->affiliateProfile;
        $this->actingAs($admin)->postJson("/api/v1/admin/affiliates/{$profile->public_id}/reject", ['reason' => 'Please add your website first.'])->assertOk();

        $this->actingAs($customer)->getJson('/api/v1/affiliate/profile')
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.history.0.reason', 'Please add your website first.')
            ->assertJsonPath('data.can_reapply_at', fn ($v) => is_string($v));

        $this->actingAs($customer)->postJson('/api/v1/affiliate/applications', $this->applicationPayload())
            ->assertUnprocessable();

        $this->travel(31)->days();

        $this->actingAs($customer)->postJson('/api/v1/affiliate/applications', $this->applicationPayload(['display_name' => 'Better Blog']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.display_name', 'Better Blog');

        $this->assertSame(1, AffiliateProfile::count());
        $this->assertSame(3, $profile->statusHistory()->count());
    }

    public function test_profile_endpoint_returns_null_before_applying(): void
    {
        $this->actingAs($this->userWithRole(Role::Customer))->getJson('/api/v1/affiliate/profile')
            ->assertOk()->assertJsonPath('data', null);
    }
}
