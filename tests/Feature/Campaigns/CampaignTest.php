<?php

namespace Tests\Feature\Campaigns;

use App\Enums\Role;
use App\Models\AffiliateLink;
use App\Models\AffiliateProfile;
use App\Models\Campaign;
use App\Models\Commission;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commissions\CommissionTestHelpers;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use CommissionTestHelpers;
    use RefreshDatabase;

    private function campaign(array $attributes = [], array $products = [], array $affiliates = []): Campaign
    {
        $campaign = new Campaign(array_merge(['name' => 'Dashain offer', 'starts_at' => now()->subDay(), 'ends_at' => now()->addWeek()], $attributes));
        $campaign->save();
        $campaign->products()->sync(array_map(fn (Product $p) => $p->id, $products));
        $campaign->affiliates()->sync(array_map(fn (AffiliateProfile $a) => $a->id, $affiliates));

        return $campaign;
    }

    private function campaignLink(AffiliateProfile $affiliate, Campaign $campaign): AffiliateLink
    {
        $link = new AffiliateLink(['name' => 'Dashain', 'slug' => 'dashain', 'campaign_id' => $campaign->id]);
        $link->affiliate_profile_id = $affiliate->id;
        $link->save();

        return $link;
    }

    private function orderThroughLink(AffiliateProfile $affiliate, Product $product): Order
    {
        $click = $this->clickCookie($this->visit('/ref/'.$affiliate->referral_code.'/dashain'));
        $this->placeOrder($product, $click, ['items' => [['product_id' => $product->public_id, 'quantity' => 1]]])->assertCreated();
        $this->flushHeaders();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->pay($order)->assertCreated();

        return $order;
    }

    public function test_campaign_rate_applies_to_orders_through_a_campaign_link_and_beats_the_affiliate_override(): void
    {
        $affiliate = $this->approvedAffiliate();
        $product = $this->product(['price_minor' => 100000]);
        $campaign = $this->campaign();
        $this->rule(['scope' => 'global', 'rate_bp' => 500]);
        $this->rule(['scope' => 'affiliate', 'affiliate_profile_id' => $affiliate->id, 'rate_bp' => 900]);
        $this->rule(['scope' => 'campaign', 'campaign_id' => $campaign->id, 'rate_bp' => 1500]);
        $this->campaignLink($affiliate, $campaign);

        $order = $this->orderThroughLink($affiliate, $product);

        $this->assertSame($campaign->id, $order->attribution->campaign_id);
        $commission = Commission::sole();
        $this->assertSame('campaign', $commission->rule_scope);
        $this->assertSame(15000, $commission->amount_minor);
        $this->assertSame($campaign->public_id, $commission->snapshot['attribution']['campaign']);

        // Without the campaign the same affiliate earns the override.
        $this->attributedOrder($affiliate, $product, 1);
        $this->pay(Order::query()->latest('id')->firstOrFail())->assertCreated();
        $this->assertSame(['campaign', 'affiliate'], Commission::orderBy('id')->pluck('rule_scope')->all());
    }

    public function test_campaign_rate_covers_only_its_products_and_exclusions_still_win(): void
    {
        $affiliate = $this->approvedAffiliate();
        $covered = $this->product(['price_minor' => 100000]);
        $other = $this->product(['price_minor' => 100000]);
        $excluded = $this->product(['price_minor' => 100000]);
        $campaign = $this->campaign([], [$covered, $excluded]);
        $this->rule(['scope' => 'global', 'rate_bp' => 500]);
        $this->rule(['scope' => 'campaign', 'campaign_id' => $campaign->id, 'rate_bp' => 2000]);
        $this->rule(['scope' => 'product', 'product_id' => $excluded->id, 'kind' => 'exclude']);
        $this->campaignLink($affiliate, $campaign);

        $click = $this->clickCookie($this->visit('/ref/'.$affiliate->referral_code.'/dashain'));
        $this->placeOrder($covered, $click, ['items' => [
            ['product_id' => $covered->public_id, 'quantity' => 1],
            ['product_id' => $other->public_id, 'quantity' => 1],
            ['product_id' => $excluded->public_id, 'quantity' => 1],
        ]])->assertCreated();
        $this->flushHeaders();
        $this->pay(Order::sole())->assertCreated();

        $this->assertSame([20000, 5000], Commission::orderBy('id')->pluck('amount_minor')->all());
        $this->assertSame(['campaign', 'global'], Commission::orderBy('id')->pluck('rule_scope')->all());
    }

    public function test_campaign_does_not_apply_to_affiliates_outside_it_or_after_it_ends(): void
    {
        $member = $this->approvedAffiliate();
        $outsider = $this->approvedAffiliate();
        $product = $this->product(['price_minor' => 100000]);
        $campaign = $this->campaign(['all_affiliates' => false], [], [$member]);
        $this->rule(['scope' => 'global', 'rate_bp' => 500]);
        $this->rule(['scope' => 'campaign', 'campaign_id' => $campaign->id, 'rate_bp' => 2000]);

        $this->campaignLink($outsider, $campaign);
        $order = $this->orderThroughLink($outsider, $product);
        $this->assertNull($order->attribution->campaign_id);
        $this->assertSame('global', Commission::sole()->rule_scope);

        $coupon = Coupon::factory()->create(['code' => 'DASHAIN', 'affiliate_profile_id' => $member->id, 'campaign_id' => $campaign->id]);
        $this->travel(8)->days();
        $this->placeOrder($product, null, ['coupon_code' => 'dashain', 'items' => [['product_id' => $product->public_id, 'quantity' => 1]]])->assertCreated();
        $late = Order::query()->latest('id')->firstOrFail();
        $this->assertSame($coupon->id, $late->attribution->coupon_id);
        $this->assertNull($late->attribution->campaign_id);
    }

    public function test_campaign_coupon_brings_the_campaign_rate(): void
    {
        $affiliate = $this->approvedAffiliate();
        $product = $this->product(['price_minor' => 100000]);
        $campaign = $this->campaign();
        $this->rule(['scope' => 'campaign', 'campaign_id' => $campaign->id, 'kind' => 'fixed', 'fixed_minor' => 12345, 'rate_bp' => null]);
        Coupon::factory()->create(['code' => 'TIHAR', 'affiliate_profile_id' => $affiliate->id, 'campaign_id' => $campaign->id]);

        $this->placeOrder($product, null, ['coupon_code' => 'tihar', 'items' => [['product_id' => $product->public_id, 'quantity' => 1]]])->assertCreated();
        $this->flushHeaders();
        $this->pay(Order::sole())->assertCreated();

        $this->assertSame(12345, Commission::sole()->amount_minor);
    }

    public function test_staff_create_a_campaign_with_products_and_affiliates_and_see_its_results(): void
    {
        $manager = $this->userWithRole(Role::AffiliateManager);
        $affiliate = $this->approvedAffiliate();
        $product = $this->product(['price_minor' => 100000]);

        $id = $this->actingAs($manager)->postJson('/api/v1/admin/campaigns', [
            'name' => 'Teej week',
            'starts_at' => now()->subHour()->toIso8601String(),
            'ends_at' => now()->addDays(7)->toIso8601String(),
            'budget_minor' => 5000000,
            'all_affiliates' => false,
            'affiliate_ids' => [$affiliate->public_id],
            'product_ids' => [$product->public_id],
        ])->assertCreated()
            ->assertJsonPath('data.state', 'running')
            ->assertJsonPath('data.affiliates.0.id', $affiliate->public_id)
            ->json('data.id');

        $campaign = Campaign::where('public_id', $id)->sole();
        $this->rule(['scope' => 'campaign', 'campaign_id' => $campaign->id, 'rate_bp' => 1000]);
        $this->campaignLink($affiliate, $campaign);
        $this->orderThroughLink($affiliate, $product);

        $this->actingAs($manager)->getJson("/api/v1/admin/campaigns/{$id}")
            ->assertOk()
            ->assertJsonPath('data.stats.orders', 1)
            ->assertJsonPath('data.stats.sales_minor', 100000)
            ->assertJsonPath('data.stats.commission_minor', 10000)
            ->assertJsonPath('data.rule.rate_bp', 1000);

        $this->actingAs($manager)->patchJson("/api/v1/admin/campaigns/{$id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.state', 'ended');
        $this->assertDatabaseHas('audit_logs', ['action' => 'campaign.updated']);
    }

    public function test_campaign_validation_and_permissions(): void
    {
        $manager = $this->userWithRole(Role::AffiliateManager);
        $this->actingAs($manager)->postJson('/api/v1/admin/campaigns', [
            'name' => 'Broken', 'starts_at' => now()->toIso8601String(), 'ends_at' => now()->subDay()->toIso8601String(), 'all_affiliates' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors(['ends_at', 'affiliate_ids']);

        $this->actingAs($this->userWithRole(Role::Support))->postJson('/api/v1/admin/campaigns', ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($this->approvedAffiliate()->user)->getJson('/api/v1/admin/campaigns')->assertForbidden();
    }

    public function test_affiliate_sees_open_campaigns_and_can_link_only_to_those(): void
    {
        $affiliate = $this->approvedAffiliate();
        $product = $this->product();
        $open = $this->campaign(['name' => 'Open to all'], [$product]);
        $private = $this->campaign(['name' => 'Invite only', 'all_affiliates' => false], [], [$this->approvedAffiliate()]);
        $this->campaign(['name' => 'Next month', 'starts_at' => now()->addMonth(), 'ends_at' => null]);
        $this->rule(['scope' => 'campaign', 'campaign_id' => $open->id, 'rate_bp' => 1500]);
        Coupon::factory()->create(['code' => 'MINE', 'affiliate_profile_id' => $affiliate->id, 'campaign_id' => $open->id]);

        $this->actingAs($affiliate->user)->getJson('/api/v1/affiliate/campaigns')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Open to all')
            ->assertJsonPath('data.0.commission.rate_bp', 1500)
            ->assertJsonPath('data.0.coupons.0.code', 'MINE')
            ->assertJsonPath('data.0.products.0.id', $product->public_id);

        $this->actingAs($affiliate->user)->postJson('/api/v1/affiliate/links', ['name' => 'Secret', 'slug' => 'secret', 'campaign_id' => $private->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('campaign_id');
        $this->actingAs($affiliate->user)->postJson('/api/v1/affiliate/links', ['name' => 'Open', 'slug' => 'open', 'campaign_id' => $open->public_id])
            ->assertCreated()->assertJsonPath('data.campaign.name', 'Open to all');
    }

    public function test_campaign_rule_target_is_shown_and_accepted_by_the_rules_api(): void
    {
        $campaign = $this->campaign();
        $admin = $this->userWithRole(Role::SuperAdmin);

        $this->actingAs($admin)->postJson('/api/v1/admin/commission-rules', [
            'name' => 'Dashain 15%', 'scope' => 'campaign', 'target_id' => $campaign->public_id,
            'kind' => 'percent', 'rate_bp' => 1500, 'starts_at' => now()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.target.name', 'Dashain offer');

        $this->actingAs($admin)->postJson('/api/v1/admin/commission-rules', [
            'name' => 'Dashain exclude', 'scope' => 'campaign', 'target_id' => $campaign->public_id,
            'kind' => 'exclude', 'starts_at' => now()->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('kind');
    }
}
