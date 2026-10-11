<?php

namespace Tests\Feature\Referrals;

use App\Domain\Referrals\ReferralEventRecorder;
use App\Enums\AffiliateStatus;
use App\Enums\ReferralEventType;
use App\Enums\Role;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\ReferralEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The store journey: referral link, then checkout. These tests go through
 * the real /ref redirect and the real checkout endpoint.
 */
class CheckoutAttributionTest extends TestCase
{
    use ReferralTestHelpers;
    use RefreshDatabase;

    public function test_order_after_a_referral_click_is_attributed_to_that_affiliate(): void
    {
        $affiliate = $this->affiliate();
        $product = $this->product(['price_minor' => 150000]);
        $click = $this->clickCookie($this->visit('/ref/'.$affiliate->referral_code));

        $response = $this->placeOrder($product, $click);

        $response->assertCreated()
            ->assertJsonPath('data.total_minor', 300000)
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonMissingPath('data.attribution');
        $order = Order::sole();
        $this->assertSame('attributed', $order->attribution_status->value);
        $this->assertSame($affiliate->id, $order->attribution->affiliate_profile_id);
        $this->assertSame('click', $order->attribution->source->value);
        $this->assertSame('last_click', $order->attribution->policy);
        $this->assertSame(30, $order->attribution->window_days);
        $this->assertNotNull($order->attribution->clicked_at);
        $this->assertSame(1, ReferralEvent::where('type', 'order_placed')->where('affiliate_profile_id', $affiliate->id)->count());
    }

    public function test_no_referral_means_no_attribution(): void
    {
        $this->placeOrder($this->product())->assertCreated();

        $order = Order::sole();
        $this->assertSame('none', $order->attribution_status->value);
        $this->assertNull($order->attribution);
        $this->assertSame(0, ReferralEvent::count());
    }

    public function test_click_outside_the_attribution_window_is_ignored(): void
    {
        $affiliate = $this->affiliate();
        $click = $this->clickCookie($this->visit('/ref/'.$affiliate->referral_code));

        $this->travel(31)->days();
        $this->placeOrder($this->product(), $click)->assertCreated();

        $this->assertSame('none', Order::sole()->attribution_status->value);
    }

    public function test_click_inside_a_configured_window_counts(): void
    {
        config(['affiliate.attribution.window_days' => 60]);
        $affiliate = $this->affiliate();
        $click = $this->clickCookie($this->visit('/ref/'.$affiliate->referral_code));

        $this->travel(45)->days();
        $this->placeOrder($this->product(), $click)->assertCreated();

        $this->assertSame('attributed', Order::sole()->attribution_status->value);
    }

    public function test_affiliate_suspended_after_the_click_does_not_get_the_order(): void
    {
        $affiliate = $this->affiliate();
        $click = $this->clickCookie($this->visit('/ref/'.$affiliate->referral_code));
        $affiliate->forceFill(['status' => AffiliateStatus::Suspended])->save();

        $this->placeOrder($this->product(), $click)->assertCreated();

        $order = Order::sole();
        $this->assertSame('blocked', $order->attribution_status->value);
        $this->assertSame('affiliate_not_eligible', $order->attribution_block_reason);
    }

    public function test_affiliate_buying_through_their_own_link_is_not_attributed(): void
    {
        $affiliate = $this->affiliate(user: ['email' => 'ram@example.com']);
        $click = $this->clickCookie($this->visit('/ref/'.$affiliate->referral_code));

        // As a guest using the affiliate's email...
        $this->placeOrder($this->product(), $click, ['customer_email' => 'RAM@example.com'])->assertCreated();
        // ...and signed in as the affiliate.
        $this->placeOrder($this->product(), $click, [], $affiliate->user)->assertCreated();

        $this->assertSame(['blocked', 'blocked'], Order::orderBy('id')->pluck('attribution_status')->map->value->all());
        $this->assertSame(['self_referral', 'self_referral'], Order::orderBy('id')->pluck('attribution_block_reason')->all());
    }

    public function test_affiliate_coupon_overrides_an_earlier_click_from_another_affiliate(): void
    {
        $clicked = $this->affiliate();
        $couponOwner = $this->affiliate();
        $coupon = Coupon::factory()->create(['code' => 'RAM10', 'affiliate_profile_id' => $couponOwner->id]);
        $click = $this->clickCookie($this->visit('/ref/'.$clicked->referral_code));

        $this->placeOrder($this->product(['price_minor' => 100000]), $click, ['coupon_code' => 'ram10'])
            ->assertCreated()
            ->assertJsonPath('data.discount_minor', 20000)
            ->assertJsonPath('data.coupon_code', 'RAM10');

        $attribution = Order::sole()->attribution;
        $this->assertSame($couponOwner->id, $attribution->affiliate_profile_id);
        $this->assertSame('coupon', $attribution->source->value);
        $this->assertSame($coupon->id, $attribution->coupon_id);
    }

    public function test_click_wins_when_coupons_are_configured_not_to_override(): void
    {
        config(['affiliate.attribution.coupon_overrides_click' => false]);
        $clicked = $this->affiliate();
        $couponOwner = $this->affiliate();
        Coupon::factory()->create(['code' => 'RAM10', 'affiliate_profile_id' => $couponOwner->id]);
        $click = $this->clickCookie($this->visit('/ref/'.$clicked->referral_code));

        $this->placeOrder($this->product(), $click, ['coupon_code' => 'RAM10'])->assertCreated();

        $this->assertSame($clicked->id, Order::sole()->attribution->affiliate_profile_id);
    }

    public function test_store_wide_coupon_does_not_replace_the_click(): void
    {
        $clicked = $this->affiliate();
        Coupon::factory()->create(['code' => 'DASHAIN']);
        $click = $this->clickCookie($this->visit('/ref/'.$clicked->referral_code));

        $this->placeOrder($this->product(), $click, ['coupon_code' => 'DASHAIN'])->assertCreated();

        $this->assertSame('click', Order::sole()->attribution->source->value);
    }

    public function test_referral_code_typed_at_checkout_attributes_the_order(): void
    {
        $affiliate = $this->affiliate();

        // e.g. a mobile app or a customer told the code in person: no cookie.
        $this->placeOrder($this->product(), null, ['referral_code' => strtolower($affiliate->referral_code)])->assertCreated();

        $attribution = Order::sole()->attribution;
        $this->assertSame($affiliate->id, $attribution->affiliate_profile_id);
        $this->assertSame('referral_code', $attribution->source->value);
    }

    public function test_cookie_is_ignored_on_non_first_party_requests(): void
    {
        $affiliate = $this->affiliate();
        $click = $this->clickCookie($this->visit('/ref/'.$affiliate->referral_code));

        // No Referer/Origin from the SPA, so Sanctum does not treat the
        // request as stateful and the encrypted cookie is not read.
        $this->withCredentials()
            ->withCookie(config('affiliate.attribution.cookie'), $click)
            ->postJson('/api/v1/store/orders', $this->checkoutPayload($this->product()))
            ->assertCreated();

        $this->assertSame('none', Order::sole()->attribution_status->value);
    }

    public function test_forged_cookie_values_are_ignored(): void
    {
        $this->affiliate();

        $this->placeOrder($this->product(), '01JAAAAAAAAAAAAAAAAAAAAAAA')->assertCreated();
        $this->placeOrder($this->product(), "' OR 1=1 --")->assertCreated();

        $this->assertSame(['none', 'none'], Order::orderBy('id')->pluck('attribution_status')->map->value->all());
    }

    public function test_double_submitted_checkout_creates_one_order_and_one_event(): void
    {
        $affiliate = $this->affiliate();
        $product = $this->product();
        $click = $this->clickCookie($this->visit('/ref/'.$affiliate->referral_code));
        $key = 'checkout-'.str_repeat('a', 24);

        $first = $this->spa($click)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/store/orders', $this->checkoutPayload($product));
        $second = $this->spa($click)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/store/orders', $this->checkoutPayload($product));

        $first->assertCreated();
        $second->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, ReferralEvent::count());
    }

    public function test_reusing_a_checkout_key_for_a_different_cart_is_rejected(): void
    {
        $product = $this->product();
        $key = 'checkout-'.str_repeat('b', 24);

        $this->spa()->withHeader('Idempotency-Key', $key)->postJson('/api/v1/store/orders', $this->checkoutPayload($product))->assertCreated();
        $this->spa()->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/store/orders', $this->checkoutPayload($product, ['items' => [['product_id' => $product->public_id, 'quantity' => 5]]]))
            ->assertStatus(409);

        $this->assertSame(1, Order::count());
    }

    public function test_recording_the_same_event_twice_is_a_no_op(): void
    {
        $affiliate = $this->affiliate();
        $this->placeOrder($this->product(), null, ['referral_code' => $affiliate->referral_code])->assertCreated();
        $order = Order::sole();

        $recorder = app(ReferralEventRecorder::class);
        $this->assertFalse($recorder->record(ReferralEventType::OrderPlaced, $affiliate->id, $order));
        $this->assertSame(1, ReferralEvent::count());
    }

    public function test_signed_in_customer_orders_use_the_account_email_and_are_listed_for_them_only(): void
    {
        $customer = $this->userWithRole(Role::Customer, ['email' => 'hari@example.com']);
        $other = $this->userWithRole(Role::Customer);

        $this->placeOrder($this->product(), null, ['customer_email' => 'someone-else@example.com'], $customer)
            ->assertCreated()
            ->assertJsonPath('data.customer_email', 'hari@example.com');
        $orderId = Order::sole()->public_id;

        $this->actingAs($customer)->getJson('/api/v1/orders')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($other)->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($other)->getJson("/api/v1/orders/{$orderId}")->assertNotFound();
    }
}
