<?php

namespace App\Domain\Payments;

use App\Domain\Audit\AuditLogger;
use App\Domain\Commissions\CommissionService;
use App\Domain\Referrals\ReferralEventRecorder;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReferralEventType;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a payment that a staff member has verified themselves (bank
 * statement, cash received, wallet merchant dashboard), with its reference.
 * This is the only way an order becomes paid until provider webhooks are
 * connected in Phase 7. Commissions are created in the same transaction.
 */
class ManualPaymentService
{
    public const PROVIDER = 'manual';

    public function __construct(
        private readonly CommissionService $commissions,
        private readonly ReferralEventRecorder $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{method: string, reference: string, amount_minor: int, note?: ?string}  $data
     * @return array{payment: OrderPayment, replayed: bool}
     */
    public function confirm(Order $order, User $actor, array $data): array
    {
        $reference = trim($data['reference']);

        return DB::transaction(function () use ($order, $actor, $data, $reference) {
            $locked = Order::query()->with('coupon')->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $existing = OrderPayment::query()->where('provider', self::PROVIDER)->where('reference', $reference)->first();
            if ($existing) {
                if ($existing->order_id === $locked->id && $existing->amount_minor === (int) $data['amount_minor']) {
                    return ['payment' => $existing, 'replayed' => true];
                }
                throw ValidationException::withMessages(['reference' => 'This payment reference is already recorded.']);
            }

            if ($locked->status === OrderStatus::Cancelled) {
                throw ValidationException::withMessages(['order' => 'This order was cancelled. Refund the customer instead of confirming the payment.']);
            }
            if ($locked->status !== OrderStatus::PendingPayment) {
                throw ValidationException::withMessages(['order' => 'A payment is already recorded for this order.']);
            }
            if ((int) $data['amount_minor'] !== $locked->total_minor) {
                throw ValidationException::withMessages(['amount_minor' => 'The amount received must equal the order total. Partial payments are not supported.']);
            }

            $payment = new OrderPayment;
            $payment->forceFill([
                'order_id' => $locked->id,
                'provider' => self::PROVIDER,
                'method' => PaymentMethod::from($data['method']),
                'reference' => $reference,
                'amount_minor' => $locked->total_minor,
                'currency' => $locked->currency,
                'note' => $data['note'] ?? null,
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
            ])->save();

            $locked->forceFill(['status' => OrderStatus::Paid, 'paid_at' => $payment->confirmed_at])->save();

            if ($attribution = $locked->attribution()->first()) {
                $this->events->record(ReferralEventType::OrderPaid, $attribution->affiliate_profile_id, $locked, $locked->total_minor);
            }

            $this->audit->log('order.payment_confirmed', $locked, [
                'payment' => $payment->public_id,
                'method' => $payment->method->value,
                'reference' => $reference,
                'amount_minor' => $payment->amount_minor,
            ], $actor);

            $this->commissions->createForOrder($locked, $actor);

            return ['payment' => $payment, 'replayed' => false];
        });
    }
}
