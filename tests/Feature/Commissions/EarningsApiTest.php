<?php

namespace Tests\Feature\Commissions;

use App\Enums\AffiliateStatus;
use App\Enums\Role;
use App\Models\Commission;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EarningsApiTest extends TestCase
{
    use CommissionTestHelpers;
    use RefreshDatabase;
    use RefundDatabaseHelpers;

    public function test_affiliate_sees_own_balances_and_commissions_with_masked_order_numbers(): void
    {
        [$order] = $this->paidOrder(100000, 2);
        $affiliate = Commission::sole()->affiliate;

        $this->actingAs($affiliate->user)->getJson('/api/v1/affiliate/earnings/summary')
            ->assertOk()
            ->assertJsonPath('data.balances.pending', 20000)
            ->assertJsonPath('data.balances.available', 0)
            ->assertJsonPath('data.counts.pending', 1)
            ->assertJsonPath('data.holding_period_days', 14);

        $response = $this->actingAs($affiliate->user)->getJson('/api/v1/affiliate/earnings/commissions')
            ->assertOk()
            ->assertJsonPath('data.0.amount_minor', 20000)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonMissingPath('data.0.hold_reason')
            ->assertJsonMissingPath('data.0.affiliate')
            ->assertJsonMissingPath('data.0.rule');
        $this->assertNotSame($order->order_number, $response->json('data.0.order_number'));
        $this->assertStringEndsWith(substr($order->order_number, -4), $response->json('data.0.order_number'));
        $this->assertStringNotContainsString('sita', $response->getContent());

        $this->actingAs($affiliate->user)->getJson('/api/v1/affiliate/earnings/ledger')
            ->assertOk()->assertJsonPath('data.0.type', 'accrual')->assertJsonPath('data.0.to_bucket', 'pending');
    }

    public function test_affiliate_never_sees_another_affiliates_earnings(): void
    {
        $this->paidOrder(100000, 2);
        $other = $this->approvedAffiliate();

        $this->actingAs($other->user)->getJson('/api/v1/affiliate/earnings/summary')->assertOk()->assertJsonPath('data.balances.pending', 0);
        $this->actingAs($other->user)->getJson('/api/v1/affiliate/earnings/commissions')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($other->user)->getJson('/api/v1/affiliate/earnings/ledger')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_rates_show_what_each_product_earns(): void
    {
        $affiliate = $this->approvedAffiliate();
        $card = $this->product(['name' => 'PVC card', 'price_minor' => 150000]);
        $stand = $this->product(['name' => 'Review stand', 'price_minor' => 80000]);
        $this->rule(['rate_bp' => 1000]);
        $this->rule(['scope' => 'product', 'product_id' => $stand->id, 'kind' => 'exclude']);

        $response = $this->actingAs($affiliate->user)->getJson('/api/v1/affiliate/earnings/rates')->assertOk()
            ->assertJsonPath('data.default.rate_bp', 1000);
        $products = collect($response->json('data.products'))->keyBy('name');
        $this->assertSame(15000, $products['PVC card']['commission_per_unit_minor']);
        $this->assertFalse($products['Review stand']['earns']);
    }

    public function test_suspended_member_can_still_see_earnings_but_not_rates(): void
    {
        $this->paidOrder(100000, 1);
        $affiliate = Commission::sole()->affiliate;
        $affiliate->forceFill(['status' => AffiliateStatus::Suspended])->save();

        $this->actingAs($affiliate->user)->getJson('/api/v1/affiliate/earnings/summary')->assertOk();
        $this->actingAs($affiliate->user)->getJson('/api/v1/affiliate/earnings/rates')->assertForbidden();
    }

    public function test_customers_cannot_use_earnings_endpoints(): void
    {
        $this->actingAs($this->userWithRole(Role::Customer))->getJson('/api/v1/affiliate/earnings/summary')->assertForbidden();
    }

    public function test_staff_commission_views_follow_permissions(): void
    {
        [$order] = $this->paidOrder(100000, 1);
        $commission = Commission::sole();

        $this->actingAs($this->finance())->getJson('/api/v1/admin/commissions')->assertOk()
            ->assertJsonPath('data.0.order_number', $order->order_number)
            ->assertJsonPath('data.0.affiliate.id', $commission->affiliate->public_id);
        $this->actingAs($this->finance())->getJson('/api/v1/admin/commissions/summary')->assertOk()->assertJsonPath('data.balances.pending', 10000);
        $this->actingAs($this->finance())->getJson("/api/v1/admin/commissions/{$commission->public_id}")->assertOk()
            ->assertJsonPath('data.snapshot.rule.rate_bp', 1000)->assertJsonPath('data.ledger.0.type', 'accrual');
        $this->actingAs($this->finance())->getJson("/api/v1/admin/affiliates/{$commission->affiliate->public_id}/ledger")->assertOk()
            ->assertJsonPath('balances.pending', 10000);

        // Order detail shows commissions to finance, not to support.
        $this->actingAs($this->finance())->getJson("/api/v1/admin/orders/{$order->public_id}")->assertOk()
            ->assertJsonPath('data.commissions.0.amount_minor', 10000)
            ->assertJsonPath('data.payment.reference', 'NIC-'.$order->order_number);
        $support = $this->userWithRole(Role::Support);
        $this->actingAs($support)->getJson("/api/v1/admin/orders/{$order->public_id}")->assertOk()->assertJsonMissingPath('data.commissions');
        $this->actingAs($support)->getJson('/api/v1/admin/commissions')->assertForbidden();
    }

    public function test_manual_attribution_on_a_paid_order_creates_the_commission(): void
    {
        $this->rule(['rate_bp' => 1000]);
        $this->placeOrder($product = $this->product(['price_minor' => 100000]))->assertCreated();
        $this->flushHeaders();
        $order = Order::sole();
        $this->pay($order)->assertCreated();
        $affiliate = $this->approvedAffiliate();
        $manager = $this->userWithRole(Role::AffiliateManager);

        $this->actingAs($manager)->postJson("/api/v1/admin/orders/{$order->public_id}/attribution", [
            'affiliate_id' => $affiliate->public_id, 'reason' => 'Customer showed the affiliate\'s Instagram post',
        ])->assertOk();
        $this->assertSame(20000, Commission::sole()->amount_minor);

        $this->actingAs($manager)->postJson("/api/v1/admin/orders/{$order->public_id}/attribution", [
            'affiliate_id' => $this->approvedAffiliate()->public_id, 'reason' => 'Changing my mind about who referred',
        ])->assertUnprocessable()->assertJsonValidationErrors('order');
    }
}
