<?php

namespace App\Domain\Payments;

use App\Domain\Audit\AuditLogger;
use App\Domain\Commissions\CommissionService;
use App\Domain\Referrals\ReferralEventRecorder;
use App\Enums\OrderStatus;
use App\Enums\ReferralEventType;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a refund that has already been paid back to the customer, naming
 * the lines (and any delivery charge) it covers, and reverses only the
 * commission on those lines. The unique reference makes a repeated
 * submission a no-op.
 */
class RefundService
{
    public function __construct(
        private readonly CommissionService $commissions,
        private readonly ReferralEventRecorder $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{reference: string, reason: string, items?: list<array{item_id: string, amount_minor: int}>, shipping_minor?: ?int}  $data
     * @return array{refund: Refund, replayed: bool, commission_reversed_minor: int}
     */
    public function record(Order $order, User $actor, array $data): array
    {
        $reference = trim($data['reference']);

        return DB::transaction(function () use ($order, $actor, $data, $reference) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($existing = Refund::query()->where('reference', $reference)->first()) {
                if ($existing->order_id === $locked->id) {
                    return ['refund' => $existing, 'replayed' => true, 'commission_reversed_minor' => 0];
                }
                throw ValidationException::withMessages(['reference' => 'This refund reference is already recorded on another order.']);
            }

            if (! $locked->status->isPaid()) {
                throw ValidationException::withMessages(['order' => 'Only paid orders can be refunded.']);
            }

            $lines = $locked->items()->withSum('refundItems as refunded_minor', 'amount_minor')->get()->keyBy('public_id');
            $requested = [];
            foreach ($data['items'] ?? [] as $i => $row) {
                $line = $lines->get($row['item_id']);
                if (! $line) {
                    throw ValidationException::withMessages(["items.$i.item_id" => 'This line is not part of the order.']);
                }
                if (isset($requested[$line->id])) {
                    throw ValidationException::withMessages(["items.$i.item_id" => 'Each line can appear once per refund.']);
                }
                $amount = (int) $row['amount_minor'];
                $left = $line->line_total_minor - (int) $line->refunded_minor;
                if ($amount < 1 || $amount > $left) {
                    throw ValidationException::withMessages(["items.$i.amount_minor" => "Refund for {$line->sku} can be at most ".self::money($left, $locked->currency).' (what is left of the line).']);
                }
                $requested[$line->id] = $amount;
            }

            $shipping = (int) ($data['shipping_minor'] ?? 0);
            $shippingLeft = $locked->shipping_minor - (int) Refund::query()->where('order_id', $locked->id)->sum('shipping_minor');
            if ($shipping < 0 || $shipping > $shippingLeft) {
                throw ValidationException::withMessages(['shipping_minor' => 'The delivery refund can be at most '.self::money($shippingLeft, $locked->currency).'.']);
            }

            $total = array_sum($requested) + $shipping;
            if ($total < 1) {
                throw ValidationException::withMessages(['items' => 'Choose at least one line or a delivery charge to refund.']);
            }

            $refund = new Refund;
            $refund->forceFill([
                'order_id' => $locked->id,
                'reference' => $reference,
                'amount_minor' => $total,
                'shipping_minor' => $shipping,
                'currency' => $locked->currency,
                'reason' => $data['reason'],
                'recorded_by' => $actor->id,
                'completed_at' => now(),
            ])->save();
            foreach ($requested as $itemId => $amount) {
                $refund->items()->forceCreate(['order_item_id' => $itemId, 'amount_minor' => $amount]);
            }

            $refunded = $locked->refunded_minor + $total;
            $fully = $refunded >= $locked->total_minor;
            $locked->forceFill([
                'refunded_minor' => $refunded,
                'status' => $fully ? OrderStatus::Refunded : OrderStatus::PartiallyRefunded,
            ])->save();

            $reversed = $this->commissions->reverseForRefund($refund, $actor);

            if ($fully && ($attribution = $locked->attribution()->first())) {
                $this->events->record(ReferralEventType::OrderRefunded, $attribution->affiliate_profile_id, $locked, $locked->total_minor);
            }

            $this->audit->log('order.refund_recorded', $locked, [
                'refund' => $refund->public_id,
                'reference' => $reference,
                'amount_minor' => $total,
                'shipping_minor' => $shipping,
                'commission_reversed_minor' => $reversed,
                'reason' => $data['reason'],
            ], $actor);

            return ['refund' => $refund, 'replayed' => false, 'commission_reversed_minor' => $reversed];
        });
    }

    private static function money(int $minor, string $currency): string
    {
        return $currency.' '.number_format($minor / 100, 2);
    }
}
