<?php

namespace Tests\Feature\Commissions;

use App\Domain\Commissions\CommissionRuleService;
use App\Enums\AffiliateStatus;
use App\Enums\Role;
use App\Models\AffiliateProfile;
use App\Models\CommissionRule;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Referrals\ReferralTestHelpers;

trait CommissionTestHelpers
{
    use ReferralTestHelpers;

    private ?User $ruleAdmin = null;

    protected function finance(): User
    {
        return $this->userWithRole(Role::FinanceManager);
    }

    protected function rule(array $attributes = []): CommissionRule
    {
        $this->ruleAdmin ??= $this->userWithRole(Role::SuperAdmin);

        return app(CommissionRuleService::class)->create(array_merge([
            'name' => 'Rule '.fake()->unique()->numberBetween(1, 99999),
            'scope' => 'global',
            'kind' => 'percent',
            'rate_bp' => 1000,
            'starts_at' => now()->subYear(),
            'is_active' => true,
        ], $attributes), $this->ruleAdmin);
    }

    /**
     * Places an order through the real checkout, attributed by referral code.
     *
     * @param  list<array{0: Product, 1: int}>|null  $lines
     */
    protected function attributedOrder(AffiliateProfile $affiliate, Product $product, int $quantity = 2, array $overrides = [], ?array $lines = null): Order
    {
        $items = $lines
            ? array_map(fn ($l) => ['product_id' => $l[0]->public_id, 'quantity' => $l[1]], $lines)
            : [['product_id' => $product->public_id, 'quantity' => $quantity]];

        $this->placeOrder($product, null, array_merge(['items' => $items, 'referral_code' => $affiliate->referral_code], $overrides))->assertCreated();
        $this->flushHeaders();

        return Order::query()->latest('id')->firstOrFail();
    }

    protected function pay(Order $order, ?User $as = null, array $overrides = []): TestResponse
    {
        return $this->actingAs($as ?? $this->finance())->postJson("/api/v1/admin/orders/{$order->public_id}/payments", array_merge([
            'method' => 'bank_transfer',
            'reference' => 'NIC-'.$order->order_number,
            'amount_minor' => $order->total_minor,
        ], $overrides));
    }

    protected function refund(Order $order, array $items, ?User $as = null, array $overrides = []): TestResponse
    {
        return $this->actingAs($as ?? $this->finance())->postJson("/api/v1/admin/orders/{$order->public_id}/refunds", array_merge([
            'reference' => 'RF-'.fake()->unique()->numerify('######'),
            'reason' => 'Customer returned the card',
            'items' => $items,
        ], $overrides));
    }

    protected function approvedAffiliate(): AffiliateProfile
    {
        return $this->affiliate(AffiliateStatus::Approved);
    }
}
