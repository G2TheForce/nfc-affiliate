<?php

namespace Tests\Feature\Store;

use App\Enums\AffiliateStatus;
use App\Enums\DiscountType;
use App\Enums\Role;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Referrals\ReferralTestHelpers;
use Tests\TestCase;

class CouponCheckoutTest extends TestCase
{
    use ReferralTestHelpers;
    use RefreshDatabase;

    public function test_percentage_coupon_respects_the_cap_and_is_allocated_across_lines(): void
    {
        $a = $this->product(['price_minor' => 100000]);
        $b = $this->product(['price_minor' => 50001]);
        Coupon::factory()->create(['code' => 'TEN', 'discount_value' => 1000, 'max_discount_minor' => 20000]);

        $this->placeOrder($a, null, [
            'items' => [['product_id' => $a->public_id, 'quantity' => 1], ['product_id' => $b->public_id, 'quantity' => 1]],
            'coupon_code' => 'TEN',
        ])->assertCreated()
            ->assertJsonPath('data.subtotal_minor', 150001)
            ->assertJsonPath('data.discount_minor', 15000)
            ->assertJsonPath('data.total_minor', 135001);

        $order = Order::sole();
        $this->assertSame(15000, $order->items->sum('discount_minor'));
        $this->assertSame($order->subtotal_minor - $order->discount_minor, $order->items->sum('line_total_minor'));
        $this->assertSame(1, CouponRedemption::count());
    }

    public function test_fixed_coupon_never_exceeds_the_eligible_amount(): void
    {
        $product = $this->product(['price_minor' => 30000]);
        Coupon::factory()->create(['code' => 'BIG', 'discount_type' => DiscountType::Fixed, 'discount_value' => 500000]);

        $this->placeOrder($product, null, ['items' => [['product_id' => $product->public_id, 'quantity' => 1]], 'coupon_code' => 'BIG'])
            ->assertCreated()
            ->assertJsonPath('data.discount_minor', 30000)
            ->assertJsonPath('data.total_minor', 0);
    }

    public function test_product_restricted_coupon_discounts_only_those_products(): void
    {
        $cards = $this->product(['price_minor' => 100000]);
        $stand = $this->product(['price_minor' => 100000]);
        $coupon = Coupon::factory()->create(['code' => 'CARDS', 'discount_value' => 5000]);
        $coupon->products()->attach($cards->id);

        $this->placeOrder($cards, null, [
            'items' => [['product_id' => $cards->public_id, 'quantity' => 1], ['product_id' => $stand->public_id, 'quantity' => 1]],
            'coupon_code' => 'CARDS',
        ])->assertCreated()->assertJsonPath('data.discount_minor', 50000);

        $this->placeOrder($stand, null, ['coupon_code' => 'CARDS'])
            ->assertUnprocessable()->assertJsonValidationErrors(['coupon_code' => 'does not apply']);
    }

    public function test_invalid_coupons_are_rejected_with_a_reason(): void
    {
        $product = $this->product(['price_minor' => 100000]);
        Coupon::factory()->create(['code' => 'OFF', 'is_active' => false]);
        Coupon::factory()->create(['code' => 'LATER', 'starts_at' => now()->addDay()]);
        Coupon::factory()->create(['code' => 'OLD', 'ends_at' => now()->subDay()]);
        Coupon::factory()->create(['code' => 'MIN', 'min_order_minor' => 500000]);
        $suspended = $this->affiliate(AffiliateStatus::Suspended);
        Coupon::factory()->create(['code' => 'SUSP', 'affiliate_profile_id' => $suspended->id]);

        foreach (['NOPE' => 'not valid', 'OFF' => 'not active', 'LATER' => 'not active', 'OLD' => 'not active', 'MIN' => 'minimum', 'SUSP' => 'not active'] as $code => $message) {
            $this->placeOrder($product, null, ['coupon_code' => $code])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['coupon_code' => $message]);
        }

        $this->assertSame(0, Order::count());
    }

    public function test_affiliate_cannot_use_their_own_coupon(): void
    {
        $affiliate = $this->affiliate(user: ['email' => 'gita@example.com']);
        Coupon::factory()->create(['code' => 'GITA', 'affiliate_profile_id' => $affiliate->id]);

        $this->placeOrder($this->product(), null, ['coupon_code' => 'GITA', 'customer_email' => 'gita@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors(['coupon_code' => 'your own']);
    }

    public function test_usage_and_per_customer_limits_are_enforced(): void
    {
        $product = $this->product();
        Coupon::factory()->create(['code' => 'ONCE', 'per_customer_limit' => 1, 'usage_limit' => 2]);

        $this->placeOrder($product, null, ['coupon_code' => 'ONCE'])->assertCreated();
        $this->placeOrder($product, null, ['coupon_code' => 'ONCE', 'customer_email' => 'SITA@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors(['coupon_code' => 'maximum number']);
        $this->placeOrder($product, null, ['coupon_code' => 'ONCE', 'customer_email' => 'gopal@example.com'])->assertCreated();
        $this->placeOrder($product, null, ['coupon_code' => 'ONCE', 'customer_email' => 'maya@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors(['coupon_code' => 'usage limit']);
    }

    public function test_cancelling_an_order_frees_its_coupon_use(): void
    {
        $product = $this->product();
        Coupon::factory()->create(['code' => 'ONCE', 'usage_limit' => 1]);
        $this->placeOrder($product, null, ['coupon_code' => 'ONCE'])->assertCreated();

        $admin = $this->userWithRole(Role::SuperAdmin);
        $this->actingAs($admin)->postJson('/api/v1/admin/orders/'.Order::sole()->public_id.'/cancel', ['reason' => 'Customer changed mind'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertNotNull(CouponRedemption::sole()->voided_at);
        $this->placeOrder($product, null, ['coupon_code' => 'ONCE', 'customer_email' => 'new@example.com'])->assertCreated();
    }

    public function test_quote_prices_a_cart_without_creating_anything(): void
    {
        $product = $this->product(['price_minor' => 100000]);
        Coupon::factory()->create(['code' => 'TEN']);

        $this->spa()->postJson('/api/v1/store/cart/quote', [
            'items' => [['product_id' => $product->public_id, 'quantity' => 3]],
            'coupon_code' => 'ten',
        ])->assertOk()
            ->assertJsonPath('data.subtotal_minor', 300000)
            ->assertJsonPath('data.discount_minor', 30000)
            ->assertJsonPath('data.coupon_code', 'TEN');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, CouponRedemption::count());
    }

    public function test_inactive_products_and_excess_quantities_are_rejected(): void
    {
        $inactive = $this->product(['is_active' => false]);
        $product = $this->product();

        $this->placeOrder($inactive)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->placeOrder($product, null, ['items' => [['product_id' => $product->public_id, 'quantity' => 101]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        // The same product split across lines is merged before the limit check.
        $this->placeOrder($product, null, ['items' => [
            ['product_id' => $product->public_id, 'quantity' => 60],
            ['product_id' => $product->public_id, 'quantity' => 60],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_client_cannot_set_prices_totals_or_status(): void
    {
        $product = $this->product(['price_minor' => 150000]);

        $this->placeOrder($product, null, [
            'items' => [['product_id' => $product->public_id, 'quantity' => 1, 'unit_price_minor' => 1]],
            'total_minor' => 1,
            'status' => 'paid',
        ])->assertCreated()
            ->assertJsonPath('data.total_minor', 150000)
            ->assertJsonPath('data.status', 'pending_payment');
    }
}
