<?php

namespace Tests\Feature\Referrals;

use App\Enums\Role;
use App\Models\AffiliateLink;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralReportTest extends TestCase
{
    use ReferralTestHelpers;
    use RefreshDatabase;

    public function test_affiliate_summary_counts_clicks_orders_and_cancellations_separately(): void
    {
        $affiliate = $this->affiliate();
        $other = $this->affiliate();
        $product = $this->product(['price_minor' => 100000]);
        $link = new AffiliateLink(['name' => 'Bio', 'slug' => 'bio']);
        $link->affiliate_profile_id = $affiliate->id;
        $link->save();

        $first = $this->clickCookie($this->visit('/ref/'.$affiliate->referral_code));
        $this->visit('/ref/'.$affiliate->referral_code.'/bio');
        $viaLink = $this->clickCookie($this->visit('/ref/'.$affiliate->referral_code.'/bio'));
        $this->visit('/ref/'.$other->referral_code);

        $this->placeOrder($product, $first)->assertCreated();
        $this->placeOrder($product, $viaLink, ['customer_email' => 'b@example.com'])->assertCreated();
        $this->placeOrder($product, $viaLink, ['customer_email' => 'c@example.com'])->assertCreated();
        $admin = $this->userWithRole(Role::SuperAdmin);
        $this->actingAs($admin)->postJson('/api/v1/admin/orders/'.Order::latest('id')->first()->public_id.'/cancel', ['reason' => 'Duplicate order'])->assertOk();

        $response = $this->actingAs($affiliate->user)->getJson('/api/v1/affiliate/referrals/summary')->assertOk();

        $response->assertJsonPath('data.totals.clicks', 3)
            ->assertJsonPath('data.totals.unique_clicks', 3)
            ->assertJsonPath('data.totals.orders', 2)
            ->assertJsonPath('data.totals.cancelled_orders', 1)
            ->assertJsonPath('data.totals.order_value_minor', 400000)
            ->assertJsonPath('data.by_source.click', 2);
        $this->assertCount(30, $response->json('data.daily'));
        $this->assertSame(3, collect($response->json('data.daily'))->sum('clicks'));
        $byLink = collect($response->json('data.by_link'))->keyBy('name');
        $this->assertSame(2, $byLink['Bio']['clicks']);
        $this->assertSame(1, $byLink['Bio']['orders']);
        $this->assertSame(1, $byLink['Main referral link']['orders']);
    }

    public function test_affiliate_order_list_shows_no_customer_details(): void
    {
        $affiliate = $this->affiliate();
        $this->placeOrder($this->product(), null, ['referral_code' => $affiliate->referral_code])->assertCreated();
        $order = Order::sole();

        $response = $this->actingAs($affiliate->user)->getJson('/api/v1/affiliate/referrals/orders')->assertOk();

        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', '…'.substr($order->order_number, -4));
        $body = $response->getContent();
        $this->assertStringNotContainsString('sita@example.com', $body);
        $this->assertStringNotContainsString('Sita', $body);
        $this->assertStringNotContainsString('9811111111', $body);
        $this->assertStringNotContainsString($order->order_number, $body);
    }

    public function test_staff_programme_summary_lists_top_affiliates(): void
    {
        $affiliate = $this->affiliate();
        $this->visit('/ref/'.$affiliate->referral_code);
        $this->placeOrder($this->product(), null, ['referral_code' => $affiliate->referral_code])->assertCreated();

        $this->actingAs($this->userWithRole(Role::FinanceManager))->getJson('/api/v1/admin/referrals/summary')
            ->assertOk()
            ->assertJsonPath('data.totals.clicks', 1)
            ->assertJsonPath('data.top_affiliates.0.affiliate_id', $affiliate->public_id)
            ->assertJsonPath('data.top_affiliates.0.orders', 1);

        $this->actingAs($this->userWithRole(Role::Support))->getJson('/api/v1/admin/referrals/summary')->assertForbidden();
    }

    public function test_report_ranges_are_validated(): void
    {
        $affiliate = $this->affiliate();

        $this->actingAs($affiliate->user)->getJson('/api/v1/affiliate/referrals/summary?from=2026-01-01&to=2025-01-01')->assertUnprocessable();
        $this->actingAs($affiliate->user)->getJson('/api/v1/affiliate/referrals/summary?from=2024-01-01&to=2026-01-01')->assertUnprocessable();
        $this->actingAs($affiliate->user)->getJson('/api/v1/affiliate/referrals/summary?from=2026-10-01&to=2026-10-07')
            ->assertOk()->assertJsonCount(7, 'data.daily');
    }
}
