<?php

namespace App\Domain\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a payment that a staff member has verified themselves (bank
 * statement, cash received, wallet merchant dashboard), with its reference.
 * Online eSewa and Khalti payments go through ProviderPaymentService instead.
 * Commissions are created in the same transaction.
 */
class ManualPaymentService
{
    public const PROVIDER = 'manual';

    public function __construct(private readonly PaymentRecorder $recorder) {}

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

            $payment = $this->recorder->record($locked, self::PROVIDER, PaymentMethod::from($data['method']), $reference, $actor, note: $data['note'] ?? null);

            return ['payment' => $payment, 'replayed' => false];
        });
    }
}
