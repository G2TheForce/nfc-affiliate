<?php

namespace Tests\Feature\Commissions;

use App\Domain\Commissions\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\Order;

trait RefundDatabaseHelpers
{
    /**
     * A paid, attributed one-line order with a global percentage rule.
     *
     * @return array{0: Order, 1: string} the order and its line's public id
     */
    protected function paidOrder(int $price, int $quantity, int $rateBp = 1000): array
    {
        if (! CommissionRule::query()->where('scope', 'global')->exists()) {
            $this->rule(['rate_bp' => $rateBp]);
        }
        $order = $this->attributedOrder($this->approvedAffiliate(), $this->product(['price_minor' => $price]), $quantity);
        $this->pay($order)->assertCreated();

        return [$order->fresh(), $order->items()->value('public_id')];
    }

    protected function assertBalances(int $affiliateId, ?int $pending = null, ?int $approved = null, ?int $available = null, ?int $reversed = null): void
    {
        $balances = app(CommissionLedger::class)->balances($affiliateId);
        foreach (compact('pending', 'approved', 'available', 'reversed') as $bucket => $expected) {
            if ($expected !== null) {
                $this->assertSame($expected, $balances[$bucket], "Balance of {$bucket}");
            }
        }
    }
}
