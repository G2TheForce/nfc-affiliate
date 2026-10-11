<?php

namespace Tests\Feature\Referrals;

use App\Enums\AffiliateStatus;
use App\Enums\Role;
use App\Models\AffiliateLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralLinksTest extends TestCase
{
    use ReferralTestHelpers;
    use RefreshDatabase;

    public function test_approved_affiliate_sees_their_main_link_and_creates_tracking_links(): void
    {
        $affiliate = $this->affiliate();
        $product = $this->product(['slug' => 'review-stand']);

        $this->actingAs($affiliate->user)->getJson('/api/v1/affiliate/links')
            ->assertOk()
            ->assertJsonPath('data.main_url', url('/ref/'.$affiliate->referral_code))
            ->assertJsonPath('data.attribution.policy', 'last_click')
            ->assertJsonPath('data.attribution.window_days', 30)
            ->assertJsonPath('data.can_create', true);

        $this->actingAs($affiliate->user)->postJson('/api/v1/affiliate/links', [
            'name' => 'TikTok review video',
            'slug' => 'TikTok-Review',
            'product_id' => $product->public_id,
            'utm_source' => 'tiktok',
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'tiktok-review')
            ->assertJsonPath('data.url', url('/ref/'.$affiliate->referral_code.'/tiktok-review'))
            ->assertJsonPath('data.product.slug', 'review-stand');

        // The new link really works end to end.
        $this->visit('/ref/'.$affiliate->referral_code.'/tiktok-review')->assertRedirect('/shop/review-stand');
    }

    public function test_slugs_are_unique_per_affiliate_and_cannot_change(): void
    {
        $affiliate = $this->affiliate();
        $other = $this->affiliate();
        $payload = ['name' => 'Bio', 'slug' => 'bio'];

        $this->actingAs($affiliate->user)->postJson('/api/v1/affiliate/links', $payload)->assertCreated();
        $this->actingAs($affiliate->user)->postJson('/api/v1/affiliate/links', $payload)->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->actingAs($other->user)->postJson('/api/v1/affiliate/links', $payload)->assertCreated();

        $link = AffiliateLink::where('affiliate_profile_id', $affiliate->id)->sole();
        $this->actingAs($affiliate->user)->patchJson('/api/v1/affiliate/links/'.$link->public_id, ['slug' => 'new'])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->actingAs($affiliate->user)->patchJson('/api/v1/affiliate/links/'.$link->public_id, ['name' => 'Instagram bio', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.name', 'Instagram bio')->assertJsonPath('data.is_active', false);
    }

    public function test_affiliate_cannot_touch_another_affiliates_link(): void
    {
        $owner = $this->affiliate();
        $intruder = $this->affiliate();
        $this->actingAs($owner->user)->postJson('/api/v1/affiliate/links', ['name' => 'Bio', 'slug' => 'bio'])->assertCreated();
        $link = AffiliateLink::sole();

        $this->actingAs($intruder->user)->patchJson('/api/v1/affiliate/links/'.$link->public_id, ['name' => 'Hijacked'])->assertNotFound();
        $this->assertSame('Bio', $link->fresh()->name);
    }

    public function test_suspended_affiliate_can_view_but_not_create_links(): void
    {
        $affiliate = $this->affiliate(AffiliateStatus::Suspended);

        $this->actingAs($affiliate->user)->getJson('/api/v1/affiliate/links')->assertOk()->assertJsonPath('data.can_create', false);
        $this->actingAs($affiliate->user)->postJson('/api/v1/affiliate/links', ['name' => 'Bio', 'slug' => 'bio'])->assertForbidden();
    }

    public function test_applicants_and_customers_have_no_referral_tools(): void
    {
        $pending = $this->affiliate(AffiliateStatus::Pending);
        $pending->user->removeRole(Role::Affiliate->value);
        $customer = $this->userWithRole(Role::Customer);

        foreach ([$pending->user, $customer] as $user) {
            $this->actingAs($user)->getJson('/api/v1/affiliate/links')->assertForbidden();
            $this->actingAs($user)->getJson('/api/v1/affiliate/referrals/summary')->assertForbidden();
        }
    }
}
