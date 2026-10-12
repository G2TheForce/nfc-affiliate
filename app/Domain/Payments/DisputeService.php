<?php

namespace App\Domain\Payments;

use App\Domain\Audit\AuditLogger;
use App\Domain\Commissions\CommissionLifecycle;
use App\Enums\CommissionStatus;
use App\Enums\DisputeStatus;
use App\Models\Commission;
use App\Models\Order;
use App\Models\OrderDispute;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payment disputes and chargebacks, handled by staff (master prompt section
 * 10). Opening one holds the order's unpaid commissions. Won: the holds are
 * released. Lost: the disputed money is recorded as a refund, which reverses
 * commission through the normal refund path (including clawback of
 * commission already paid out).
 */
class DisputeService
{
    public function __construct(
        private readonly CommissionLifecycle $lifecycle,
        private readonly RefundService $refunds,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array{reference: string, amount_minor: int, reason: string}  $data */
    public function open(Order $order, User $actor, array $data): OrderDispute
    {
        try {
            return DB::transaction(function () use ($order, $actor, $data) {
                $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                if (! $locked->status->isPaid()) {
                    throw ValidationException::withMessages(['order' => 'Only a paid order can be disputed.']);
                }
                $disputable = $locked->total_minor - $locked->refunded_minor;
                if ($data['amount_minor'] < 1 || $data['amount_minor'] > $disputable) {
                    throw ValidationException::withMessages(['amount_minor' => 'The disputed amount can be at most what was paid and not yet refunded.']);
                }

                $dispute = new OrderDispute;
                $dispute->forceFill([
                    'order_id' => $locked->id,
                    'open_order_id' => $locked->id,
                    'reference' => trim($data['reference']),
                    'amount_minor' => $data['amount_minor'],
                    'currency' => $locked->currency,
                    'reason' => $data['reason'],
                    'status' => DisputeStatus::Open,
                    'opened_by' => $actor->id,
                    'opened_at' => now(),
                ])->save();

                $held = [];
                $commissions = Commission::query()->where('order_id', $locked->id)
                    ->whereIn('status', [CommissionStatus::Pending->value, CommissionStatus::Approved->value])
                    ->whereNull('held_at')->get();
                foreach ($commissions as $commission) {
                    $this->lifecycle->hold($commission, $actor, "Payment dispute {$dispute->reference}");
                    $held[] = $commission->id;
                }
                $dispute->forceFill(['held_commission_ids' => $held])->save();

                $this->audit->log('order.dispute_opened', $locked, [
                    'dispute' => $dispute->public_id,
                    'reference' => $dispute->reference,
                    'amount_minor' => $dispute->amount_minor,
                    'commissions_held' => count($held),
                ], $actor);

                return $dispute;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['reference' => 'This order already has an open dispute, or this dispute reference is already recorded.']);
        }
    }

    public function resolve(OrderDispute $dispute, User $actor, string $outcome, string $note): OrderDispute
    {
        return DB::transaction(function () use ($dispute, $actor, $outcome, $note) {
            $locked = OrderDispute::query()->whereKey($dispute->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== DisputeStatus::Open) {
                throw ValidationException::withMessages(['dispute' => 'This dispute is already resolved.']);
            }

            $refund = null;
            if ($outcome === DisputeStatus::Lost->value) {
                $refund = $this->refundDisputedAmount($locked, $actor, $note);
            }

            // Release the holds this dispute placed on commissions still live.
            foreach (Commission::query()->whereIn('id', $locked->held_commission_ids ?? [])->get() as $commission) {
                if ($commission->isHeld() && $commission->status !== CommissionStatus::Reversed) {
                    $this->lifecycle->releaseHold($commission, $actor, "Dispute {$locked->reference} {$outcome}");
                }
            }

            $locked->forceFill([
                'status' => DisputeStatus::from($outcome),
                'open_order_id' => null,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
                'resolution_note' => $note,
                'refund_id' => $refund?->id,
            ])->save();

            $this->audit->log('order.dispute_resolved', $locked->order, [
                'dispute' => $locked->public_id,
                'outcome' => $outcome,
                'refund' => $refund?->public_id,
                'note' => $note,
            ], $actor);

            return $locked;
        });
    }

    /**
     * Spreads the disputed amount over the order's lines in proportion to what
     * is left of each (delivery last), and records it as one refund.
     */
    private function refundDisputedAmount(OrderDispute $dispute, User $actor, string $note): Refund
    {
        $order = $dispute->order()->firstOrFail();
        $lines = $order->items()->withSum('refundItems as refunded_minor', 'amount_minor')->orderBy('id')->get()
            ->map(fn ($l) => ['id' => $l->public_id, 'left' => $l->line_total_minor - (int) $l->refunded_minor])
            ->filter(fn ($l) => $l['left'] > 0)->values();
        $shippingLeft = $order->shipping_minor - (int) $order->refunds()->sum('shipping_minor');

        $amount = min($dispute->amount_minor, $order->total_minor - $order->refunded_minor);
        $linesLeft = (int) $lines->sum('left');
        $forLines = min($amount, $linesLeft);
        $shipping = min($amount - $forLines, max(0, $shippingLeft));

        $items = [];
        $allocated = 0;
        foreach ($lines as $i => $line) {
            $share = $i === $lines->count() - 1 ? $forLines - $allocated : intdiv($forLines * $line['left'], max(1, $linesLeft));
            $share = min($share, $line['left']);
            if ($share > 0) {
                $items[] = ['item_id' => $line['id'], 'amount_minor' => $share];
                $allocated += $share;
            }
        }

        return $this->refunds->record($order, $actor, [
            'reference' => "DISPUTE-{$dispute->reference}",
            'reason' => "Chargeback lost ({$dispute->reference}): {$note}",
            'items' => $items,
            'shipping_minor' => $shipping,
        ])['refund'];
    }
}
