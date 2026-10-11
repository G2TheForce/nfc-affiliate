<?php

namespace Tests\Feature\Store;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Referrals\ReferralTestHelpers;
use Tests\TestCase;

class AdminStoreTest extends TestCase
{
    use ReferralTestHelpers;
    use RefreshDatabase;

    public function test_super_admin_manages_products_and_changes_are_audited(): void
    {
        $admin = $this->userWithRole(Role::SuperAdmin);

        $id = $this->actingAs($admin)->postJson('/api/v1/admin/products', [
            'name' => 'Metal NFC Card', 'sku' => 'NFC-METAL', 'slug' => 'metal-nfc-card', 'price_minor' => 250000,
        ])->assertCreated()->assertJsonPath('data.currency', 'NPR')->json('data.id');

        $this->actingAs($admin)->patchJson("/api/v1/admin/products/{$id}", ['price_minor' => 240000, 'is_active' => false])
            ->assertOk()->assertJsonPath('data.price_minor', 240000);

        $this->assertSame(['product.created', 'product.updated'], AuditLog::where('subject_type', 'Product')->orderBy('id')->pluck('action')->all());
        $this->getJson('/api/v1/store/products/metal-nfc-card')->assertNotFound();
    }

    public function test_only_catalog_managers_change_products(): void
    {
        foreach ([Role::AffiliateManager, Role::FinanceManager, Role::Support, Role::Affiliate, Role::Customer] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->postJson('/api/v1/admin/products', ['name' => 'X', 'sku' => 'X1', 'slug' => 'x', 'price_minor' => 1])
                ->assertForbidden();
        }
        $this->assertSame(0, Product::count());
    }

    public function test_affiliate_manager_creates_an_affiliate_coupon(): void
    {
        $manager = $this->userWithRole(Role::AffiliateManager);
        $affiliate = $this->affiliate();
        $product = $this->product();

        $this->actingAs($manager)->postJson('/api/v1/admin/coupons', [
            'code' => 'ram-dashain',
            'affiliate_id' => $affiliate->public_id,
            'discount_type' => 'percent',
            'discount_value' => 1500,
            'per_customer_limit' => 1,
            'product_ids' => [$product->public_id],
        ])->assertCreated()
            ->assertJsonPath('data.code', 'RAM-DASHAIN')
            ->assertJsonPath('data.affiliate.id', $affiliate->public_id)
            ->assertJsonPath('data.products.0.id', $product->public_id);

        $this->actingAs($manager)->postJson('/api/v1/admin/coupons', ['code' => 'RAM-DASHAIN', 'discount_type' => 'fixed', 'discount_value' => 100])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->actingAs($manager)->postJson('/api/v1/admin/coupons', ['code' => 'TOOMUCH', 'discount_type' => 'percent', 'discount_value' => 10001])
            ->assertUnprocessable()->assertJsonValidationErrors('discount_value');

        $this->actingAs($this->userWithRole(Role::Support))->getJson('/api/v1/admin/coupons')->assertForbidden();
        $this->actingAs($this->userWithRole(Role::FinanceManager))->postJson('/api/v1/admin/coupons', ['code' => 'FIN'])->assertForbidden();
    }

    public function test_used_coupon_code_cannot_be_renamed(): void
    {
        Coupon::factory()->create(['code' => 'USED']);
        $this->placeOrder($this->product(), null, ['coupon_code' => 'USED'])->assertCreated();

        $this->actingAs($this->userWithRole(Role::SuperAdmin))
            ->patchJson('/api/v1/admin/coupons/'.Coupon::sole()->public_id, ['code' => 'RENAMED'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_support_sees_masked_orders_with_attribution_but_cannot_change_them(): void
    {
        $affiliate = $this->affiliate();
        $this->placeOrder($this->product(), null, ['referral_code' => $affiliate->referral_code])->assertCreated();
        $order = Order::sole();
        $support = $this->userWithRole(Role::Support);

        $response = $this->actingAs($support)->getJson('/api/v1/admin/orders/'.$order->public_id)->assertOk();
        $response->assertJsonPath('data.customer_email', 'si***@example.com')
            ->assertJsonPath('data.customer_phone', '***********111')
            ->assertJsonPath('data.attribution.affiliate.id', $affiliate->public_id)
            ->assertJsonPath('data.attribution.source', 'referral_code');
        $this->assertStringNotContainsString('sita@example.com', $response->getContent());

        // A list of several attributed orders (lazy loading is disabled in tests, so this also catches N+1s).
        $this->placeOrder($this->product(), null, ['referral_code' => $affiliate->referral_code, 'customer_email' => 'om@example.com'])->assertCreated();
        $list = $this->actingAs($support)->getJson('/api/v1/admin/orders?attribution=attributed')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([$affiliate->public_id, $affiliate->public_id], array_column(array_column(array_column($list->json('data'), 'attribution'), 'affiliate'), 'id'));
        $this->assertContains('si***@example.com', array_column($list->json('data'), 'customer_email'));
        $this->assertStringNotContainsString('sita@example.com', $list->getContent());

        $this->actingAs($support)->postJson("/api/v1/admin/orders/{$order->public_id}/cancel", ['reason' => 'Trying it'])->assertForbidden();
        $this->actingAs($support)->postJson("/api/v1/admin/orders/{$order->public_id}/attribution", ['affiliate_id' => $affiliate->public_id, 'reason' => 'Trying this out'])->assertForbidden();
    }

    public function test_affiliates_and_customers_cannot_see_staff_order_endpoints(): void
    {
        $this->placeOrder($this->product())->assertCreated();
        $order = Order::sole();

        foreach ([Role::Affiliate, Role::Customer] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->getJson('/api/v1/admin/orders')->assertForbidden();
            $this->actingAs($user)->getJson('/api/v1/admin/orders/'.$order->public_id)->assertForbidden();
        }
    }

    public function test_manual_attribution_requires_a_reason_and_is_audited(): void
    {
        $original = $this->affiliate();
        $claimed = $this->affiliate();
        $this->placeOrder($this->product(), null, ['referral_code' => $original->referral_code])->assertCreated();
        $order = Order::sole();
        $manager = $this->userWithRole(Role::AffiliateManager);

        $this->actingAs($manager)->postJson("/api/v1/admin/orders/{$order->public_id}/attribution", ['affiliate_id' => $claimed->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->actingAs($manager)->postJson("/api/v1/admin/orders/{$order->public_id}/attribution", [
            'affiliate_id' => $claimed->public_id,
            'reason' => 'Customer showed the Instagram story they ordered from.',
        ])->assertOk()
            ->assertJsonPath('data.attribution.source', 'manual')
            ->assertJsonPath('data.attribution.affiliate.id', $claimed->public_id)
            ->assertJsonPath('data.attribution.decided_by', $manager->name);

        $log = AuditLog::where('action', 'order.attribution.manual')->sole();
        $this->assertSame($manager->id, $log->actor_id);
        $this->assertSame($original->public_id, $log->properties['previous_affiliate']);
        $this->assertSame('referral_code', $log->properties['previous_source']);
    }

    public function test_manual_attribution_refuses_self_referrals_and_cancelled_orders(): void
    {
        $affiliate = $this->affiliate(user: ['email' => 'self@example.com']);
        $this->placeOrder($this->product(), null, ['customer_email' => 'self@example.com'])->assertCreated();
        $admin = $this->userWithRole(Role::SuperAdmin);
        $order = Order::sole();

        $this->actingAs($admin)->postJson("/api/v1/admin/orders/{$order->public_id}/attribution", ['affiliate_id' => $affiliate->public_id, 'reason' => 'Asked to attribute it'])
            ->assertUnprocessable()->assertJsonValidationErrors('affiliate_id');

        $this->actingAs($admin)->postJson("/api/v1/admin/orders/{$order->public_id}/cancel", ['reason' => 'Test order'])->assertOk();
        $other = $this->affiliate();
        $this->actingAs($admin)->postJson("/api/v1/admin/orders/{$order->public_id}/attribution", ['affiliate_id' => $other->public_id, 'reason' => 'Asked to attribute it'])
            ->assertUnprocessable()->assertJsonValidationErrors('order');
    }

    public function test_only_unpaid_orders_can_be_cancelled_once(): void
    {
        $this->placeOrder($this->product())->assertCreated();
        $order = Order::sole();
        $admin = $this->userWithRole(Role::SuperAdmin);

        $this->actingAs($admin)->postJson("/api/v1/admin/orders/{$order->public_id}/cancel", ['reason' => 'Customer request'])->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/admin/orders/{$order->public_id}/cancel", ['reason' => 'Customer request'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame(1, AuditLog::where('action', 'order.cancelled')->count());
    }
}
