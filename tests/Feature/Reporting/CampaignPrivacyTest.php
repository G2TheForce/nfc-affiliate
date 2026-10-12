<?php

namespace Tests\Feature\Reporting;

use App\Enums\Role;
use App\Models\Coupon;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignPrivacyTest extends TestCase
{
    use RefreshDatabase;
    use ReportingTestHelpers;

    public function test_an_affiliate_never_sees_another_affiliates_coupon_on_an_order_credited_to_them(): void
    {
        config(['affiliate.attribution.coupon_overrides_click' => false]);
        $this->travelTo('2026-09-01 04:00:00');
        $this->rule(['rate_bp' => 1000]);
        $a = $this->approvedAffiliate();
        $b = $this->approvedAffiliate();
        $b->forceFill(['display_name' => 'Bee Secret'])->save();
        $card = $this->product(['name' => 'Metal card', 'price_minor' => 100000]);
        Coupon::factory()->create(['code' => 'BEEPRIVATE', 'affiliate_profile_id' => $b->id, 'discount_value' => 1000]);

        $click = $this->clickCookie($this->visit('/ref/'.$a->referral_code));
        $this->placeOrder($card, $click, ['coupon_code' => 'BEEPRIVATE'])->assertCreated();
        $this->flushHeaders();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertSame($a->id, $order->attribution->affiliate_profile_id);

        $campaigns = $this->actingAs($a->user)->getJson('/api/v1/affiliate/dashboard?from=2026-09-01&to=2026-09-30')->assertOk()->json('data.campaigns');
        $this->assertSame([], $campaigns);
        $this->assertStringNotContainsString('BEEPRIVATE', $this->actingAs($a->user)->getJson('/api/v1/affiliate/dashboard?from=2026-09-01&to=2026-09-30')->getContent());

        // Staff still see which coupon was used.
        $this->assertSame('BEEPRIVATE', $this->actingAs($this->userWithRole(Role::SuperAdmin))->getJson('/api/v1/admin/dashboard?from=2026-09-01&to=2026-09-30')->json('data.campaigns.0.code'));
    }
}
