<?php

namespace Tests\Feature\Commissions;

use App\Enums\AffiliateStatus;
use App\Enums\Role;
use App\Models\Commission;
use App\Models\CommissionLedgerEntry;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\ReferralEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Payment confirmation is the only thing that creates commission, and it
 * does so exactly once per order line.
 */
class PaymentCommissionTest extends TestCase
{
    use CommissionTestHelpers;
    use RefreshDatabase;

    public function test_confirming_payment_creates_a_pending_commission_and_ledger_accrual(): void
    {
        $this->travelTo(now()->startOfMinute());
        $this->rule(['rate_bp' => 1000]);
        $affiliate = $this->approvedAffiliate();
        // Spec example: NPR 2,000 of eligible revenue at 10% is NPR 200.
        $order = $this->attributedOrder($affiliate, $this->product(['price_minor' => 100000]), 2);

        $this->pay($order)->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment.method', 'bank_transfer')
            ->assertJsonPath('data.commissions.0.amount_minor', 20000)
            ->assertJsonPath('data.commissions.0.status', 'pending');

        $commission = Commission::sole();
        $this->assertSame(200000, $commission->base_minor);
        $this->assertSame(1000, $commission->rate_bp);
        $this->assertSame(20000, $commission->amount_minor);
        $this->assertSame('NPR', $commission->currency);
        $this->assertTrue($commission->eligible_at->equalTo($order->fresh()->paid_at->copy()->addDays(14)));
        $this->assertSame('global', $commission->snapshot['rule']['scope']);
        $this->assertSame(1000, $commission->snapshot['rule']['rate_bp']);
        $this->assertSame(200000, $commission->snapshot['line']['line_total_minor']);
        $this->assertFalse($commission->isHeld());

        $entry = CommissionLedgerEntry::sole();
        $this->assertNull($entry->from_bucket);
        $this->assertSame('pending', $entry->to_bucket->value);
        $this->assertSame(20000, $entry->amount_minor);
        $this->assertSame(1, ReferralEvent::where('type', 'order_paid')->count());
    }

    public function test_commission_base_is_after_the_coupon_discount(): void
    {
        $this->rule(['rate_bp' => 1000]);
        $affiliate = $this->approvedAffiliate();
        Coupon::factory()->create(['code' => 'SAVE10', 'affiliate_profile_id' => $affiliate->id, 'discount_type' => 'percent', 'discount_value' => 1000]);
        $order = $this->attributedOrder($affiliate, $this->product(['price_minor' => 100000]), 2, ['coupon_code' => 'SAVE10', 'referral_code' => null]);

        $this->pay($order)->assertCreated();

        $commission = Commission::sole();
        $this->assertSame(180000, $commission->base_minor);
        $this->assertSame(18000, $commission->amount_minor);
    }

    public function test_replaying_the_same_payment_is_a_no_op(): void
    {
        $this->rule();
        $order = $this->attributedOrder($this->approvedAffiliate(), $this->product());
        $finance = $this->finance();

        $this->pay($order, $finance)->assertCreated();
        $this->pay($order, $finance)->assertOk();

        $this->assertSame(1, OrderPayment::count());
        $this->assertSame(1, Commission::count());
        $this->assertSame(1, CommissionLedgerEntry::count());
    }

    public function test_a_second_different_payment_is_refused(): void
    {
        $this->rule();
        $order = $this->attributedOrder($this->approvedAffiliate(), $this->product());
        $this->pay($order)->assertCreated();

        $this->pay($order, null, ['reference' => 'OTHER-REF'])->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->assertSame(1, Commission::count());
    }

    public function test_reference_already_used_on_another_order_is_refused(): void
    {
        $product = $this->product();
        $first = $this->attributedOrder($this->approvedAffiliate(), $product);
        $second = $this->attributedOrder($this->approvedAffiliate(), $product);
        $this->pay($first, null, ['reference' => 'DUP-1'])->assertCreated();

        $this->pay($second, null, ['reference' => 'DUP-1'])->assertUnprocessable()->assertJsonValidationErrors('reference');
    }

    public function test_amount_must_equal_the_order_total(): void
    {
        $order = $this->attributedOrder($this->approvedAffiliate(), $this->product());

        $this->pay($order, null, ['amount_minor' => $order->total_minor - 1])->assertUnprocessable()->assertJsonValidationErrors('amount_minor');
        $this->assertSame('pending_payment', $order->fresh()->status->value);
    }

    public function test_cancelled_order_cannot_be_marked_paid(): void
    {
        $order = $this->attributedOrder($this->approvedAffiliate(), $this->product());
        $order->forceFill(['status' => 'cancelled'])->save();

        $this->pay($order)->assertUnprocessable()->assertJsonValidationErrors('order');
    }

    public function test_unattributed_order_creates_no_commission(): void
    {
        $this->rule();
        $this->placeOrder($product = $this->product())->assertCreated();
        $order = Order::sole();

        $this->pay($order)->assertCreated();

        $this->assertSame(0, Commission::count());
        $this->assertSame(0, CommissionLedgerEntry::count());
    }

    public function test_coupon_that_does_not_earn_commission_creates_none(): void
    {
        $this->rule();
        $affiliate = $this->approvedAffiliate();
        Coupon::factory()->create(['code' => 'STAFFONLY', 'affiliate_profile_id' => $affiliate->id, 'earns_commission' => false]);
        $order = $this->attributedOrder($affiliate, $this->product(), 1, ['coupon_code' => 'STAFFONLY', 'referral_code' => null]);

        $this->pay($order)->assertCreated();

        $this->assertSame(0, Commission::count());
    }

    public function test_no_matching_rule_means_no_commission(): void
    {
        $order = $this->attributedOrder($this->approvedAffiliate(), $this->product());

        $this->pay($order)->assertCreated();

        $this->assertSame(0, Commission::count());
    }

    public function test_affiliate_suspended_before_payment_gets_a_held_commission(): void
    {
        $this->rule();
        $affiliate = $this->approvedAffiliate();
        $order = $this->attributedOrder($affiliate, $this->product());
        $affiliate->forceFill(['status' => AffiliateStatus::Suspended])->save();

        $this->pay($order)->assertCreated();

        $commission = Commission::sole();
        $this->assertTrue($commission->isHeld());
        $this->assertStringContainsString('suspended', $commission->hold_reason);
    }

    public function test_only_staff_with_payments_confirm_can_confirm(): void
    {
        $order = $this->attributedOrder($this->approvedAffiliate(), $this->product());

        foreach ([Role::Support, Role::AffiliateManager, Role::Customer, Role::Affiliate] as $role) {
            $this->pay($order, $this->userWithRole($role))->assertForbidden();
        }
        $this->pay($order, $this->userWithRole(Role::SuperAdmin))->assertCreated();
    }

    public function test_each_line_gets_its_own_commission(): void
    {
        $this->rule(['rate_bp' => 500]);
        $a = $this->product(['price_minor' => 100000]);
        $b = $this->product(['price_minor' => 33333]);
        $order = $this->attributedOrder($this->approvedAffiliate(), $a, lines: [[$a, 1], [$b, 1]]);

        $this->pay($order)->assertCreated();

        // 5% of 1000.00 = 50.00; 5% of 333.33 = 16.6665, half-up to 16.67.
        $this->assertSame([5000, 1667], Commission::orderBy('id')->pluck('amount_minor')->all());
    }
}
