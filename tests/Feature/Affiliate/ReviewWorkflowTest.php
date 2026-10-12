<?php

namespace Tests\Feature\Affiliate;

use App\Enums\AffiliateStatus;
use App\Enums\Role;
use App\Models\AffiliateProfile;
use App\Models\AffiliateStatusHistory;
use App\Notifications\Affiliate\AffiliateStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Tests\TestCase;

class ReviewWorkflowTest extends TestCase
{
    use AffiliateTestHelpers, RefreshDatabase;

    private function pendingProfile(): AffiliateProfile
    {
        return AffiliateProfile::factory()->create();
    }

    public function test_approval_message_reaches_the_member_and_history(): void
    {
        Notification::fake();
        $manager = $this->userWithRole(Role::AffiliateManager);
        $profile = $this->pendingProfile();

        $response = $this->actingAs($manager)->postJson("/api/v1/admin/affiliates/{$profile->public_id}/approve", [
            'reason' => 'Welcome! Start with your Facebook group.',
        ])->assertOk();

        $this->assertSame('Welcome! Start with your Facebook group.', $response->json('data.history.0.reason'));
        Notification::assertSentTo($profile->user, AffiliateStatusChanged::class, function ($n) use ($profile) {
            return str_contains((string) $n->toMail($profile->user)->render(), 'Message from the team: Welcome! Start with your Facebook group.');
        });
    }

    public function test_manager_approves_application_which_assigns_code_slug_and_role(): void
    {
        Notification::fake();
        $manager = $this->userWithRole(Role::AffiliateManager);
        $profile = $this->pendingProfile();

        $response = $this->actingAs($manager)->postJson("/api/v1/admin/affiliates/{$profile->public_id}/approve", [
            'internal_note' => 'Checked their Facebook page.',
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        $profile->refresh();
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $profile->referral_code);
        $this->assertNotEmpty($profile->referral_slug);
        $this->assertSame($manager->id, $profile->approved_by);
        $this->assertTrue($profile->user->hasRole(Role::Affiliate->value));
        $this->assertSame('Checked their Facebook page.', $response->json('data.history.0.internal_note'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'affiliate.approved', 'subject_id' => $profile->public_id, 'actor_id' => $manager->id]);

        Notification::assertSentTo($profile->user, AffiliateStatusChanged::class, function ($n) use ($profile) {
            $mail = $n->toMail($profile->user)->render();

            return $n->to === AffiliateStatus::Approved
                && str_contains((string) $mail, $profile->referral_code)
                && ! str_contains((string) $mail, 'Checked their Facebook page');
        });
    }

    public function test_member_never_sees_internal_notes(): void
    {
        $manager = $this->userWithRole(Role::AffiliateManager);
        $profile = $this->pendingProfile();

        $this->actingAs($manager)->postJson("/api/v1/admin/affiliates/{$profile->public_id}/reject", [
            'reason' => 'We need a public website or social profile.',
            'internal_note' => 'SECRET-NOTE suspected duplicate of another account',
        ])->assertOk();
        $this->actingAs($manager)->postJson("/api/v1/admin/affiliates/{$profile->public_id}/notes", ['body' => 'SECRET-NOTE two'])->assertOk();

        $member = $this->actingAs($profile->user)->getJson('/api/v1/affiliate/profile')->assertOk();
        $this->assertStringNotContainsString('SECRET-NOTE', $member->getContent());
        $this->assertStringNotContainsString($manager->name, $member->getContent());
        $member->assertJsonPath('data.history.0.reason', 'We need a public website or social profile.');

        $notifications = $this->actingAs($profile->user)->getJson('/api/v1/notifications')->assertOk();
        $this->assertStringNotContainsString('SECRET-NOTE', $notifications->getContent());
    }

    public function test_reject_requires_a_reason(): void
    {
        $profile = $this->pendingProfile();

        $this->actingAs($this->userWithRole(Role::AffiliateManager))
            ->postJson("/api/v1/admin/affiliates/{$profile->public_id}/reject", [])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason']);

        $this->assertSame(AffiliateStatus::Pending, $profile->fresh()->status);
    }

    public function test_full_lifecycle_suspend_reinstate_deactivate_reactivate(): void
    {
        $admin = $this->userWithRole(Role::SuperAdmin);
        $profile = $this->pendingProfile();
        $base = "/api/v1/admin/affiliates/{$profile->public_id}";

        $this->actingAs($admin)->postJson("$base/approve")->assertOk();
        $code = $profile->fresh()->referral_code;

        $this->actingAs($admin)->postJson("$base/suspend", ['reason' => 'Spam posts reported.'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertTrue($profile->user->fresh()->hasRole(Role::Affiliate->value), 'suspended members keep portal access');

        $this->actingAs($admin)->postJson("$base/reinstate", ['reason' => 'Posts removed.'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->actingAs($admin)->postJson("$base/deactivate", ['reason' => 'Requested by member.'])->assertOk()->assertJsonPath('data.status', 'deactivated');
        $this->assertFalse($profile->user->fresh()->hasRole(Role::Affiliate->value));

        $this->actingAs($admin)->postJson("$base/reactivate", ['reason' => 'Member returned.'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertTrue($profile->user->fresh()->hasRole(Role::Affiliate->value));

        $this->assertSame($code, $profile->fresh()->referral_code, 'referral code is permanent');
        $this->assertSame(5, $profile->statusHistory()->count());
    }

    public function test_invalid_transitions_are_refused(): void
    {
        $admin = $this->userWithRole(Role::SuperAdmin);
        $pending = $this->pendingProfile();
        $approved = AffiliateProfile::factory()->status(AffiliateStatus::Approved)->create();

        $this->actingAs($admin)->postJson("/api/v1/admin/affiliates/{$pending->public_id}/suspend", ['reason' => 'Not possible yet.'])->assertUnprocessable();
        $this->actingAs($admin)->postJson("/api/v1/admin/affiliates/{$pending->public_id}/reactivate", ['reason' => 'Not possible yet.'])->assertUnprocessable();
        $this->actingAs($admin)->postJson("/api/v1/admin/affiliates/{$approved->public_id}/approve")->assertUnprocessable();
        $this->actingAs($admin)->postJson("/api/v1/admin/affiliates/{$approved->public_id}/reject", ['reason' => 'Too late now.'])->assertUnprocessable();

        $this->assertSame(AffiliateStatus::Pending, $pending->fresh()->status);
        $this->assertSame(AffiliateStatus::Approved, $approved->fresh()->status);
    }

    public function test_approving_twice_creates_one_transition(): void
    {
        $manager = $this->userWithRole(Role::AffiliateManager);
        $profile = $this->pendingProfile();

        $this->actingAs($manager)->postJson("/api/v1/admin/affiliates/{$profile->public_id}/approve")->assertOk();
        $this->actingAs($manager)->postJson("/api/v1/admin/affiliates/{$profile->public_id}/approve")->assertUnprocessable();

        $this->assertSame(1, $profile->statusHistory()->where('to_status', 'approved')->count());
    }

    public function test_status_history_is_immutable(): void
    {
        $manager = $this->userWithRole(Role::AffiliateManager);
        $profile = $this->pendingProfile();
        $this->actingAs($manager)->postJson("/api/v1/admin/affiliates/{$profile->public_id}/approve")->assertOk();

        $this->expectException(LogicException::class);
        AffiliateStatusHistory::firstOrFail()->update(['to_status' => 'rejected']);
    }

    public function test_list_filters_and_shows_pending_first(): void
    {
        AffiliateProfile::factory()->status(AffiliateStatus::Approved)->create(['applied_at' => now()->subDays(10)]);
        $pending = AffiliateProfile::factory()->create(['display_name' => 'Pokhara Prints', 'applied_at' => now()->subDay()]);

        $this->actingAs($this->userWithRole(Role::Support))->getJson('/api/v1/admin/affiliates')
            ->assertOk()->assertJsonPath('data.0.id', $pending->public_id)->assertJsonPath('meta.total', 2);

        $this->actingAs($this->userWithRole(Role::Support))->getJson('/api/v1/admin/affiliates?status=approved')
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->actingAs($this->userWithRole(Role::Support))->getJson('/api/v1/admin/affiliates?search=Pokhara')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.display_name', 'Pokhara Prints');

        $this->actingAs($this->userWithRole(Role::Support))->getJson('/api/v1/admin/affiliates/counts')
            ->assertOk()->assertJsonPath('data.pending', 1)->assertJsonPath('data.approved', 1);
    }

    public function test_detail_flags_possible_duplicates(): void
    {
        $first = AffiliateProfile::factory()->create(['website_urls' => ['https://www.shared-site.com/a']]);
        $second = AffiliateProfile::factory()->create(['website_urls' => ['http://shared-site.com/b']]);
        $second->user->forceFill(['phone' => $first->user->phone])->save();
        AffiliateProfile::factory()->create(['website_urls' => ['https://unrelated.com']]);

        $this->actingAs($this->userWithRole(Role::AffiliateManager))
            ->getJson("/api/v1/admin/affiliates/{$first->public_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.possible_duplicates')
            ->assertJsonPath('data.possible_duplicates.0.id', $second->public_id)
            ->assertJsonPath('data.possible_duplicates.0.matched_on', ['phone', 'website']);
    }

    public function test_unknown_profile_is_404(): void
    {
        $this->actingAs($this->userWithRole(Role::SuperAdmin))->getJson('/api/v1/admin/affiliates/01HZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
    }
}
