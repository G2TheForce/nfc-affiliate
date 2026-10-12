<?php

namespace Tests\Feature\Commissions;

use App\Enums\Role;
use App\Models\Commission;
use App\Notifications\Affiliate\CommissionReversed;
use App\Notifications\Affiliate\ReferredOrderPaid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AffiliateNoticesTest extends TestCase
{
    use CommissionTestHelpers;
    use RefreshDatabase;
    use RefundDatabaseHelpers;

    public function test_affiliate_is_told_when_a_referred_order_is_paid_with_the_commission_and_no_customer_details(): void
    {
        Notification::fake();
        [$order] = $this->paidOrder(100000, 2); // commission 200.00
        $user = Commission::sole()->affiliate->user;

        Notification::assertSentTo($user, ReferredOrderPaid::class, function (ReferredOrderPaid $n) use ($order, $user) {
            $mail = (string) $n->toMail($user)->render();

            return $n->commissionMinor === 20000
                && str_contains($mail, 'NPR 200.00')
                && str_contains($mail, '…'.substr($order->order_number, -4))
                && ! str_contains($mail, $order->order_number)
                && ! str_contains($mail, $order->customer_email);
        });
    }

    public function test_order_with_no_matching_rule_still_tells_the_affiliate_without_an_amount(): void
    {
        Notification::fake();
        $affiliate = $this->approvedAffiliate();
        $order = $this->attributedOrder($affiliate, $this->product(['price_minor' => 50000]));
        $this->pay($order)->assertCreated();

        Notification::assertSentTo($affiliate->user, ReferredOrderPaid::class, fn (ReferredOrderPaid $n) => $n->commissionMinor === 0
            && str_contains($n->toArray($affiliate->user)['body'], 'No commission applies'));
    }

    public function test_refund_tells_the_affiliate_how_much_commission_was_reversed(): void
    {
        [$order, $item] = $this->paidOrder(100000, 2);
        Notification::fake();

        $this->refund($order, [['item_id' => $item, 'amount_minor' => 50000]])->assertCreated();

        $user = Commission::sole()->affiliate->user;
        Notification::assertSentToTimes($user, CommissionReversed::class, 1);
        Notification::assertSentTo($user, CommissionReversed::class, fn (CommissionReversed $n) => $n->amountMinor === 5000
            && $n->cause === CommissionReversed::CAUSE_REFUND);
    }

    public function test_voiding_tells_the_affiliate_but_never_shows_the_staff_reason(): void
    {
        $this->paidOrder(100000, 1);
        $commission = Commission::sole();
        Notification::fake();

        $this->actingAs($this->userWithRole(Role::AffiliateManager))
            ->postJson("/api/v1/admin/commissions/{$commission->public_id}/void", ['reason' => 'Suspected self-referral ring'])
            ->assertOk();

        $user = $commission->affiliate->user;
        Notification::assertSentTo($user, CommissionReversed::class, function (CommissionReversed $n) use ($user) {
            $mail = (string) $n->toMail($user)->render();

            return $n->amountMinor === 10000 && ! str_contains($mail, 'self-referral') && str_contains($mail, 'cancelled by our team');
        });
    }
}
