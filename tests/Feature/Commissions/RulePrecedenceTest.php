<?php

namespace Tests\Feature\Commissions;

use App\Enums\Role;
use App\Models\Commission;
use App\Models\CommissionPlan;
use App\Models\CommissionRule;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RulePrecedenceTest extends TestCase
{
    use CommissionTestHelpers;
    use RefreshDatabase;

    private function commissionFor(array $productAttributes = [], ?callable $prepare = null): ?Commission
    {
        $affiliate = $this->approvedAffiliate();
        $product = $this->product(['price_minor' => 100000] + $productAttributes);
        if ($prepare) {
            $prepare($affiliate, $product);
        }
        $order = $this->attributedOrder($affiliate, $product, 1);
        $this->pay($order)->assertCreated();

        return Commission::first();
    }

    public function test_each_level_beats_the_ones_below_it(): void
    {
        $category = ProductCategory::create(['name' => 'Metal cards', 'slug' => 'metal']);
        $plan = CommissionPlan::create(['name' => 'Creators']);

        $commission = $this->commissionFor(['category_id' => $category->id], function ($affiliate, $product) use ($category, $plan) {
            $affiliate->forceFill(['commission_plan_id' => $plan->id])->save();
            $this->rule(['scope' => 'global', 'rate_bp' => 500]);
            $this->rule(['scope' => 'plan', 'commission_plan_id' => $plan->id, 'rate_bp' => 600]);
            $this->rule(['scope' => 'category', 'product_category_id' => $category->id, 'rate_bp' => 700]);
            $this->rule(['scope' => 'product', 'product_id' => $product->id, 'rate_bp' => 800]);
            $this->rule(['scope' => 'affiliate', 'affiliate_profile_id' => $affiliate->id, 'rate_bp' => 900]);
        });
        $this->assertSame(900, $commission->rate_bp);
        $this->assertSame('affiliate', $commission->rule_scope);

        CommissionRule::where('scope', 'affiliate')->update(['is_active' => false]);
        $this->assertSame(800, $this->secondOrderRate($commission));
        CommissionRule::where('scope', 'product')->update(['is_active' => false]);
        $this->assertSame(700, $this->secondOrderRate($commission));
        CommissionRule::where('scope', 'category')->update(['is_active' => false]);
        $this->assertSame(600, $this->secondOrderRate($commission));
        CommissionRule::where('scope', 'plan')->update(['is_active' => false]);
        $this->assertSame(500, $this->secondOrderRate($commission));
    }

    /** A new order for the same affiliate and product as the given commission. */
    private function secondOrderRate(Commission $commission): ?int
    {
        $order = $this->attributedOrder($commission->affiliate, $commission->orderItem->product, 1);
        $this->pay($order)->assertCreated();

        return Commission::where('order_id', $order->id)->value('rate_bp');
    }

    public function test_product_exclusion_beats_an_affiliate_override(): void
    {
        $commission = $this->commissionFor([], function ($affiliate, $product) {
            $this->rule(['scope' => 'affiliate', 'affiliate_profile_id' => $affiliate->id, 'rate_bp' => 2000]);
            $this->rule(['scope' => 'product', 'product_id' => $product->id, 'kind' => 'exclude']);
        });

        $this->assertNull($commission);
    }

    public function test_category_exclusion_beats_a_product_rate(): void
    {
        $category = ProductCategory::create(['name' => 'Gift cards', 'slug' => 'gift']);
        $commission = $this->commissionFor(['category_id' => $category->id], function ($affiliate, $product) use ($category) {
            $this->rule(['scope' => 'product', 'product_id' => $product->id, 'rate_bp' => 1500]);
            $this->rule(['scope' => 'category', 'product_category_id' => $category->id, 'kind' => 'exclude']);
        });

        $this->assertNull($commission);
    }

    public function test_fixed_rule_pays_per_unit(): void
    {
        $commission = $this->commissionFor([], fn ($affiliate, $product) => $this->rule(['scope' => 'product', 'product_id' => $product->id, 'kind' => 'fixed', 'fixed_minor' => 12500]));

        $this->assertSame(12500, $commission->amount_minor);
        $this->assertSame('fixed', $commission->kind->value);
        $this->assertNull($commission->rate_bp);
    }

    public function test_rules_in_force_when_the_order_was_placed_apply(): void
    {
        $this->rule(['rate_bp' => 1000, 'starts_at' => now()->subYear(), 'ends_at' => now()->addDay()]);
        $order = $this->attributedOrder($this->approvedAffiliate(), $this->product(['price_minor' => 100000]), 1);
        $this->travel(2)->days();
        $this->rule(['rate_bp' => 3000, 'starts_at' => now()->subHour()]);

        $this->pay($order)->assertCreated();

        $this->assertSame(1000, Commission::sole()->rate_bp);
    }

    public function test_editing_a_rule_bumps_its_version_without_changing_existing_commissions(): void
    {
        $rule = $this->rule(['rate_bp' => 1000]);
        $order = $this->attributedOrder($this->approvedAffiliate(), $this->product(['price_minor' => 100000]), 1);
        $this->pay($order)->assertCreated();

        $this->actingAs($this->userWithRole(Role::AffiliateManager))
            ->patchJson("/api/v1/admin/commission-rules/{$rule->public_id}", ['rate_bp' => 1500])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.rate_bp', 1500);

        $commission = Commission::sole();
        $this->assertSame(1000, $commission->rate_bp);
        $this->assertSame(1, $commission->rule_version);
        $this->assertSame(10000, $commission->amount_minor);
    }

    public function test_overlapping_rules_for_the_same_target_are_refused(): void
    {
        $product = $this->product();
        $manager = $this->userWithRole(Role::AffiliateManager);
        $payload = ['name' => 'Launch rate', 'scope' => 'product', 'target_id' => $product->public_id, 'kind' => 'percent', 'rate_bp' => 1200, 'starts_at' => now()->toIso8601String()];

        $this->actingAs($manager)->postJson('/api/v1/admin/commission-rules', $payload)->assertCreated();
        $this->actingAs($manager)->postJson('/api/v1/admin/commission-rules', ['kind' => 'exclude'] + $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        // Back-to-back dates are fine once the first rule has an end.
        $first = CommissionRule::sole();
        $this->actingAs($manager)->patchJson("/api/v1/admin/commission-rules/{$first->public_id}", ['ends_at' => now()->addMonth()->toIso8601String()])->assertOk();
        $this->actingAs($manager)->postJson('/api/v1/admin/commission-rules', ['starts_at' => now()->addMonth()->toIso8601String()] + $payload)->assertCreated();
    }

    public function test_exclusions_only_for_products_and_categories(): void
    {
        $this->expectException(ValidationException::class);
        $this->rule(['scope' => 'global', 'kind' => 'exclude']);
    }

    public function test_rule_payload_is_validated(): void
    {
        $manager = $this->userWithRole(Role::AffiliateManager);

        $this->actingAs($manager)->postJson('/api/v1/admin/commission-rules', ['name' => 'Bad', 'scope' => 'global', 'kind' => 'percent', 'rate_bp' => 9000, 'starts_at' => now()->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors('rate_bp');
        $this->actingAs($manager)->postJson('/api/v1/admin/commission-rules', ['name' => 'No target', 'scope' => 'product', 'kind' => 'percent', 'rate_bp' => 500, 'starts_at' => now()->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors('target_id');
        $rule = $this->rule();
        $this->actingAs($manager)->patchJson("/api/v1/admin/commission-rules/{$rule->public_id}", ['scope' => 'product'])
            ->assertUnprocessable()->assertJsonValidationErrors('scope');
    }

    public function test_preview_shows_the_winning_rule(): void
    {
        $affiliate = $this->approvedAffiliate();
        $product = $this->product(['price_minor' => 250000]);
        $this->rule(['rate_bp' => 1000]);
        $this->rule(['scope' => 'affiliate', 'affiliate_profile_id' => $affiliate->id, 'rate_bp' => 1200, 'name' => 'VIP']);

        $this->actingAs($this->finance())
            ->getJson("/api/v1/admin/commission-rules/preview?affiliate_id={$affiliate->public_id}&product_id={$product->public_id}")
            ->assertOk()
            ->assertJsonPath('data.rule.name', 'VIP')
            ->assertJsonPath('data.commission_per_unit_minor', 30000);
    }

    public function test_only_rule_managers_can_change_rules(): void
    {
        $payload = ['name' => 'Global', 'scope' => 'global', 'kind' => 'percent', 'rate_bp' => 1000, 'starts_at' => now()->toIso8601String()];

        foreach ([Role::FinanceManager, Role::Support, Role::Affiliate] as $role) {
            $this->actingAs($this->userWithRole($role))->postJson('/api/v1/admin/commission-rules', $payload)->assertForbidden();
        }
        $this->actingAs($this->userWithRole(Role::FinanceManager))->getJson('/api/v1/admin/commission-rules')->assertOk();
        $this->actingAs($this->userWithRole(Role::Support))->getJson('/api/v1/admin/commission-rules')->assertForbidden();
    }

    public function test_plan_assignment_is_audited(): void
    {
        $affiliate = $this->approvedAffiliate();
        $manager = $this->userWithRole(Role::AffiliateManager);
        $plan = $this->actingAs($manager)->postJson('/api/v1/admin/commission-plans', ['name' => 'Agencies'])->assertCreated()->json('data.id');

        $this->actingAs($manager)->postJson("/api/v1/admin/affiliates/{$affiliate->public_id}/plan", ['plan_id' => $plan, 'reason' => 'Signed agency deal'])
            ->assertOk()->assertJsonPath('data.plan.name', 'Agencies');

        $this->assertNotNull($affiliate->fresh()->commission_plan_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'affiliate.plan_changed', 'actor_id' => $manager->id]);
    }
}
