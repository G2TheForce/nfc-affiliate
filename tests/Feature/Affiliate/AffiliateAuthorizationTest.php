<?php

namespace Tests\Feature\Affiliate;

use App\Enums\AffiliateStatus;
use App\Enums\Role;
use App\Models\AffiliateProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AffiliateAuthorizationTest extends TestCase
{
    use AffiliateTestHelpers, RefreshDatabase;

    /**
     * role => [view, approve, suspend, publish terms]
     */
    public static function matrix(): array
    {
        return [
            'super admin' => [Role::SuperAdmin, 200, 200, 200, 201],
            'affiliate manager' => [Role::AffiliateManager, 200, 200, 200, 403],
            'finance manager' => [Role::FinanceManager, 200, 403, 403, 403],
            'support' => [Role::Support, 200, 403, 403, 403],
            'affiliate' => [Role::Affiliate, 403, 403, 403, 403],
            'customer' => [Role::Customer, 403, 403, 403, 403],
        ];
    }

    #[DataProvider('matrix')]
    public function test_staff_affiliate_endpoints_enforce_permissions(Role $role, int $view, int $approve, int $suspend, int $publish): void
    {
        $user = $this->userWithRole($role);
        $pending = AffiliateProfile::factory()->create();
        $approved = AffiliateProfile::factory()->status(AffiliateStatus::Approved)->create();

        $this->actingAs($user)->getJson("/api/v1/admin/affiliates/{$pending->public_id}")->assertStatus($view);
        $this->actingAs($user)->postJson("/api/v1/admin/affiliates/{$pending->public_id}/approve")->assertStatus($approve);
        $this->actingAs($user)->postJson("/api/v1/admin/affiliates/{$approved->public_id}/suspend", ['reason' => 'Policy breach found.'])->assertStatus($suspend);
        $this->actingAs($user)->postJson('/api/v1/admin/terms', [
            'version' => 'v-'.$role->value, 'title' => 'Terms', 'body' => str_repeat('Clause text. ', 10),
        ])->assertStatus($publish);

        if ($approve !== 200) {
            $this->assertSame(AffiliateStatus::Pending, $pending->fresh()->status);
        }
    }

    public function test_affiliate_cannot_approve_their_own_application_via_admin_api(): void
    {
        $profile = AffiliateProfile::factory()->create();
        $profile->user->assignRole(Role::Affiliate->value);

        $this->actingAs($profile->user)->postJson("/api/v1/admin/affiliates/{$profile->public_id}/approve")->assertForbidden();
        $this->assertSame(AffiliateStatus::Pending, $profile->fresh()->status);
    }

    public function test_affiliate_endpoints_never_accept_another_members_id(): void
    {
        $mine = AffiliateProfile::factory()->status(AffiliateStatus::Approved)->create();
        $theirs = AffiliateProfile::factory()->status(AffiliateStatus::Approved)->create(['display_name' => 'Other Person']);

        $this->actingAs($mine->user)->getJson("/api/v1/affiliate/profile?id={$theirs->public_id}")
            ->assertOk()->assertJsonPath('data.id', $mine->public_id);
        $this->actingAs($mine->user)->patchJson('/api/v1/affiliate/profile', ['id' => $theirs->public_id, 'display_name' => 'Hijacked'])
            ->assertOk();

        $this->assertSame('Other Person', $theirs->fresh()->display_name);
    }
}
