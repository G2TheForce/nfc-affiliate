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
use App\Notifications\Store\OrderPaymentReceived;
use Illuminate\Support\Facades\Notification;

/**
 * Marks an order paid from verified evidence: a staff confirmation or a
 * provider status check. The caller holds the order row lock inside a
 * transaction and has checked the order can take the payment. Commissions
 * are created in the same transaction.
 */
class PaymentRecorder
{
    public function __construct(
        private readonly CommissionService $commissions,
        private readonly ReferralEventRecorder $events,
        private readonly AuditLogger $audit,
    ) {}

    public function record(
        Order $locked,
        string $provider,
        PaymentMethod $method,
        string $reference,
        ?User $actor,
        ?int $attemptId = null,
        ?string $note = null,
    ): OrderPayment {
        $payment = new OrderPayment;
        $payment->forceFill([
            'order_id' => $locked->id,
            'payment_attempt_id' => $attemptId,
            'provider' => $provider,
            'method' => $method,
            'reference' => $reference,
            'amount_minor' => $locked->total_minor,
            'currency' => $locked->currency,
            'note' => $note,
            'confirmed_by' => $actor?->id,
            'confirmed_at' => now(),
        ])->save();

        $locked->forceFill(['status' => OrderStatus::Paid, 'paid_at' => $payment->confirmed_at])->save();

        if ($attribution = $locked->attribution()->first()) {
            $this->events->record(ReferralEventType::OrderPaid, $attribution->affiliate_profile_id, $locked, $locked->total_minor);
        }

        $this->audit->log('order.payment_confirmed', $locked, [
            'payment' => $payment->public_id,
            'provider' => $provider,
            'method' => $method->value,
            'reference' => $reference,
            'amount_minor' => $payment->amount_minor,
        ], $actor);

        $this->commissions->createForOrder($locked, $actor);

        // Queued, and sent only once the payment is committed.
        Notification::route('mail', $locked->customer_email)->notify((new OrderPaymentReceived($locked, $payment))->afterCommit());

        return $payment;
    }
}
