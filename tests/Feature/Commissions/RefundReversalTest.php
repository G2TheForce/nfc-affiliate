<?php

namespace Tests\Feature\Commissions;

use App\Domain\Commissions\CommissionLedger;
use App\Domain\Commissions\CommissionLifecycle;
use App\Models\Commission;
use App\Models\CommissionLedgerEntry;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundReversalTest extends TestCase
{
    use CommissionTestHelpers;
    use RefreshDatabase;
    use RefundDatabaseHelpers;

    public function test_partial_refund_reverses_only_the_refunded_share(): void
    {
        [$order, $item] = $this->paidOrder(100000, 2); // line 2,000.00, commission 200.00

        $this->refund($order, [['item_id' => $item, 'amount_minor' => 50000]])
            ->assertCreated()
            ->assertJsonPath('data.status', 'partially_refunded')
            ->assertJsonPath('data.refunded_minor', 50000)
            ->assertJsonPath('meta.commission_reversed_minor', 5000);

        $commission = Commission::sole();
        $this->assertSame(5000, $commission->reversed_minor);
        $this->assertSame(15000, $commission->netMinor());
        $this->assertSame('pending', $commission->status->value);
        $this->assertBalances($commission->affiliate_profile_id, pending: 15000, reversed: 5000);
    }

    public function test_repeated_partial_refunds_do_not_drift_through_rounding(): void
    {
        [$order, $item] = $this->paidOrder(33333, 1, 1000); // commission 33.33 (3333.3 half-up = 3333)

        $this->refund($order, [['item_id' => $item, 'amount_minor' => 11111]])->assertCreated();
        $this->refund($order, [['item_id' => $item, 'amount_minor' => 11111]])->assertCreated();
        $this->refund($order, [['item_id' => $item, 'amount_minor' => 11111]])->assertCreated();

        $commission = Commission::sole();
        $this->assertSame(3333, $commission->reversed_minor);
        $this->assertSame(0, $commission->netMinor());
        $this->assertSame('reversed', $commission->status->value);
        $this->assertSame('refunded', $order->fresh()->status->value);
    }

    public function test_full_refund_reverses_the_whole_commission(): void
    {
        [$order, $item] = $this->paidOrder(100000, 2);

        $this->refund($order, [['item_id' => $item, 'amount_minor' => 200000]])->assertCreated()->assertJsonPath('data.status', 'refunded');

        $commission = Commission::sole();
        $this->assertSame('reversed', $commission->status->value);
        $this->assertNotNull($commission->reversed_at);
        $this->assertBalances($commission->affiliate_profile_id, pending: 0, reversed: 20000);
    }

    public function test_refund_on_one_line_leaves_the_other_lines_commission_alone(): void
    {
        $this->rule(['rate_bp' => 1000]);
        $a = $this->product(['price_minor' => 100000]);
        $b = $this->product(['price_minor' => 50000]);
        $order = $this->attributedOrder($this->approvedAffiliate(), $a, lines: [[$a, 1], [$b, 1]]);
        $this->pay($order)->assertCreated();
        $lineB = $order->items()->where('product_id', $b->id)->value('public_id');

        $this->refund($order, [['item_id' => $lineB, 'amount_minor' => 50000]])->assertCreated();

        $this->assertSame([0, 5000], Commission::orderBy('id')->pluck('reversed_minor')->all());
    }

    public function test_same_refund_reference_is_not_reversed_twice(): void
    {
        [$order, $item] = $this->paidOrder(100000, 2);
        $finance = $this->finance();
        $payload = ['reference' => 'RF-SAME'];

        $this->refund($order, [['item_id' => $item, 'amount_minor' => 50000]], $finance, $payload)->assertCreated();
        $this->refund($order, [['item_id' => $item, 'amount_minor' => 50000]], $finance, $payload)->assertOk();

        $this->assertSame(1, Refund::count());
        $this->assertSame(5000, Commission::sole()->reversed_minor);
        $this->assertSame(50000, $order->fresh()->refunded_minor);
    }

    public function test_cannot_refund_more_than_what_is_left_of_a_line(): void
    {
        [$order, $item] = $this->paidOrder(100000, 2);
        $this->refund($order, [['item_id' => $item, 'amount_minor' => 150000]])->assertCreated();

        $this->refund($order, [['item_id' => $item, 'amount_minor' => 50001]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.amount_minor');
    }

    public function test_refund_needs_a_paid_order_and_a_line_from_it(): void
    {
        $unpaid = $this->attributedOrder($this->approvedAffiliate(), $this->product());
        $item = $unpaid->items()->value('public_id');
        $this->refund($unpaid, [['item_id' => $item, 'amount_minor' => 100]])->assertUnprocessable()->assertJsonValidationErrors('order');

        [$order] = $this->paidOrder(100000, 1);
        $this->refund($order, [['item_id' => $item, 'amount_minor' => 100]])->assertUnprocessable()->assertJsonValidationErrors('items.0.item_id');
        $this->refund($order, [])->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_refund_after_commission_is_available_reverses_from_available(): void
    {
        [$order, $item] = $this->paidOrder(100000, 2);
        $this->travel(15)->days();
        $this->artisan('affiliate:mature-commissions')->assertSuccessful();
        $this->assertSame('available', Commission::sole()->status->value);

        $this->refund($order, [['item_id' => $item, 'amount_minor' => 100000]])->assertCreated();

        $this->assertBalances(Commission::sole()->affiliate_profile_id, available: 10000, reversed: 10000);
    }

    public function test_refund_of_a_paid_commission_leaves_a_recoverable_negative_balance(): void
    {
        [$order, $item] = $this->paidOrder(100000, 2);
        $commission = Commission::sole();
        // Payouts arrive in Phase 5; simulate a commission already paid out.
        $commission->forceFill(['status' => 'paid'])->save();

        $this->refund($order, [['item_id' => $item, 'amount_minor' => 200000]])->assertCreated();

        $commission->refresh();
        $this->assertSame('paid', $commission->status->value);
        $this->assertSame(20000, $commission->reversed_minor);
        $this->assertSame(-20000, app(CommissionLedger::class)->balances($commission->affiliate_profile_id)['available']);
    }

    public function test_voided_commission_is_not_reversed_again_by_a_refund(): void
    {
        [$order, $item] = $this->paidOrder(100000, 2);
        app(CommissionLifecycle::class)->void(Commission::sole(), $this->finance(), 'Confirmed fraudulent order');

        $this->refund($order, [['item_id' => $item, 'amount_minor' => 200000]])->assertCreated()->assertJsonPath('meta.commission_reversed_minor', 0);

        $this->assertSame(2, CommissionLedgerEntry::count());
    }

    public function test_delivery_charge_refund_does_not_touch_commission(): void
    {
        config(['affiliate.store.shipping_flat_minor' => 15000]);
        [$order] = $this->paidOrder(100000, 1);

        $this->refund($order, [], null, ['shipping_minor' => 15000])->assertCreated()->assertJsonPath('meta.commission_reversed_minor', 0);
        $this->refund($order, [], null, ['shipping_minor' => 1])->assertUnprocessable()->assertJsonValidationErrors('shipping_minor');
        $this->assertSame('partially_refunded', $order->fresh()->status->value);
    }
}
