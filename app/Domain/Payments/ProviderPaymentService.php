<?php

namespace App\Domain\Payments;

use App\Domain\Audit\AuditLogger;
use App\Domain\Payments\Gateways\CallbackData;
use App\Domain\Payments\Gateways\GatewayRegistry;
use App\Domain\Payments\Gateways\GatewayStatus;
use App\Domain\Payments\Gateways\GatewayUnavailable;
use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\Finance\PaymentNeedsReview;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Online payments (ADR 0011).
 *
 * An order becomes paid only when the provider's status API says the
 * attempt is complete for the right amount. The browser callback just
 * prompts that check. Every path (callback, staff re-check, scheduled
 * re-check) goes through refresh(), which locks the attempt and the order,
 * so duplicate or out-of-order deliveries cannot pay an order twice.
 */
class ProviderPaymentService
{
    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly PaymentRecorder $recorder,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Creates an attempt and asks the provider to start it.
     *
     * @return array{attempt: PaymentAttempt, redirect: array{type: string, url: string, fields?: array<string, string>}}
     */
    public function start(Order $order, string $provider): array
    {
        $gateway = $this->gateways->availableOrFail($provider);

        $attempt = DB::transaction(function () use ($order, $gateway) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== OrderStatus::PendingPayment) {
                throw ValidationException::withMessages(['order' => $locked->status === OrderStatus::Cancelled
                    ? 'This order was cancelled.'
                    : 'This order is already paid.']);
            }
            if ($locked->total_minor < $gateway->minimumMinor()) {
                throw ValidationException::withMessages(['provider' => "{$gateway->label()} needs at least NPR ".number_format($gateway->minimumMinor() / 100).'.']);
            }

            $attempt = new PaymentAttempt;
            $attempt->forceFill([
                'order_id' => $locked->id,
                'provider' => $gateway->key(),
                'amount_minor' => $locked->total_minor,
                'currency' => $locked->currency,
                'status' => PaymentAttemptStatus::Initiated,
                'next_check_at' => now()->addMinutes($this->delay(0)),
            ])->save();

            return $attempt;
        });

        try {
            $started = $gateway->start($attempt, $order);
        } catch (\Throwable $e) {
            $attempt->forceFill(['status' => PaymentAttemptStatus::Failed, 'provider_status' => 'start_failed', 'next_check_at' => null])->save();
            if ($e instanceof GatewayUnavailable) {
                Log::warning('Payment provider unavailable at start', ['provider' => $provider, 'attempt' => $attempt->public_id, 'error' => $e->getMessage()]);
                throw ValidationException::withMessages(['provider' => "{$gateway->label()} is not responding. Please try again in a few minutes or choose another way to pay."]);
            }
            throw $e;
        }

        $attempt->forceFill(['provider_reference' => $started['reference'], 'expires_at' => $started['expires_at']])->save();
        $this->audit->log('payment.started', $order, ['attempt' => $attempt->public_id, 'provider' => $provider, 'amount_minor' => $attempt->amount_minor]);

        return ['attempt' => $attempt, 'redirect' => $started['redirect']];
    }

    /**
     * Handles the customer's browser coming back from the provider. Returns
     * the attempt (if it could be identified) after a server-side check.
     *
     * @param  array<string, mixed>  $query
     */
    public function handleCallback(string $provider, string $kind, array $query, ?string $attemptId = null): ?PaymentAttempt
    {
        $gateway = $this->gateways->get($provider);
        // The failure redirect carries nothing but the attempt id in its path.
        $callback = $kind === 'failure' ? new CallbackData(null, null, []) : $gateway->readCallback($query);
        $event = $this->recordEvent($provider, $kind, $callback->safePayload + ($attemptId ? ['attempt' => $attemptId] : []), $callback->signatureValid);

        if ($event->processed_at !== null && $event->times_received > 1) {
            // A repeated callback (a page reload, a replay): nothing new to record,
            // but an attempt still waiting can be checked again.
            return $event->attempt ? $this->refresh($event->attempt) : null;
        }

        if ($callback->signatureValid === false) {
            $this->finishEvent($event, 'rejected', 'Signature did not verify.');
            Log::warning('Payment callback with a bad signature', ['provider' => $provider, 'event' => $event->id]);

            return $attemptId ? PaymentAttempt::query()->where('provider', $provider)->where('public_id', $attemptId)->first() : null;
        }

        $attempt = PaymentAttempt::query()->where('provider', $provider)
            ->where(fn ($q) => $q->when($callback->reference, fn ($w, $ref) => $w->where('provider_reference', $ref))
                ->when(! $callback->reference && $attemptId, fn ($w) => $w->where('public_id', $attemptId)))
            ->when(! $callback->reference && ! $attemptId, fn ($q) => $q->whereRaw('1 = 0'))
            ->first();

        if (! $attempt) {
            $this->finishEvent($event, 'unknown_attempt');

            return null;
        }

        $event->forceFill(['payment_attempt_id' => $attempt->id])->save();
        $attempt = $this->refresh($attempt);
        $this->finishEvent($event, $attempt->status === PaymentAttemptStatus::Completed ? 'verified_paid' : 'not_paid');

        return $attempt;
    }

    /**
     * Asks the provider for the attempt's status and applies it. Safe to call
     * any number of times, from anywhere, concurrently.
     */
    public function refresh(PaymentAttempt $attempt, ?User $actor = null): PaymentAttempt
    {
        if (! $attempt->status->isOpen() && $actor === null) {
            return $attempt;
        }
        if ($attempt->provider_reference === null) {
            return $attempt;
        }

        try {
            $status = $this->gateways->get($attempt->provider)->check($attempt);
        } catch (GatewayUnavailable $e) {
            Log::warning('Payment status check failed', ['attempt' => $attempt->public_id, 'error' => $e->getMessage()]);

            return $this->scheduleNextCheck($attempt);
        }

        return $this->apply($attempt, $status, $actor);
    }

    private function apply(PaymentAttempt $attempt, GatewayStatus $status, ?User $actor): PaymentAttempt
    {
        $review = null;

        $result = DB::transaction(function () use ($attempt, $status, $actor, &$review) {
            $locked = PaymentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            $base = [
                'provider_status' => $status->providerStatus,
                'last_checked_at' => now(),
                'check_count' => $locked->check_count + 1,
            ];

            // Final states are not reopened by a late or out-of-order answer.
            if ($locked->status === PaymentAttemptStatus::Completed || $locked->status === PaymentAttemptStatus::NeedsReview) {
                $refundUnrecorded = $status->outcome === GatewayStatus::REFUNDED
                    && $locked->status === PaymentAttemptStatus::Completed
                    && $order->status !== OrderStatus::Refunded
                    && $locked->review_reason === null;
                if ($refundUnrecorded) {
                    $review = 'The provider reports this payment as refunded. Record the refund on the order so the commission is reversed.';
                    $base['review_reason'] = $review;
                    $this->audit->log('payment.needs_review', $order, ['attempt' => $locked->public_id, 'reason' => $review, 'provider_status' => $status->providerStatus], $actor);
                }
                $locked->forceFill($base)->save();

                return $locked;
            }

            switch ($status->outcome) {
                case GatewayStatus::PAID:
                    if ($status->amountMinor !== null && $status->amountMinor !== $locked->amount_minor) {
                        $review = 'The provider reports '.number_format(($status->amountMinor) / 100, 2).' paid, but the attempt was for '.number_format($locked->amount_minor / 100, 2).'. Check the merchant dashboard before doing anything.';
                        break;
                    }
                    if ($order->status !== OrderStatus::PendingPayment) {
                        $review = $order->status === OrderStatus::Cancelled
                            ? 'The customer paid online after the order was cancelled. Refund them through the provider and record it here.'
                            : 'The order was already paid another way, so this online payment is extra. Refund the customer through the provider.';
                        break;
                    }

                    $reference = $status->transactionId ?? $locked->provider_reference;
                    try {
                        $this->recorder->record($order, $locked->provider, PaymentMethod::from($locked->provider), $reference, $actor, $locked->id);
                    } catch (UniqueConstraintViolationException) {
                        $review = 'The provider transaction id is already recorded on another payment.';
                        break;
                    }
                    $locked->forceFill($base + [
                        'status' => PaymentAttemptStatus::Completed,
                        'provider_transaction_id' => $status->transactionId,
                        'completed_at' => now(),
                        'next_check_at' => null,
                    ])->save();

                    return $locked;

                case GatewayStatus::PENDING:
                case GatewayStatus::NOT_FOUND:
                    $expired = $locked->created_at->lt(now()->subHours((int) config('payments.give_up_after_hours', 48)));
                    if ($status->outcome === GatewayStatus::NOT_FOUND && ($expired || ($locked->expires_at && $locked->expires_at->isPast()))) {
                        $locked->forceFill($base + ['status' => PaymentAttemptStatus::Failed, 'next_check_at' => null])->save();

                        return $locked;
                    }
                    if ($expired) {
                        $review = 'The provider still reports this payment as pending after '.config('payments.give_up_after_hours', 48).' hours. Check the merchant dashboard.';
                        break;
                    }
                    $locked->forceFill($base + [
                        'status' => $status->outcome === GatewayStatus::PENDING ? PaymentAttemptStatus::Pending : $locked->status,
                        'next_check_at' => now()->addMinutes($this->delay($locked->check_count + 1)),
                    ])->save();

                    return $locked;

                case GatewayStatus::FAILED:
                    $locked->forceFill($base + ['status' => PaymentAttemptStatus::Failed, 'next_check_at' => null])->save();

                    return $locked;

                case GatewayStatus::REFUNDED:
                    $review = 'The provider reports this payment as refunded, but it was never applied to the order. Check the merchant dashboard.';
                    break;
            }

            $locked->forceFill($base + [
                'status' => PaymentAttemptStatus::NeedsReview,
                'provider_transaction_id' => $status->transactionId,
                'review_reason' => $review,
                'next_check_at' => null,
            ])->save();
            $this->audit->log('payment.needs_review', $order, ['attempt' => $locked->public_id, 'reason' => $review, 'provider_status' => $status->providerStatus], $actor);

            return $locked;
        });

        if ($review !== null) {
            $this->notifyFinance($result->load('order'));
        }

        return $result;
    }

    /** Staff note that a needs-review attempt was dealt with (refunded, or confirmed fine). */
    public function resolveReview(PaymentAttempt $attempt, User $actor, string $note): PaymentAttempt
    {
        return DB::transaction(function () use ($attempt, $actor, $note) {
            $locked = PaymentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($locked->review_reason === null || $locked->reviewed_at !== null) {
                throw ValidationException::withMessages(['attempt' => 'This payment has nothing waiting for review.']);
            }
            $locked->forceFill(['reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
            $this->audit->log('payment.review_resolved', $locked->order, ['attempt' => $locked->public_id, 'note' => $note], $actor);

            return $locked;
        });
    }

    /**
     * Re-checks unfinished attempts whose next check is due.
     *
     * @return array{checked: int, completed: int}
     */
    public function recheckDue(int $limit = 100): array
    {
        $checked = 0;
        $completed = 0;
        PaymentAttempt::query()
            ->whereIn('status', [PaymentAttemptStatus::Initiated->value, PaymentAttemptStatus::Pending->value])
            ->whereNotNull('provider_reference')
            ->where('next_check_at', '<=', now())
            ->orderBy('next_check_at')
            ->limit($limit)
            ->get()
            ->each(function (PaymentAttempt $attempt) use (&$checked, &$completed) {
                $checked++;
                if ($this->refresh($attempt)->status === PaymentAttemptStatus::Completed) {
                    $completed++;
                }
            });

        return ['checked' => $checked, 'completed' => $completed];
    }

    /**
     * Records the callback, or counts a repeat of one already received.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordEvent(string $provider, string $kind, array $payload, ?bool $signatureValid): WebhookEvent
    {
        ksort($payload);
        $key = hash('sha256', $provider.'|'.$kind.'|'.json_encode($payload));

        $existing = WebhookEvent::query()->where('provider', $provider)->where('event_key', $key)->first();
        if ($existing) {
            $existing->increment('times_received');

            return $existing->refresh();
        }

        try {
            $event = new WebhookEvent;
            $event->forceFill([
                'provider' => $provider,
                'kind' => $kind,
                'event_key' => $key,
                'signature_valid' => $signatureValid,
                'payload' => $payload,
                'received_at' => now(),
            ])->save();

            return $event;
        } catch (UniqueConstraintViolationException) {
            // The same callback arrived twice at once; the other request records it.
            $existing = WebhookEvent::query()->where('provider', $provider)->where('event_key', $key)->firstOrFail();
            $existing->increment('times_received');

            return $existing->refresh();
        }
    }

    private function finishEvent(WebhookEvent $event, string $outcome, ?string $error = null): void
    {
        $event->forceFill(['outcome' => $outcome, 'error' => $error, 'processed_at' => now()])->save();
    }

    private function scheduleNextCheck(PaymentAttempt $attempt): PaymentAttempt
    {
        $attempt->forceFill([
            'last_checked_at' => now(),
            'check_count' => $attempt->check_count + 1,
            'next_check_at' => now()->addMinutes($this->delay($attempt->check_count + 1)),
        ])->save();

        return $attempt;
    }

    private function delay(int $checks): int
    {
        $steps = (array) config('payments.recheck_minutes', [5]);

        return (int) ($steps[min($checks, count($steps) - 1)] ?? 5);
    }

    private function notifyFinance(PaymentAttempt $attempt): void
    {
        $staff = User::permission(Permission::PaymentsConfirm->value)->where('status', 'active')->get();
        Notification::send($staff, new PaymentNeedsReview($attempt));
    }
}
